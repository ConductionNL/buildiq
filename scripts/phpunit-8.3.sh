#!/usr/bin/env bash
# Run the openbuild unit suite on PHP 8.3 (the app's required runtime) without
# needing PHP 8.3 on the host — uses a throwaway php:8.3-cli docker container.
# Usage:  bash scripts/phpunit-8.3.sh
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
docker run --rm -v "$REPO":/app -w /app php:8.3-cli sh -c '
  apt-get update -qq >/dev/null 2>&1
  apt-get install -y -qq git unzip libzip-dev curl >/dev/null 2>&1
  docker-php-ext-install zip >/dev/null 2>&1
  git config --global --add safe.directory /app
  command -v composer >/dev/null 2>&1 || (curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet)
  composer install --no-interaction --no-progress --prefer-dist --ignore-platform-reqs >/dev/null 2>&1
  ./vendor/bin/phpunit --configuration phpunit-unit.xml --no-coverage --colors=never
'
