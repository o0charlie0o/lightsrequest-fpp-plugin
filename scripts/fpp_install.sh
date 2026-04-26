#!/bin/bash

# Mark to reboot
. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy allowed domains).
# The script doesn't exist on every FPP version (~5.x and earlier);
# don't fail the install if it's missing.
if [ -x "${FPPDIR}/scripts/ManageApacheContentPolicy.sh" ]; then
  ${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://api.lightsrequest.com
fi

# Pre-create the plugin settings file owned by fpp:fpp. Without this,
# the listener (started by postStart.sh as root) creates it owned by
# root on first boot, which then prevents Apache (running as fpp) from
# saving values via the plugin's settings UI.
SETTINGS_FILE=/home/fpp/media/config/plugin.lightsrequest-fpp-plugin
if [ ! -f "$SETTINGS_FILE" ]; then
  touch "$SETTINGS_FILE"
fi
chown fpp:fpp "$SETTINGS_FILE" 2>/dev/null || true
chmod 664 "$SETTINGS_FILE" 2>/dev/null || true

setSetting restartFlag 1

#fpp_install
