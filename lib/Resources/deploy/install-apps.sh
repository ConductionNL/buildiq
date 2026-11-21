#!/bin/sh
# Puts {{appName}} and OpenRegister in /apps, the volume Nextcloud reads as custom_apps.
set -eu

: "${OPENREGISTER_VERSION:?Set OPENREGISTER_VERSION in deploy/.env}"

rm -rf "/apps/{{appId}}"
cp -a "/app-src/{{appId}}" "/apps/{{appId}}"

if [ ! -f /apps/openregister/appinfo/info.xml ]; then
	url="https://github.com/ConductionNL/openregister/releases/download/v${OPENREGISTER_VERSION}/openregister-${OPENREGISTER_VERSION}.tar.gz"
	echo "==> installing OpenRegister ${OPENREGISTER_VERSION}"
	rm -rf /tmp/stage && mkdir -p /tmp/stage
	if ! curl -fsSL "$url" | tar -xz -C /tmp/stage; then
		echo "!! could not fetch OpenRegister ${OPENREGISTER_VERSION} from $url" >&2
		exit 1
	fi
	mv "/tmp/stage/$(ls /tmp/stage | head -n1)" /apps/openregister
	rm -rf /tmp/stage
fi

# 33 is www-data inside the Nextcloud image.
chown -R 33:33 /apps
