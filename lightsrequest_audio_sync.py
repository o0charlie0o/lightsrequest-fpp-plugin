#!/usr/bin/env python3
"""LightsRequest synced show audio — FPP position reporter.

Passively observes the MultiSync packets fppd already sends (UDP 32320) and
reports the current audio position to LightsRequest (POST /plugin/position) so
phones on lightsrequest.com can play the show audio in sync with the lights.

SAFETY — this runs on the show controller, so it is deliberately inert:
  * NEVER opens a UDP socket on 32320. fppd's MultiSync receive socket uses
    SO_REUSEPORT on that port; a second socket there would be handed a share of
    fppd's unicast packets and could break MultiSync to remotes. Instead we read
    copies of fppd's OUTGOING packets off the interface (AF_PACKET, like tcpdump).
    Nothing is sent on the show network and no FPP setting is touched.
  * A kernel BPF filter passes only `udp dst port 32320`, so the E1.31 lighting
    traffic (10k+ packets/s) never reaches this process.
  * No disk writes. Memory is bounded (5 s sample windows, latest-only send slot).
  * Network: separate sender thread, 2 s timeouts, latest-state-only (no queue),
    exponential backoff when offline. A dead internet connection costs nothing.
  * Intended to run as a capped systemd unit (CPUQuota, MemoryMax, Nice=19,
    IOSchedulingClass=idle, only CAP_NET_RAW as the fpp user) — see README.

Usage: lightsrequest_audio_sync.py [--iface eth0] [--config /home/fpp/media/config/plugin.lightsrequest-fpp-plugin]
"""
import argparse, json, os, re, socket, struct, sys, threading, time, urllib.parse, urllib.request

ETH_P_ALL = 0x0003
SO_ATTACH_FILTER = 26
SO_TIMESTAMPNS = 35
WINDOW_S = 5.0
STEADY_AFTER_S = 5.0
SEND_PLAYING_S = 0.5
SEND_IDLE_S = 10.0
# tcpdump -dd "udp dst port 32320"  (generated on FPP 10.1, Pi 4)
BPF = [
    (0x28, 0, 0, 0x0000000c), (0x15, 0, 4, 0x000086dd), (0x30, 0, 0, 0x00000014),
    (0x15, 0, 11, 0x00000011), (0x28, 0, 0, 0x00000038), (0x15, 8, 9, 0x00007e40),
    (0x15, 0, 8, 0x00000800), (0x30, 0, 0, 0x00000017), (0x15, 0, 6, 0x00000011),
    (0x28, 0, 0, 0x00000014), (0x45, 4, 0, 0x00001fff), (0xb1, 0, 0, 0x0000000e),
    (0x48, 0, 0, 0x00000010), (0x15, 0, 1, 0x00007e40), (0x6, 0, 0, 0x00040000),
    (0x6, 0, 0, 0x00000000),
]


def log(msg):
    print(time.strftime("%H:%M:%S"), msg, flush=True)


def read_config(path):
    cfg = {}
    try:
        for line in open(path):
            m = re.match(r'\s*(\w+)\s*=\s*"?(.*?)"?\s*$', line)
            if m:
                cfg[m.group(1)] = m.group(2)
    except OSError:
        pass
    return cfg


def fit(samples):
    """Robust envelope at rate 1.0: each packet implies z = t - pos; network
    delay only makes z larger, so take the 20th percentile (one bad reading
    can't move it). Returns (t_ms, pos_s) at the newest sample."""
    t_last = samples[-1][0]
    zs = sorted(t - p * 1000.0 for t, p in samples)
    z = zs[int(0.2 * (len(zs) - 1))]
    return t_last, (t_last - z) / 1000.0


class Tracker:
    def __init__(self):
        self.lock = threading.Lock()
        self.seq = None
        self.media = None
        self.playing = False
        self.pending_media = None  # (name, t_ms) from media OPEN/START
        self.mstart_t = None
        self.seq_samples = []      # (t_ms, pos_s)
        self.media_samples = []
        self.leads = []
        self.lead = 0.045
        self.delay_obs = None      # {"media", "delay_s"} pending send
        self.changed = threading.Event()
        self.song_changed = False

    def on_packet(self, t_ms, sp, ft, secs, name):
        with self.lock:
            if ft == 1:  # media
                if sp in (0, 3):
                    self.pending_media = (name, t_ms)
                    if self.media != name:
                        self.media_samples = []
                    if sp == 0:
                        self.mstart_t = t_ms
                elif sp == 1:
                    self.media_samples = []
                elif sp == 2:
                    if self.media is None and self.playing:
                        self.media = name  # joined mid-song
                    if name == self.media:
                        ms = self.media_samples
                        if ms and secs + 0.5 < ms[-1][1]:
                            ms.clear()
                        ms.append((t_ms, secs))
                        if self.mstart_t and len(ms) == 4:
                            zs = sorted(t - p * 1000.0 for t, p in ms)
                            d = (zs[1] - self.mstart_t) / 1000.0
                            if -0.05 < d < 3.0:
                                self.delay_obs = {"media": name, "delay_s": round(d, 4)}
                        while ms and ms[0][0] < t_ms - WINDOW_S * 1000:
                            ms.pop(0)
                        ss = self.seq_samples
                        if secs > STEADY_AFTER_S and len(ss) >= 4 and len(ms) >= 4:
                            ta, pa = fit(ss)
                            tm, pm = fit(ms)
                            lead = (pm + (t_ms - tm) / 1000) - (pa + (t_ms - ta) / 1000)
                            if 0.0 < lead < 0.2:
                                self.leads = (self.leads + [lead])[-120:]
                                self.lead = sorted(self.leads)[len(self.leads) // 2]
                return
            # sequence
            if sp in (0, 3):
                if self.seq != name:
                    self.seq_samples = []
                    self.song_changed = True
                self.seq = name
                self.playing = True
                pm = self.pending_media
                if pm and t_ms - pm[1] < 200:
                    self.media = pm[0]
                else:  # sequence-only item (e.g. an ad): silence
                    self.media = None
                    self.mstart_t = None
                self.changed.set()
            elif sp == 1:
                self.playing = False
                self.seq_samples = []
                self.media_samples = []
                self.pending_media = None
                self.mstart_t = None
                self.changed.set()
            elif sp == 2:
                if self.seq != name:
                    self.seq = name
                    self.seq_samples = []
                    self.song_changed = True
                if not self.playing:
                    self.changed.set()  # wake the sender out of its idle wait (joined mid-song)
                self.playing = True
                ss = self.seq_samples
                if ss and secs + 0.5 < ss[-1][1]:
                    ss.clear()
                ss.append((t_ms, secs))
                while ss and ss[0][0] < t_ms - WINDOW_S * 1000:
                    ss.pop(0)

    def snapshot(self):
        with self.lock:
            r = {"seq": self.seq, "media": self.media, "playing": self.playing,
                 "mstart_t": self.mstart_t, "lead_s": round(self.lead, 4), "src": None,
                 "anchor_t": None, "anchor_pos": None}
            if self.playing and len(self.media_samples) >= 2:
                t, p = fit(self.media_samples)
                r.update(anchor_t=round(t, 1), anchor_pos=round(p, 4), src="media")
            elif self.playing and self.seq_samples:
                t, p = fit(self.seq_samples)
                r.update(anchor_t=round(t, 1), anchor_pos=round(p, 4), src="seq")
            if self.delay_obs:
                r["delay_obs"] = self.delay_obs
            song_changed, self.song_changed = self.song_changed, False
            return r, song_changed

    def ack_delay(self, obs):
        with self.lock:
            if self.delay_obs is obs:
                self.delay_obs = None


def playlist_entries(name):
    d = json.load(urllib.request.urlopen(
        "http://127.0.0.1/api/playlist/" + urllib.parse.quote(name), timeout=2))
    return [{"seq": e.get("sequenceName"), "media": e.get("mediaName") or None,
             "duration": e.get("duration")}
            for e in d.get("mainPlaylist", []) if e.get("type") in ("both", "sequence", "media")]


def fpp_playlist():
    """Two tiny local GETs per song change: running playlist + FPP's index."""
    try:
        st = json.load(urllib.request.urlopen("http://127.0.0.1/api/fppd/status", timeout=2))
        cp = st.get("current_playlist") or {}
        name = cp.get("playlist")
        idx = int(cp.get("index") or 0)
        if not name:
            return None
        return {"name": name, "index": idx, "entries": playlist_entries(name)}
    except Exception:
        return None


def sender(tr, base, token, request_playlist):
    url = base.rstrip("/") + "/plugin/position"
    backoff = 1.0
    last_send = 0.0
    playlist = None
    request_playing = False
    req_entries_at = 0.0
    while True:
        snap_peek = tr.playing
        tr.changed.wait(timeout=SEND_PLAYING_S if snap_peek else SEND_IDLE_S)
        tr.changed.clear()
        now = time.time()
        if now - last_send < 0.2:
            continue
        r, song_changed = tr.snapshot()
        if song_changed or playlist is None:
            pl = fpp_playlist()
            if pl and pl["name"] == request_playlist:
                # A viewer request (the plugin inserts its own playlist). Keep the
                # main playlist + index: that's where FPP resumes afterwards.
                request_playing = True
            elif pl:
                request_playing = False
                playlist = pl
                r["playlist"] = pl
        r["request_playing"] = request_playing
        if request_playlist and now - req_entries_at > 600:
            try:  # seq->media map of the request playlist, so queued requests can preload
                r["request_entries"] = playlist_entries(request_playlist)
                req_entries_at = now
            except Exception:
                req_entries_at = now - 540  # retry in a minute
        try:
            req = urllib.request.Request(url, data=json.dumps(r).encode(), method="POST",
                                         headers={"Content-Type": "application/json",
                                                  "Authorization": "Bearer " + token,
                                                  # Cloudflare's bot protection 403s Python-urllib's default UA
                                                  "User-Agent": "LightsRequest-FPP-AudioSync/1.0"})
            urllib.request.urlopen(req, timeout=2).read()
            last_send = time.time()
            if "delay_obs" in r:
                tr.ack_delay(r["delay_obs"])
            if backoff > 1.0:
                log("reconnected")
            backoff = 1.0
        except Exception as e:
            if backoff == 1.0:
                log(f"post failed ({type(e).__name__}); backing off")
            if song_changed:
                with tr.lock:
                    tr.song_changed = True  # resend playlist next time
            time.sleep(backoff)
            backoff = min(backoff * 2, 30.0)


def capture(tr, iface):
    s = socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.htons(ETH_P_ALL))
    import ctypes
    prog = ctypes.create_string_buffer(b"".join(struct.pack("HBBI", *ins) for ins in BPF))
    fprog = struct.pack("HL", len(BPF), ctypes.addressof(prog))  # struct sock_fprog
    s.setsockopt(socket.SOL_SOCKET, SO_ATTACH_FILTER, fprog)
    s.setsockopt(socket.SOL_SOCKET, SO_TIMESTAMPNS, 1)
    s.bind((iface, 0))
    log(f"capturing MultiSync on {iface} (passive, BPF udp dst port 32320)")
    while True:
        data, anc, _, _ = s.recvmsg(2048, socket.CMSG_SPACE(16))
        t_ms = time.time() * 1000.0
        for lvl, typ, cd in anc:
            if lvl == socket.SOL_SOCKET and typ == SO_TIMESTAMPNS and len(cd) >= 16:
                sec, nsec = struct.unpack("qq", cd[:16])
                t_ms = sec * 1000.0 + nsec / 1e6  # kernel timestamp: when fppd sent it
        if len(data) < 14 + 20 + 8 or data[12:14] != b"\x08\x00":
            continue
        ihl = (data[14] & 0x0F) * 4
        p = data[14 + ihl + 8:]
        if p[:4] != b"FPPD" or len(p) < 17 or p[4] != 1:
            continue
        sp, ft, _frame, secs = struct.unpack("<BBIf", p[7:17])
        name = p[17:].split(b"\0", 1)[0].decode(errors="replace")
        tr.on_packet(t_ms, sp, ft, secs, name)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--iface", default="eth0")
    ap.add_argument("--config", default="/home/fpp/media/config/plugin.lightsrequest-fpp-plugin")
    ap.add_argument("--dry-run", action="store_true", help="print snapshots instead of posting")
    a = ap.parse_args()
    cfg = read_config(a.config)
    base = cfg.get("pluginsApiPath") or "https://api.lightsrequest.com"
    token = cfg.get("remoteToken") or ""
    if not token:
        log("no remoteToken in plugin config; exiting")
        sys.exit(1)
    try:
        os.nice(19)
    except OSError:
        pass
    tr = Tracker()
    if a.dry_run:
        def printer():
            while True:
                time.sleep(1)
                r, chg = tr.snapshot()
                log(json.dumps(r) + (" SONG-CHANGED" if chg else ""))
        threading.Thread(target=printer, daemon=True).start()
    else:
        threading.Thread(target=sender, args=(tr, base, token, cfg.get("remotePlaylist") or "Requests"),
                         daemon=True).start()
    capture(tr, a.iface)


if __name__ == "__main__":
    main()
