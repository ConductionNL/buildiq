# Builds {{appName}} from its exported source into an installer image.
# The installer copies the built app into the shared apps volume and fetches
# OpenRegister; compose.yaml then starts a stock Nextcloud on that volume.

FROM composer:2 AS php
WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM node:22-alpine AS frontend
WORKDIR /app
COPY --from=php /app .
RUN npm ci && npm run build && rm -rf node_modules

FROM alpine:3.20
RUN apk add --no-cache curl tar
COPY --from=frontend /app /app-src/{{appId}}
COPY deploy/install-apps.sh /install-apps.sh
RUN rm -rf /app-src/{{appId}}/deploy && chmod 0755 /install-apps.sh
CMD ["/bin/sh", "/install-apps.sh"]
