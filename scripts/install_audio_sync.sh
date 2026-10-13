#!/bin/bash
# Idempotently install/refresh + start the synced-audio reporter service.
# Called from fpp_install.sh (fresh install) and postStart.sh (every fppd
# start, so plugin *updates* pick up unit changes without a reinstall).
PLUGIN_DIR=/home/fpp/media/plugins/lightsrequest-fpp-plugin
UNIT=lightsrequest-audio-sync.service
SUDO=""
[ "$(id -u)" -ne 0 ] && SUDO="sudo -n"

if ! cmp -s "$PLUGIN_DIR/$UNIT" "/etc/systemd/system/$UNIT"; then
  $SUDO cp "$PLUGIN_DIR/$UNIT" "/etc/systemd/system/$UNIT" || exit 0
  $SUDO systemctl daemon-reload
  $SUDO systemctl enable "$UNIT" >/dev/null 2>&1
  $SUDO systemctl restart "$UNIT"
elif ! systemctl is-active --quiet "$UNIT"; then
  $SUDO systemctl enable "$UNIT" >/dev/null 2>&1
  $SUDO systemctl start "$UNIT"
fi
exit 0
