# Run {{appName}} on its own server

This folder runs {{appName}} on a Nextcloud of its own, with OpenRegister as its
data layer. Nothing else is needed: the app does not depend on the tool it was
made with.

## What the server needs

| Part | Version | Why |
|-|-|-|
| Docker with Compose | 2 or newer | runs Nextcloud, the database and the installer |
| Nextcloud | {{nextcloudVersion}} or newer | the app runs as a Nextcloud app |
| OpenRegister | {{openregisterVersion}} or newer | holds the app's registers, schemas and records |
| PostgreSQL | 16 | Nextcloud's database |

The app was built on OpenRegister {{openregisterVersion}}. Building the image needs
network access to Packagist, npm and GitHub.

## Start it

1. Copy `.env.example` to `.env` and set `NEXTCLOUD_ADMIN_USER`,
   `NEXTCLOUD_ADMIN_PASSWORD` and `POSTGRES_PASSWORD`. Compose stops and names
   the variable when one is empty; there are no default passwords.
2. Run `docker compose up -d` in this folder.
3. Open `http://localhost:8080` (or the `PORT` you set), log in, and open
   {{appName}}.

## Later

- A new version: export it again, replace the app folder, and run
  `docker compose up -d --build`.
- Backups, TLS and scaling: see the Nextcloud administration manual,
  https://docs.nextcloud.com/server/latest/admin_manual/.
