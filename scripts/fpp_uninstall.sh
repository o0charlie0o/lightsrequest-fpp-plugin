#!/bin/bash

rm -f /home/fpp/media/plugins/lightsrequest-fpp-plugin/remote_falcon_listener.php

# Remove the synced-audio reporter service.
SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo -n"
$SUDO systemctl disable --now lightsrequest-audio-sync.service >/dev/null 2>&1
$SUDO rm -f /etc/systemd/system/lightsrequest-audio-sync.service
$SUDO systemctl daemon-reload

#fpp_uninstall