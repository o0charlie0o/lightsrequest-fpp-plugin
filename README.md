# Lights Request — FPP Plugin

> **Fork notice.** This is a personal fork of
> [`Remote-Falcon/remote-falcon-plugin`](https://github.com/Remote-Falcon/remote-falcon-plugin)
> retained under the upstream **GPL-3.0** license. All upstream copyright
> headers are preserved. The original SaaS plugin is excellent — go use it
> if you want a managed Remote Falcon experience.

## Purpose

This fork targets a **self-hosted** Lights Request backend running at
`https://api.lightsrequest.com` instead of `remotefalcon.com`. It was
built specifically for Charlie's residential light show
(`lightsrequest.com`) and is paired with a private monorepo that
implements the `/plugin/*` HTTP contract the listener calls.

The PHP listener, polling loop, FPP playlist invocation
(`fpp -P <name>`), and heartbeat behavior all match upstream. What
changed:

- HTTP call sites point at `api.lightsrequest.com/plugin/*` (bearer-token
  auth, JSON booleans, no `Y`/`N` strings, no `-1` sentinels).
- Apache CSP `connect-src` allowlists `api.lightsrequest.com`.
- UI labels in the FPP plugin settings page renamed to "API Base URL"
  and "Plugin Token".
- `pluginInfo.json` repo URLs point at this fork.

## Installation on FPP

Tested on FPP 7.x and 8.x. Steps mirror §9.2 of the upstream rewrite
plan (private — see "Sync checklist" below):

1. **SSH to your FPP Pi:**
   ```sh
   ssh fpp@<pi-ip>
   cd /home/fpp/media/plugins
   ```

2. **Uninstall the upstream Remote Falcon plugin if present:**
   ```sh
   rm -rf remote-falcon
   ```

3. **Install via the FPP web UI:**
   - Open the FPP web UI → **Content Setup** → **Plugin Manager**.
   - Click **Install Plugin** → paste the Git URL:
     ```
     https://github.com/o0charlie0o/lightsrequest-fpp-plugin.git
     ```
   - The install script (`scripts/fpp_install.sh`) handles the Apache
     CSP edit and sets `restartFlag=1`.

4. **Restart FPPD** (the Plugin Manager will prompt you).

5. **Configure the plugin:**
   - In the FPP web UI sidebar, open **Lights Request** (the renamed
     plugin entry).
   - Paste your **Plugin Token** (see §Settings below).
   - Set **API Base URL** to `https://api.lightsrequest.com`.
   - Save.

6. **Smoke-test:**
   - Click **Test Connectivity** — hits `GET /plugin/health`, expects
     `{ ok: true, plugin_token_valid: true }`.
   - Click **Sync Sequences** — POSTs the current FPP playlist list to
     `POST /plugin/sync-playlists`.

If both steps succeed, FPP will start polling `/plugin/next` on the
plugin's normal cadence.

## Settings

| Field | What it is | Where it comes from |
|---|---|---|
| **Plugin Token** | 32-byte hex bearer token sent on every `/plugin/*` request as `Authorization: Bearer <token>`. | Generated server-side via `openssl rand -hex 32`, stored in the API server's `FALCON_PLUGIN_TOKEN` env var. Rotate by changing the env var, redeploying, and pasting the new value here. |
| **API Base URL** | Origin of the self-hosted backend. | `https://api.lightsrequest.com` for Charlie's deployment. Change only if you re-host the backend on a different domain. |

There is no account, no signup, no per-show secret — single tenant by
design.

## Sync checklist (when the API contract changes)

The API server lives in a **private** monorepo; this plugin fork is
**public** because FPP's Plugin Manager clones from a public Git URL.
The two are intentionally not CI-synced — plugin edits are rare after
the initial cutover (estimated <5 edits/year).

When the `/plugin/*` HTTP contract changes (new endpoint, request/
response shape change, host rename, path-prefix rename), **both**
repos need a coordinated commit + push. The full procedure lives in
the private monorepo at `docs/remote-falcon/06-rewrite-plan.md` §9.3 —
table of triggers + the FPP "Update plugin" step that picks up the
new code.

If the plugin and API drift, `/plugin/health` will still pass but the
affected endpoint will return 4xx/5xx and the plugin will log it to
FPP's plugin log. Acceptable failure mode for a single-tenant show.

## License

GPL-3.0, retained from upstream. See `LICENSE`.

## Bug reports

This is a **personal fork** maintained for one residential light show.
For issues with this fork, please open an issue at
<https://github.com/o0charlie0o/lightsrequest-fpp-plugin/issues>.

For issues with the upstream SaaS plugin, file at
<https://github.com/Remote-Falcon/remote-falcon-plugin/issues>.
