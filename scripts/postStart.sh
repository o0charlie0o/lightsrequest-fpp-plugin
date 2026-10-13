#!/bin/bash

/usr/bin/php /home/fpp/media/plugins/lightsrequest-fpp-plugin/remote_falcon_listener.php &

# Synced show audio reporter: (re)install + start if needed. Backgrounded so it
# can never delay fppd startup.
/home/fpp/media/plugins/lightsrequest-fpp-plugin/scripts/install_audio_sync.sh >/dev/null 2>&1 &

#postStart