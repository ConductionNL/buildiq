# platform-properties Specification (delta)

## Purpose

What buildiq and the apps it builds are, independent of any page: where they run, how people sign in, what protects them, how they adapt to devices and assistive technology, which language model they use and how the licence works. Most of these properties are provided by Nextcloud server or the shared nextcloud-vue renderer. Each requirement says who provides the property and what buildiq SHALL do, or SHALL NOT do, to keep it. Covers rows pg-responsive, ai-self-hosted-model, acc-sso, acc-csrf-xss, ux-wcag, ux-keyboard, ops-self-host, ops-one-platform, ops-license-cost, ops-scale-out, acc-directory-sync and acc-two-factor.

## ADDED Requirements

### Requirement: Built pages adapt to phone and tablet screens (REQ-PLAT-001)

Every page of a built app SHALL render through the shared nextcloud-vue renderer (`CnAppRoot`, `CnPageRenderer` and the `Cn*` page components), which adapts its layout to the viewport width. buildiq SHALL NOT ask the maker for a separate phone or tablet design, and the page designer SHALL NOT write fixed pixel widths for page containers into the manifest.

#### Scenario: An index page on a phone
- GIVEN a built app with an index page of cases
- WHEN a user opens the page in a 390 pixel wide viewport
- THEN the page shows without horizontal scrolling of the page itself, and the navigation collapses into the Nextcloud app menu

#### Scenario: The maker designs one page
- GIVEN a maker designs a detail page in the page designer
- WHEN the maker publishes the version
- THEN the manifest holds one layout for the page, and no device-specific variant is asked for or stored

### Requirement: The language model is the one the Nextcloud instance provides (REQ-PLAT-002)

Every AI feature in buildiq (copilot, generate with AI, agents) SHALL reach a language model only through Nextcloud Task Processing (`OCP\TaskProcessing\IManager`, task type `TextToText` or a more specific registered type). buildiq SHALL NOT ship a client for a model vendor, and SHALL NOT store a model API key. Whether the model is self-hosted (for example through `llm2` or LocalAI) or a cloud service is the administrator's choice in Nextcloud.

#### Scenario: A self-hosted provider is the only one installed
- GIVEN a Nextcloud instance with a local Task Processing provider for `TextToText` and no internet access
- WHEN a maker asks the copilot to add a field to a schema
- THEN the request runs as a Task Processing task on the local provider and the copilot shows its proposal

#### Scenario: No provider is installed
- GIVEN no Task Processing provider for `TextToText` is registered
- WHEN a maker opens the copilot
- THEN the copilot says that no language model is set up in Nextcloud, and no request leaves the server

### Requirement: Built apps sit behind the Nextcloud login (REQ-PLAT-003)

Every builder and built-app route SHALL require a Nextcloud session, so the sign-in the instance uses (local accounts, `user_oidc`, `user_saml`, LDAP) applies unchanged. A controller method SHALL carry `#[PublicPage]` only when it is on an allow-list in the route protection test together with the check that replaces the login, such as the random token of a generated document download link.

#### Scenario: Single sign-on
- GIVEN a Nextcloud instance that signs users in through SAML
- WHEN a user opens `/apps/buildiq/builder/vergunningen/` without a session
- THEN Nextcloud sends the user through the SAML login and then shows the built app

#### Scenario: A new public method without an allow-list entry
- GIVEN a developer adds `#[PublicPage]` to a buildiq controller method
- WHEN the unit tests run
- THEN the route protection test fails and names the method

### Requirement: Forged requests and script injection are blocked by default (REQ-PLAT-004)

buildiq SHALL keep the Nextcloud CSRF check on every state-changing route: `#[NoCSRFRequired]` SHALL appear only on methods routed with verb GET. Vue templates SHALL render user and manifest content through text interpolation; a `v-html` binding SHALL only render output of `DOMPurify.sanitize`.

#### Scenario: A forged save
- GIVEN a signed-in maker visits a third-party page that posts to `/apps/buildiq/api/applications/{slug}/manifest`
- WHEN the request arrives without the Nextcloud request token
- THEN Nextcloud rejects it and the manifest is unchanged

#### Scenario: A label with markup
- GIVEN a maker sets a page title to `<img src=x onerror=alert(1)>`
- WHEN a user opens the page
- THEN the title shows the text literally and no script runs

### Requirement: Built apps meet WCAG 2.1 AA without extra work (REQ-PLAT-005)

Built apps SHALL render through the nextcloud-vue components, which carry the fleet's accessibility baseline (labelled controls, focus order, contrast through Nextcloud theme variables). buildiq SHALL warn the maker in the page designer when an action, widget or form field has an empty label, because the renderer cannot give an unlabelled control an accessible name.

#### Scenario: An action without a label
- GIVEN a maker adds an action button to an index page and leaves its label empty
- WHEN the page designer validates the draft
- THEN a warning names the action and says it needs a label for screen reader users

#### Scenario: A labelled form
- GIVEN every field of a form page has a label
- WHEN a screen reader user tabs through the form in the built app
- THEN each field announces its label

### Requirement: Built apps work by keyboard alone (REQ-PLAT-006)

Built apps SHALL use the keyboard-operable controls of nextcloud-vue and Nextcloud Vue (`NcButton`, `NcSelect`, `NcActions` and their peers) for every interaction, and buildiq SHALL NOT add a manifest option that makes an interaction pointer-only. Drag interactions in the builder, such as moving widgets, SHALL have a keyboard alternative.

#### Scenario: Working through a case by keyboard
- GIVEN a built app with an index page and a detail page
- WHEN a user opens a case, changes its status and saves using only Tab, Enter, Space and the arrow keys
- THEN every step succeeds and the focus stays visible

### Requirement: The builder runs on the customer's own Nextcloud (REQ-PLAT-007)

buildiq SHALL install and run as an ordinary Nextcloud app on a server the customer operates, with OpenRegister as its only required app dependency. It SHALL NOT need a service operated by Conduction or another vendor to start, store apps or serve built apps. Optional integrations (GitHub export, the remote template store, AI providers) SHALL be configured by the customer and SHALL fail with a message when not configured.

#### Scenario: An installation without access to vendor services
- GIVEN a Nextcloud server with OpenRegister and no route to any Conduction service
- WHEN an administrator enables buildiq and a maker creates an app from a blank template
- THEN the app is created, published and opened without errors

### Requirement: Built apps run inside the same Nextcloud (REQ-PLAT-008)

A built app SHALL be stored as records in buildiq's OpenRegister register and rendered inside the same Nextcloud instance at `/apps/buildiq/builder/{slug}/`, through a nested `CnAppRoot`. It SHALL share the instance's users, files, calendar, talk and other apps, and SHALL NOT need its own server, database or deployment.

#### Scenario: A built app uses files
- GIVEN a built app with a detail page that has the files sidebar tab
- WHEN a user attaches a document to a record
- THEN the document is stored in Nextcloud Files of the same instance

### Requirement: No per-user licence fees (REQ-PLAT-009)

buildiq SHALL be licensed under the EUPL-1.2, declared in `LICENSE` and in `appinfo/info.xml`. It SHALL NOT count users, limit their number, or check a licence key, subscription or seat allowance before a maker builds or a user works in a built app.

#### Scenario: The licence is declared consistently
- GIVEN the repository at any release tag
- WHEN `LICENSE` and `appinfo/info.xml` are read
- THEN both name the EUPL-1.2

#### Scenario: More users
- GIVEN 200 users work in built apps
- WHEN an administrator adds a 201st user to a group with access
- THEN that user can open the apps, and no screen or log mentions a seat limit

### Requirement: The platform scales out over several servers (REQ-PLAT-010)

buildiq SHALL keep no state on a single web server that another request needs: app definitions and records live in OpenRegister, files in Nextcloud storage or `IAppData`, caches in the Nextcloud distributed cache, and long work in Nextcloud background jobs. Running Nextcloud behind a load balancer with several web servers and cron workers SHALL work without buildiq-specific configuration.

#### Scenario: An export downloaded through another web server
- GIVEN Nextcloud runs on two web servers behind a load balancer
- WHEN a maker starts an export that a background job builds on server A, and downloads it through server B
- THEN the download serves the finished ZIP

### Requirement: Users and groups come from the organisation's directory (REQ-PLAT-011)

buildiq SHALL take users and groups only from Nextcloud's user and group managers (`IUserManager`, `IGroupManager`), so users and departments synchronised from LDAP or Active Directory by `user_ldap` appear in every buildiq group picker and access rule without buildiq-specific setup. buildiq SHALL NOT keep its own user or group list.

#### Scenario: A department from Active Directory
- GIVEN `user_ldap` synchronises the group "Vergunningen" from Active Directory
- WHEN a maker grants a built app's page to a group
- THEN "Vergunningen" is offered, and its members get access as soon as they sign in

### Requirement: Two-factor authentication applies to built apps (REQ-PLAT-012)

Because every built-app route requires a Nextcloud session (REQ-PLAT-003), a second factor that Nextcloud enforces for a user or group SHALL be challenged before any built app opens. buildiq SHALL NOT offer an app password, token or link that opens a built app around that challenge, other than the allow-listed public methods of REQ-PLAT-003.

#### Scenario: Enforced two-factor
- GIVEN an administrator enforces two-factor authentication for the group "Medewerkers"
- WHEN a member signs in and opens a built app
- THEN Nextcloud asks for the second factor before the app shows
