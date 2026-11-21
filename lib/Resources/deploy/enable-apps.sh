#!/bin/sh
# Runs once after Nextcloud installs itself. OpenRegister goes first: it holds
# the registers and schemas {{appName}} imports when it is enabled.
set -eu
php /var/www/html/occ app:enable openregister
php /var/www/html/occ app:enable {{appId}}
