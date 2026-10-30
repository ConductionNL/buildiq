# Design: data-external-database-sources

Read at buildiq development `d21e42f`, openregister development `ae898b0` and
`@conduction/nextcloud-vue` 2.57.1 (the version buildiq pins).

## Where it sits

- Data plane, openregister (not touched here): a `Source` of type `database`
  (`openregister/src/modals/source/EditSource.vue:229`, drivers at lines
  238-240, the "Allow writes" switch at line 87), tested and introspected by
  `SourcesController::testConnection()` (line 541) and `::introspect()` (line
  602), both refusing a non-administrator with 403 (lines 542-544 and 603-605).
  Introspection makes a `Register` whose `source` names the source
  (`openregister/lib/Db/Register.php:151`) and one schema per table, served by
  `DbalObjectSourceProvider`, which caps a page at `MAX_RESULTS` 1000 (line 75) and
  re-checks `authConfig.writable` on every write
  (`openregister/lib/Service/ObjectSource/DbalObjectSourceProvider.php:1384`).
- Binding, buildiq: `Application.dataRegisters`
  (`lib/Settings/register.d/20-data-registers.json:7-36`, items
  `{register, label}` with `additionalProperties: false`). The settings rows are
  two text fields per binding (`src/modals/AppSettingsModal.vue:70-90`) and emit
  `update:data-registers` (line 345).
- Pickers, buildiq: `useRegisterPicker().fetchRegisters()`
  (`src/composables/useRegisterPicker.js:122`) reads
  `/apps/openregister/api/registers` (line 124) and hoists bound registers
  (lines 152-200). `IndexPageEditor.vue:12-51` and `DetailPageEditor.vue:14-49`
  render the register and schema selects; `IndexPageEditor.vue:74-80` mounts the
  `ActionBuilder`. `DataSourceOriginToggle.vue:13-32` is the OpenRegister or
  OpenConnector switch.
- Runtime, nextcloud-vue 2.57.1: `CnPageRenderer.vue:128` binds a page's
  `config` as props, and `CnIndexPage.vue` takes `showEditAction` (line 1893),
  `showDeleteAction` (line 1937) and `showAdd` (line 2293); `CnDetailPage.vue`
  takes `showEditAction` (line 1478). `CnDataTable` renders a result grid.
- App creation, buildiq: `POST /api/applications/wizard` (`appinfo/routes.php:25`)
  reaches `ApplicationCreationController::wizard()`
  (`lib/Controller/ApplicationCreationController.php:108-110`, admin setting
  gate and 10 per hour rate limit), which calls
  `ApplicationCreationService::createApplication()`
  (`lib/Service/ApplicationCreationService.php:147`). It loads the default
  manifest and schemas (lines 263-264), rewrites each version's manifest with
  `substituteVersionContext()` (lines 273-277), and rolls back on failure
  (`rollback()`, line 761). The wizard's first step already offers "Generate
  with AI" (`src/dialogs/CreateApplicationWizard/Step1Basics.vue:16-25`).
- Schemas, buildiq: `src/views/SchemaDesigner.vue:28-39` shows the list with an
  "Import data" button gated on owner or editor (lines 390-405).

## D1. A connected database is an OpenRegister register, not a third origin

A database register serves objects through the ordinary objects API, so a page
bound to it uses the OpenRegister origin and the existing selects. Adding a
third radio to `DataSourceOriginToggle.vue` would split one data path in two
and duplicate the column and action builders. The editor instead marks the
register in the select ("Connected database") and reads its write setting.

## D2. Database and write setting are read from OpenRegister, never stored

Whether a bound register is a database, and whether that database takes writes,
is resolved each time from OpenRegister: the register's `source`, that source's
`type`, and its `authConfig.writable`. Nothing is copied onto the Application,
so an administrator who turns writes off is followed at once, and a maker
cannot mark a read-only database writable by editing the app. The binding keeps
the existing `{register, label}` shape; the picker only helps fill it. A new
composable `src/composables/useConnectedDatabases.js` reads
`/apps/openregister/api/sources` and `/api/registers` and returns the database
registers with a `writable` flag.

## D3. Pages follow the write setting through props the runtime already has

For a register whose source does not take writes, the index editor sets
`showAdd`, `showEditAction` and `showDeleteAction` to `false` on the page
config, the detail editor sets `showEditAction` to `false`, and both lock those
controls with a note. OpenRegister would reject the write
anyway (its spec, "Writes are rejected on a read-only source"); the point is
that the app never offers a button that can only fail. The runtime needs no
change, because `CnPageRenderer` already passes these keys to `CnIndexPage` and
`CnDetailPage`.

## D4. Generated pages keep the database register and table slugs

Two rewrites would break a generated page today. `substituteVersionContext()`
prefixes every non-empty `config.schema` with the version prefix
(`ApplicationCreationService.php:1022-1028`), whatever the register, and
`ManifestDataBinding::bindRegister()` points any register that does not start
with `openbuild-` at the app's own register
(`lib/Support/ManifestDataBinding.php:195-213`). Both learn one exception: a
register the Application declares in `dataRegisters` is left alone, and so is
the schema beside it. The same fix lets a copilot plan bind to a data register,
which it cannot do today.

## D5. "Start from a database" extends the wizard, not the copilot

The wizard already creates the Application, its versions and their registers
atomically with rollback. The new path adds a `fromDatabase` block to the wizard
payload (`{register, schemas[]}`), which `validatePayload()` checks against the
live register, and replaces `loadDefaultManifest()` with a new pure class
`lib/Service/DatabaseManifestBuilder.php`. For each chosen table it builds an
index page (columns from the first eight scalar properties), a detail page, and
a menu entry, and it writes `dataRegisters` on the Application. Only a caller
who passes the wizard's existing admin setting gate reaches it. The copilot path
was considered and rejected: its plan is model-shaped, it needs review of free
text, and it has no reason to be involved when the pages follow from the tables.

## D6. The query runs in OpenRegister, and only an administrator writes one

A query is code a maker writes, so its limits are fixed here:

- Where it runs: in OpenRegister, on the source's own DBAL connection, server
  side. Buildiq never opens a connection and never holds the password.
- How it is sandboxed: OpenRegister accepts one statement that starts with
  `SELECT` or `WITH`, runs it in a read-only transaction with a statement
  timeout, caps the rows, and binds named values as parameters. Anything else is
  refused before it reaches the database.
- Who may author it: a Nextcloud administrator, the same gate as `introspect`.
  The query editor opens from `SchemaDesigner.vue` only for an administrator
  (`OC.isUserAdmin()`, as `Step1Basics.vue:294-299` already does), and the
  preview and save routes refuse everyone else server side, so hiding the
  button is a convenience and not the control.

The editor is a dialog, `src/dialogs/SqlQueryDialog.vue`: a monospace text area,
a source picker limited to connected databases, "Run preview" (at most 50 rows
in a `CnDataTable`), and "Save as table" with a name. Saving creates the query
table through OpenRegister's schema API in the database register, so pages bind
to it like any table and D3 treats it as read only.

## Risks

- A wide table makes a crowded index page. The builder takes the first eight
  scalar columns and the maker trims them in the column builder afterwards.
- A slow query costs the external database. The statement timeout and row cap
  are OpenRegister's; the preview asks for 50 rows only.
- An administrator can read any table the database user can read. That is the
  database user's grant, which OpenRegister's source screen already warns about
  ("Use a database user with least-privilege grants").

## What it does not do

- It does not create sources, store passwords or open connections.
- It adds no buildiq route: every call goes to OpenRegister or to the existing
  wizard route.
- It does not make a query table writable.
