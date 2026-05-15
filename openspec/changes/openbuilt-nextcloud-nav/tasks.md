## 1. Schema register patch — top-level icon fields on Application

- [ ] 1.1 **Patch `application` schema in `lib/Settings/openbuilt_register.json`**
  - spec_ref: REQ-OBICON-001, REQ-OBA-002 (modified)
  - files: `lib/Settings/openbuilt_register.json`
  - Add two optional **top-level** properties under
    `components.schemas.Application.properties` (sibling to `slug`, `name`, `manifest`,
    `version`, `permissions` — NOT inside `manifest`):
    - `icon`: `{ "type": "object", "required": ["ref"], "properties": { "ref": { "type": "string" } }, "additionalProperties": false }`
    - `iconDark`: same shape
  - Do NOT create a new PHP service class for this; the patch is declarative JSON only.
  - Do NOT touch `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json` — icons
    live on the Application record, not in the manifest blob, so the upstream schema is
    intentionally not patched.
  - acceptance_criteria: `composer check:strict` passes; an Application payload containing
    top-level `"icon": { "ref": "app-icon.svg" }` validates against the patched schema; a
    payload containing top-level `"icon": "string"` fails validation.

- [ ] 1.2 **Re-import schema on upgrade via existing `InitializeSettings` repair step**
  - spec_ref: REQ-OBICON-001
  - files: `lib/Repair/InitializeSettings.php` (verify it calls
    `ConfigurationService::importFromApp('openbuilt')` — no change needed if already correct)
  - acceptance_criteria: Running `occ maintenance:repair` after the patch causes the updated
    top-level `icon` / `iconDark` schema properties to be visible via the OR schema API.

## 2. Icon-serving PHP layer

- [ ] 2.1 **Create `lib/Service/IconService.php`**
  - spec_ref: REQ-OBICON-002, REQ-OBICON-003
  - files: `lib/Service/IconService.php` (NEW)
  - Methods:
    - `getIconStream(string $slug, bool $dark): array{ stream: resource|null, mimeType: string }` — implements the fallback chain documented in design.md Decision 2.
    - Reads attached files from OR via `ObjectService` (or the OR files-attached-to-object endpoint); on OR failure falls back to the filesystem icons at `OC::$SERVERROOT . "/custom_apps/openbuilt/img/app{$suffix}.svg"`.
  - Must carry SPDX + EUPL-1.2 docblock per project standards.
  - acceptance_criteria: PHPUnit: mock ObjectService to return a stream; assert correct bytes returned. Mock ObjectService to throw; assert fallback stream returned. `composer check:strict` passes.

- [ ] 2.2 **Create `lib/Controller/IconController.php`**
  - spec_ref: REQ-OBICON-002, REQ-OBICON-003
  - files: `lib/Controller/IconController.php` (NEW)
  - Methods:
    - `iconLight(string $slug): Response` — calls `IconService::getIconStream($slug, false)`, sets `Content-Type: image/svg+xml`, `Cache-Control: public, max-age=60`, returns a `DataDisplayResponse` or equivalent.
    - `iconDark(string $slug): Response` — same but `$dark = true`.
  - Both methods carry `#[NoAdminRequired]`.
  - acceptance_criteria: PHPUnit: mock IconService; assert 200 + correct headers. `composer check:strict` passes.

- [ ] 2.3 **Register icon routes in `appinfo/routes.php`**
  - spec_ref: REQ-OBICON-002, REQ-OBICON-003
  - files: `appinfo/routes.php`
  - Add before the SPA catch-all, after the exports routes:
    ```php
    ['name' => 'icon#iconLight', 'url' => '/icons/{slug}.svg',      'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
    ['name' => 'icon#iconDark',  'url' => '/icons/{slug}-dark.svg', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9][a-z0-9-]*[a-z0-9]']],
    ```
  - acceptance_criteria: Newman or Playwright network-request capture confirms both URLs resolve to `IconController`; dark route does not shadow the light route.

## 3. Navigation wiring

- [ ] 3.1 **Create `lib/Service/AppNavigationService.php`**
  - spec_ref: REQ-OBNAV-001, REQ-OBNAV-002, REQ-OBNAV-003, REQ-OBNAV-004
  - files: `lib/Service/AppNavigationService.php` (NEW)
  - Responsibilities:
    - `registerNavEntries(INavigationManager $nav): void` — queries OR for all `status == published` Applications; for each, calls `$nav->add()` with a closure factory.
    - Gating closure: resolves the session user's UID + group memberships via `IUserSession` + `IGroupManager`; checks `group:*` sentinel first; then intersects uid/group principal sets; falls through to NC-admin bypass last.
    - Order: `1000 + (abs(crc32($slug)) % 1000)` — deterministic, alpha-spread, after openbuilt's own static entry.
  - acceptance_criteria: PHPUnit: mock ObjectService to return one published + one draft Application; assert only the published entry is registered; assert gating closure returns `true` for matching user and `false` for non-matching user; `composer check:strict` passes.

- [ ] 3.2 **Wire `AppNavigationService` inside `Application::boot()`**
  - spec_ref: REQ-OBNAV-001
  - files: `lib/AppInfo/Application.php`
  - Inside the currently-empty `boot()` method, resolve `AppNavigationService` lazily and call `registerNavEntries()`:
    ```php
    public function boot(IBootContext $context): void
    {
        $container = $context->getAppContainer();
        $container->get(AppNavigationService::class)
                  ->registerNavEntries($container->get(INavigationManager::class));
    }
    ```
  - acceptance_criteria: After install (or OPcache flush), Nextcloud's top bar includes an entry for `hello-world` when signed in as `admin`; entry is absent when signed in as a user with no permissions.

## 4. Frontend — ApplicationCard update

- [ ] 4.1 **Add icon `<img>` before the title in `ApplicationCard.vue`**
  - spec_ref: REQ-OBR-007
  - files: `src/components/ApplicationCard.vue`
  - In the `ob-app-card__head` div, add before `<h3>`:
    ```html
    <img
      class="ob-app-card__icon"
      :src="`/index.php/apps/openbuilt/icons/${app.slug}.svg`"
      :alt="app.name || app.slug"
      width="20"
      height="20"
      @error="onIconError"
    />
    ```
  - Add `onIconError(e)` method: `e.target.src = '/apps/openbuilt/img/app.svg'`.
  - Add `.ob-app-card__icon { width: 20px; height: 20px; object-fit: contain; flex-shrink: 0; }` to `<style scoped>`.
  - acceptance_criteria: Playwright: navigating to the virtual-apps index shows `<img>` elements with the icon endpoint src on each card.

- [ ] 4.2 **Remove the redundant Live chip from `ApplicationCard.vue`**
  - spec_ref: REQ-OBR-007
  - files: `src/components/ApplicationCard.vue`
  - Remove line 30: `<span v-if="app.currentVersion" class="ob-app-card__chip ob-app-card__chip--live">{{ t('openbuilt', 'Live') }}</span>`
  - Remove the `.ob-app-card__chip--live` CSS rule.
  - acceptance_criteria: No element with class `ob-app-card__chip--live` or text "Live" appears on any ApplicationCard; existing badge, version, role, and slug chips are unaffected.

## 5. Frontend — Application detail page icon section

- [ ] 5.1 **Add icon upload/preview section to the Application detail page**
  - spec_ref: REQ-OBICON-004
  - files: `src/views/SchemaDesigner.vue` (or the appropriate detail-page component — verify the tab extension point before modifying)
  - Add an **Icon** tab / section containing:
    - Light icon slot: file input (accept=".svg"), uploads to OR's files-attached-to-object endpoint, patches `top-level `icon.ref``; remove button clears the attachment and the ref.
    - Dark icon slot: same flow for `top-level `iconDark.ref``.
    - Preview area: two 48×48 boxes — white background (light preview) and `#1c1c1e` background (dark preview) — each showing the respective uploaded SVG via the icon-serving endpoint URL.
  - Validate client-side that the selected file has a `.svg` extension before calling OR.
  - acceptance_criteria: Playwright: navigate to detail page → Icon tab → upload an SVG → preview area updates within the same view; remove → preview clears.

## 6. Seed data — Hello World icons

- [ ] 6.1 **Extend `SeedHelloWorld` to attach demo SVG icons to the seeded Application**
  - spec_ref: design.md §Seed Data
  - files: `lib/Repair/SeedHelloWorld.php`
  - After `seedApplicationAndRoute()` returns a non-null UUID, call a new private method
    `seedIcons(applicationUuid: $applicationUuid, output: $output)` that:
    - Attaches `app-icon.svg` (Conduction cobalt `#4376FC` fill) to the Application record
      via OR's files-attached-to-object endpoint (or `ObjectService::addFile` equivalent).
    - Attaches `app-icon-dark.svg` (white `#fff` fill) similarly.
    - Both calls are idempotent — guarded by checking for an existing file with that name
      before attaching.
  - acceptance_criteria: Running `occ maintenance:repair` on a fresh install produces an
    Application record with two attached files (`app-icon.svg`, `app-icon-dark.svg`); the
    icon-serving endpoint returns SVG bytes for `GET /icons/hello-world.svg`.

- [ ] 6.2 **Patch the Hello World manifest seed data with `icon` and `iconDark` refs**
  - spec_ref: design.md §Seed Data, REQ-OBICON-001
  - files: `lib/Repair/SeedHelloWorld.php` (`buildHelloWorldManifest()` method)
  - Add `'icon' => ['ref' => 'app-icon.svg']` and `'iconDark' => ['ref' => 'app-icon-dark.svg']`
    to the returned manifest array.
  - acceptance_criteria: The seeded Application's `top-level `icon.ref`` equals `"app-icon.svg"`
    after `occ maintenance:repair`; schema validation passes.

## 7. Tests

- [ ] 7.1 **PHPUnit: `IconServiceTest`** — unit tests for `IconService::getIconStream`; covers light + dark happy paths, OR-failure fallback, and unknown-slug 404 case.
  - files: `tests/Unit/Service/IconServiceTest.php` (NEW)

- [ ] 7.2 **PHPUnit: `IconControllerTest`** — unit tests for `IconController::iconLight` and `iconDark`; assert `Content-Type: image/svg+xml` and `Cache-Control: public, max-age=60` headers.
  - files: `tests/Unit/Controller/IconControllerTest.php` (NEW)

- [ ] 7.3 **PHPUnit: `AppNavigationServiceTest`** — unit tests for `registerNavEntries`; covers published-only filter, gating-closure user/group/admin/wildcard branches, and draft+archived exclusion.
  - files: `tests/Unit/Service/AppNavigationServiceTest.php` (NEW)

- [ ] 7.4 **Playwright: icon appears in ApplicationCard** — navigate to virtual-apps index, assert each card contains an `<img>` with an icon endpoint src; assert no "Live" chip text.
  - files: `tests/e2e/applicationCard.spec.ts` (new test block or new file)

- [ ] 7.5 **Playwright: icon upload on detail page** — navigate to an Application's detail page icon tab, upload a local SVG, assert the preview src updates; click remove, assert preview reverts.
  - files: `tests/e2e/iconUpload.spec.ts` (NEW)

- [ ] 7.6 **Newman: icon endpoints reachable** — `GET /icons/hello-world.svg` and `GET /icons/hello-world-dark.svg` return 200 with `Content-Type: image/svg+xml`; unauthenticated request returns 401.
  - files: add to existing Newman collection or `tests/newman/openbuilt.postman_collection.json`
