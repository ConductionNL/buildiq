<?php

/**
 * Buildiq SeedHelloWorldFixture Command
 *
 * Occ command that idempotently seeds the canonical hello-world virtual app
 * fixture used by the Playwright e2e suite.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\Buildiq\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Occ buildiq:seed-hello-world-fixture.
 *
 * Idempotently seeds the canonical `hello-world` virtual app used by the
 * Playwright e2e suite: one published Application with a productionVersion
 * carrying a menu+pages manifest, a BuiltAppRoute so the app resolves by
 * slug, and three sample `hello-message` objects rendered by the index page.
 *
 * This is a TEST/DEV fixture seeder, NOT a production repair step. The
 * `SeedHelloWorld` repair step was deliberately retired (commit 0a9553c)
 * during the green-field/versioned-model migration; production installs do
 * not seed hello-world. The e2e harness invokes this command explicitly so
 * the legacy hello-world specs have a deterministic fixture to run against.
 *
 * Re-running is a no-op once the BuiltAppRoute for `hello-world` exists.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */
class SeedHelloWorldFixture extends Command {
	/**
	 * Prefix of the PER-VERSION register the creation wizard provisions.
	 *
	 * TWO DIFFERENT IDENTIFIERS THAT USED TO SHARE A SPELLING, which is why
	 * this is spelled out rather than derived. ApplicationVersionService::
	 * REGISTER_SLUG is the app's MAIN register and moved to `buildiq` with the
	 * rename. This one names a per-version register, convention
	 * `openbuild-{appSlug}-{versionSlug}`, and did NOT move: every producer
	 * still emits it (ApplicationsController, ApplicationCreationService,
	 * AppRepoSerializer, GitHubAppSyncService, UpsertSchemaHandler) and the
	 * applicationVersion schema pins it with
	 * `"pattern": "^openbuild-[a-z0-9][a-z0-9-]*[a-z0-9]$"`.
	 *
	 * The fixture used to build this from REGISTER_SLUG, which silently coupled
	 * the two, so the main rename dragged the per-version name along and the
	 * seed died on that pattern: "Property 'register' should match pattern
	 * '^openbuild-…' but 'buildiq-hello-world' does not."
	 *
	 * @var string
	 */
	private const VERSION_REGISTER_PREFIX = 'openbuild-';

	private const SEED_SLUG = 'hello-world';

	private const VERSION_SLUG = 'production';

	private const SEMVER = '1.0.0';

	/**
	 * Slug/appId of the seeded HYBRID example app (unify-apps-with-app-type).
	 * Points at the OpenCatalogi fleet app; the delta is data-only and renders
	 * over that app's bundled manifest client-side, so it is harmless even when
	 * OpenCatalogi is not installed on this instance.
	 */
	private const HYBRID_SLUG = 'opencatalogi';

	/**
	 * The hello-world fixture's access block, granting the CI/dev admin owner
	 * rights on the seeded app.
	 *
	 * A CONSTANT, and repeated on EVERY write to the Application — this is the
	 * point of it, not an accident.
	 *
	 * OR's `saveObject()` update path is PUT-semantic, not PATCH:
	 * `SaveObject::fillMissingSchemaPropertiesWithNull()` sets every schema
	 * property absent from the payload to null. The fixture writes the
	 * Application once with its permissions and then writes it again purely to
	 * attach `productionVersion` — and that second, "partial" write silently
	 * NULLED the block the first had just set.
	 *
	 * The result was not a missing badge. `permissions` is what the frontend
	 * `useRole()` and the backend `PermissionResolver` both read, and an empty
	 * block denies everyone (`allowAdminBypass` is false), so the seeded app
	 * came out owned by NOBODY. Measured on run 31040914410, that one omission
	 * produced: a `readonly` manifest editor for the admin (REQ-OBR-005 x4,
	 * REQ-OBR-006b, REQ-OBR-008b), no owner-only Settings menu entry, a 403
	 * from the copilot execute endpoint ("You do not have owner or editor
	 * access to application 'hello-world'"), and seven automation scenarios
	 * whose edit modal never closed because the save 403'd — 14 failures, every
	 * one of which reads like a permissions bug in the product.
	 *
	 * Wizard-created apps set the same shape; only the seed, which runs in
	 * system context, has to state it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SEED_PERMISSIONS = [
		'owners' => ['user:admin'],
		'editors' => [],
		'viewers' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param RegisterMapper $registerMapper OpenRegister register mapper.
	 * @param SchemaMapper $schemaMapper OpenRegister schema mapper.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name and description.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'buildiq:seed-hello-world-fixture')
			->setDescription(description: 'Seed the hello-world virtual app fixture for the e2e suite (idempotent; test/dev only).');
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * @param InputInterface $input The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Command exit code.
	 *
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$register = ApplicationVersionService::REGISTER_SLUG;

		try {
			// Guard on the Application object (survives disable/enable), not just
			// the built-app-route (cleared on disable) — otherwise re-seeding
			// accumulates duplicate hello-world apps that make loadApplication()
			// 404 on the ambiguous slug.
			if ($this->applicationExists(register: $register) === true || $this->routeExists(register: $register) === true) {
				$output->writeln('<info>hello-world fixture already present — skipping the virtual app.</info>');
				$this->seedHybridExample(register: $register, output: $output);
				return Command::SUCCESS;
			}

			// 1. Application (no productionVersion yet — set after the version exists).
			$application = $this->create(
				register: $register,
				schema: ApplicationVersionService::APPLICATION_SCHEMA,
				data: [
					'slug' => self::SEED_SLUG,
					'name' => 'Hello World',
					'description' => 'Seeded e2e fixture — your first virtual app built from a JSON manifest.',
					// Grant the admin user owner rights so automation ops
					// (compile/enable/dry-run — WRITE_ROLES ['owners','editors'])
					// are permitted. See SEED_PERMISSIONS.
					'permissions' => self::SEED_PERMISSIONS,
				]
			);
			$applicationUuid = $application->getUuid();

			// 2. Published version carrying the manifest.
			$version = $this->create(
				register: $register,
				schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
				data: [
					'name' => self::SEMVER,
					'slug' => self::VERSION_SLUG,
					'manifest' => $this->buildManifest(),
					// NOT $register — see VERSION_REGISTER_PREFIX.
					'register' => self::VERSION_REGISTER_PREFIX . self::SEED_SLUG,
					'semver' => self::SEMVER,
					'status' => 'published',
					'application' => $applicationUuid,
				]
			);
			$versionUuid = $version->getUuid();

			// 3. Point the Application at its production version.
			//
			// ⚠️ `permissions` IS REPEATED HERE ON PURPOSE — DO NOT TRIM IT.
			// This write's only intent is the production pointer, but OR's
			// saveObject() is PUT-semantic and nulls every property it omits.
			// See the SEED_PERMISSIONS docblock for what that cost.
			$this->create(
				register: $register,
				schema: ApplicationVersionService::APPLICATION_SCHEMA,
				data: [
					'slug' => self::SEED_SLUG,
					'name' => 'Hello World',
					'description' => 'Seeded e2e fixture — your first virtual app built from a JSON manifest.',
					'permissions' => self::SEED_PERMISSIONS,
					'productionVersion' => $versionUuid,
				],
				uuid: $applicationUuid
			);

			// 4. BuiltAppRoute so getManifest()/resolveApplicationBySlug() resolve the slug.
			$this->create(
				register: $register,
				schema: 'built-app-route',
				data: [
					'slug' => self::SEED_SLUG,
					'applicationUuid' => $applicationUuid,
				]
			);

			// 5. Three sample messages rendered by the index page.
			foreach ($this->buildSampleMessages() as $message) {
				$this->create(register: $register, schema: 'hello-message', data: $message);
			}

			$output->writeln('<info>Seeded hello-world fixture (application ' . $applicationUuid . ').</info>');

			// Also seed the hybrid example app (unify-apps-with-app-type).
			$this->seedHybridExample(register: $register, output: $output);
			return Command::SUCCESS;
		} catch (Throwable $e) {
			$output->writeln('<error>Failed to seed hello-world fixture: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}//end try
	}//end execute()

	/**
	 * Idempotently seed one HYBRID example app (unify-apps-with-app-type): a
	 * hybrid Application(appType:hybrid, slug=opencatalogi) + a delta-only
	 * production ApplicationVersion carrying a small manifestDelta. The delta is
	 * served raw by the /api/app-overrides/{appId} shim and merged client-side
	 * over the OpenCatalogi fleet app's bundled manifest, so it is harmless even
	 * when OpenCatalogi is not installed. Re-running is a no-op once the hybrid
	 * Application exists.
	 *
	 * @param string $register The buildiq register slug.
	 * @param OutputInterface $output The command output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	private function seedHybridExample(string $register, OutputInterface $output): void {
		if ($this->hybridExists(register: $register) === true) {
			$output->writeln('<info>hybrid example already present — nothing to do.</info>');
			return;
		}

		$baseRef = ['kind' => 'fleet-app', 'id' => self::HYBRID_SLUG];

		// The Application UUID is minted HERE rather than taken from a first
		// create, so the whole hybrid app is written in exactly ONE create.
		//
		// WHY — read before restoring the create/create/update shape.
		//
		// HybridMetadataLockListener USED TO lock slug, name, description AND
		// productionVersion on a hybrid app, and it fires on ObjectUpdatingEvent
		// only: "a hybrid app is created with its locked identity, which is
		// allowed". The previous shape created the Application, created the
		// version, then UPDATED the Application to attach productionVersion —
		// an update touching two locked fields at once. It was rejected with
		//
		// "A hybrid app's description is read-only — it mirrors the installed
		// Nextcloud app it customizes."
		//
		// (description first only because it came first in LOCKED_FIELDS; the
		// update dropped it under PUT semantics, and productionVersion was
		// locked too). So the hybrid example could never be seeded once that
		// listener shipped. Nothing said so: globalSetup swallows a seed failure
		// as a warning, and the E2E job had never run in CI at all. It surfaced
		// the first time it did — run 31029961494.
		//
		// Both of those locks have since been removed as defects in their own
		// right — `productionVersion` because the canonical spec REQUIRES a
		// hybrid app to point at its delta version, and `description` because
		// OR's PUT-semantic saveObject delivers an unmentioned field as null,
		// which the lock read as a deliberate edit. See
		// HybridMetadataLockListener::LOCKED_FIELDS. The single-create shape is
		// kept anyway: it is one write instead of three, and it does not depend
		// on which fields the listener happens to guard today.
		//
		// Creating the version first requires the parent UUID up front, which is
		// why it is minted. The forward reference is safe: OR only validates a
		// relation target's existence for `$ref` properties carrying
		// `validateReference`, and ApplicationVersion.application is an
		// `x-openregister-relation` with neither.
		$applicationUuid = $this->uuid4();

		$version = $this->create(
			register: $register,
			schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
			data: [
				'name' => 'Production',
				'slug' => self::VERSION_SLUG,
				'manifest' => (object)[],
				'manifestDelta' => [
					'pages' => [
						'Publications' => ['title' => 'Open Data'],
					],
				],
				'baseRef' => $baseRef,
				'register' => $register . '-' . self::HYBRID_SLUG,
				'semver' => '0.1.0',
				'status' => 'published',
				'application' => $applicationUuid,
			]
		);
		$versionUuid = $version->getUuid();

		// One create, carrying the full locked identity + the production
		// pointer. `uuid:` names an object that does not exist yet, which OR
		// resolves to a CREATE with that identifier (SaveObject falls through
		// to handleObjectCreation when the lookup finds nothing), so no
		// ObjectUpdatingEvent is dispatched and the metadata lock is satisfied
		// by construction rather than bypassed.
		$this->create(
			register: $register,
			schema: ApplicationVersionService::APPLICATION_SCHEMA,
			data: [
				'slug' => self::HYBRID_SLUG,
				'name' => 'OpenCatalogi',
				'description' => 'Seeded hybrid example — a local layout customization layered over the installed OpenCatalogi app.',
				'appType' => 'hybrid',
				'baseRef' => $baseRef,
				'productionVersion' => $versionUuid,
			],
			uuid: $applicationUuid
		);

		$output->writeln('<info>Seeded hybrid example app (application ' . $applicationUuid . ').</info>');
	}//end seedHybridExample()

	/**
	 * Generate a UUIDv4.
	 *
	 * Same implementation as ExportJobService::uuid4() — kept local because this
	 * command must not take a service dependency for one identifier, and the
	 * seeder needs the parent Application's UUID BEFORE the object exists (see
	 * seedHybridExample()).
	 *
	 * @return string UUIDv4 in canonical 8-4-4-4-12 form.
	 *
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	private function uuid4(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
		$hex = bin2hex($data);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}//end uuid4()

	/**
	 * Whether the hybrid example Application already exists.
	 *
	 * @param string $register The buildiq register slug.
	 *
	 * @return bool True when the hybrid example is already present.
	 */
	private function hybridExists(string $register): bool {
		$registerId = $this->registerMapper->find($register, _multitenancy: false)->getId();
		$appSchema = $this->schemaMapper->find(
			ApplicationVersionService::APPLICATION_SCHEMA,
			_multitenancy: false
		)->getId();
		$apps = $this->objectService->searchObjects(
			['@self' => ['register' => $registerId, 'schema' => $appSchema], 'slug' => self::HYBRID_SLUG, 'appType' => 'hybrid'],
			_rbac: false,
			_multitenancy: false
		);
		return empty($apps) === false;
	}//end hybridExists()

	/**
	 * Create an object with RBAC + multitenancy disabled. The command runs
	 * via occ as the system (Anonymous) user, so the normal per-user write
	 * guards must be bypassed for this system-seed operation.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $data The object data.
	 * @param string|null $uuid Optional UUID to update an existing object.
	 *
	 * @return ObjectEntityInterface The saved object.
	 */
	private function create(string $register, string $schema, array $data, ?string $uuid = null): ObjectEntityInterface {
		return $this->objectService->saveObject(
			$data,
			register: $register,
			schema: $schema,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
	}//end create()

	/**
	 * Whether a BuiltAppRoute for the hello-world slug already exists.
	 *
	 * @param string $register The register slug.
	 *
	 * @return bool True when the route already exists.
	 */
	private function routeExists(string $register): bool {
		// OR's searchObjects `@self` filter matches on numeric register/schema
		// IDs, not slugs (mirrors ApplicationsController::resolveApplicationBySlug).
		$registerId = $this->registerMapper->find($register, _multitenancy: false)->getId();
		$routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();
		$routes = $this->objectService->searchObjects(
			['@self' => ['register' => $registerId, 'schema' => $routeSchema], 'slug' => self::SEED_SLUG],
			_rbac: false,
			_multitenancy: false
		);
		return empty($routes) === false;
	}//end routeExists()

	/**
	 * Whether the hello-world Application object already exists in the register.
	 *
	 * Unlike routeExists(), an Application object survives an app disable/enable
	 * cycle (the built-app-route registration does not). Guarding the seed on the
	 * Application's existence is what keeps re-running truly idempotent: without
	 * it, every disable/enable + re-seed created a NEW duplicate hello-world
	 * Application, and loadApplication() (which resolves a single object by slug)
	 * then 404s on the ambiguous set.
	 *
	 * @param string $register The Buildiq register slug.
	 *
	 * @return bool True when a hello-world Application object already exists.
	 */
	private function applicationExists(string $register): bool {
		$registerId = $this->registerMapper->find($register, _multitenancy: false)->getId();
		$appSchema = $this->schemaMapper->find(ApplicationVersionService::APPLICATION_SCHEMA, _multitenancy: false)->getId();
		$apps = $this->objectService->searchObjects(
			['@self' => ['register' => $registerId, 'schema' => $appSchema], 'slug' => self::SEED_SLUG],
			_rbac: false,
			_multitenancy: false
		);
		return empty($apps) === false;
	}//end applicationExists()

	/**
	 * The canonical hello-world manifest — index + detail + form pages over
	 * the seeded `hello-message` schema in the shared `buildiq` register.
	 *
	 * @return array<string, mixed>
	 */
	private function buildManifest(): array {
		$reg = ApplicationVersionService::REGISTER_SLUG;
		return [
			'version' => self::SEMVER,
			'dependencies' => ['openregister'],
			'menu' => [
				['id' => 'Messages', 'label' => 'Messages', 'icon' => 'icon-comment', 'route' => 'Messages', 'order' => 1],
			],
			'pages' => [
				[
					'id' => 'Messages',
					'route' => '/',
					'type' => 'index',
					'title' => 'Messages',
					'config' => ['register' => $reg, 'schema' => 'hello-message', 'columns' => ['title', 'body', '@self.created']],
				],
				$this->buildMessageDetailPage(registerSlug: $reg),
				[
					'id' => 'MessageCreate',
					'route' => '/messages/new',
					'type' => 'form',
					'title' => 'New message',
					'config' => [
						'register' => $reg,
						'schema' => 'hello-message',
						'mode' => 'create',
						// `fields[]` is REQUIRED for a form page:
						// validateManifest() rejects a form page whose config has
						// no non-empty fields[] with "form pages must declare a
						// non-empty fields[] array" (manifest-form-page-type
						// REQ-MFPT-*). Each entry needs key + label + a type from
						// the closed enum (boolean|number|string|enum|password|json).
						//
						// Omitting it meant the SEEDED example app shipped a
						// manifest that fails the app's own validator, and because
						// the page designer gates Save on `validatorErrors.length
						// === 0`, "Save & open preview" was permanently DISABLED
						// for hello-world — nobody could save any page edit to the
						// one app every new user starts from. It also blocked three
						// page-editor-coverage specs, which reached Save and found
						// it disabled for a page they had configured correctly.
						//
						// Mirrors the index page's columns and the sample messages,
						// which both use `title` + `body`.
						'fields' => [
							['key' => 'title', 'label' => 'Title', 'type' => 'string'],
							['key' => 'body', 'label' => 'Body', 'type' => 'string'],
						],
						'submitEndpoint' => '/index.php/apps/openregister/api/objects/' . $reg . '/hello-message',
					],
				],
			],
		];
	}//end buildManifest()

	/**
	 * The `MessageDetail` page, with its body grid EJECTED on purpose.
	 *
	 * ⚠️ AN AUTO-BODY DETAIL PAGE LOSES ITS DATA WIDGET ON A LOST RACE.
	 *
	 * A `type: detail` page with no widgets/layout takes nc-vue's auto-body
	 * path. `CnDetailPage` fires `Promise.all([fetchObject(), fetchSchema()])`,
	 * but `shouldRenderAutoBody` flips as soon as the OBJECT lands — it does
	 * not wait for the schema — the watcher materialises the grid EXACTLY
	 * ONCE, and `materializeAutoBody()` DROPS the Data widget when
	 * `currentSchema` is still null. Nothing rebuilds it when the schema
	 * arrives, and `fetchSchema` fails silently, so there is no error state
	 * either: the page renders its header and "Related" and simply has no data
	 * on it.
	 *
	 * Measured in CI (job 95207190738, playwright-traces): the object response
	 * completed at 16.171 s and the schema at 16.181 s — ten milliseconds
	 * apart, object first — and the captured page snapshot has no Data widget.
	 * That is the whole of the builder-host "detail page must render the seeded
	 * body text" failure. It passes on a developer box because the schema
	 * usually wins the race.
	 *
	 * Ejecting the default grid takes the explicit-grid path instead
	 * (`hasGridLayout` wins over the auto-body), where the widget's schema
	 * arrives through `CnPageRenderer`'s read-through detail context and fills
	 * in reactively. The shape below is byte-for-byte nc-vue's own
	 * `defaultDetailGrid()`, which is also exactly what Buildiq's edit button
	 * writes the moment anyone edits this page — so this changes no pixels, it
	 * only removes the race.
	 *
	 * The underlying nc-vue defect is NOT fixed by this: any other auto-body
	 * detail page still has it.
	 *
	 * @param string $registerSlug Register the page and its Data widget read from.
	 *
	 * @return array<string, mixed> The manifest page definition.
	 *
	 * @spec openspec/specs/openbuild-page-designer/spec.md
	 */
	private function buildMessageDetailPage(string $registerSlug): array {
		return [
			'id' => 'MessageDetail',
			'route' => '/messages/:id',
			'type' => 'detail',
			'title' => 'Message',
			'config' => [
				'register' => $registerSlug,
				'schema' => 'hello-message',
				'widgets' => [
					[
						'id' => 'data',
						'widgetId' => 'data',
						'type' => 'data',
						'title' => 'Data',
						'content' => [
							'register' => $registerSlug,
							'schema' => 'hello-message',
							'columns' => 3,
							'overrides' => new stdClass(),
						],
					],
					[
						'id' => 'related',
						'widgetId' => 'related',
						'type' => 'related',
						'title' => 'Related',
						'content' => ['title' => '', 'groups' => []],
					],
				],
				'layout' => [
					['id' => 'data', 'widgetId' => 'data', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 6, 'showTitle' => false],
					['id' => 'related', 'widgetId' => 'related', 'gridX' => 0, 'gridY' => 6, 'gridWidth' => 12, 'gridHeight' => 5, 'showTitle' => false],
				],
			],
		];
	}//end buildMessageDetailPage()

	/**
	 * The three sample HelloMessage objects rendered by the index page.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function buildSampleMessages(): array {
		return [
			[
				'title' => 'Welcome to Buildiq',
				'body' => 'This message is rendered by your first virtual app — built from a JSON manifest stored in OpenRegister.',
			],
			[
				'title' => 'Edit me',
				'body' => 'Open the Buildiq shell, find hello-world, and edit its manifest to change what you see here.',
			],
			[
				'title' => 'Built from a manifest',
				'body' => 'Everything here — menu, pages, columns, form — came from a JSON manifest. No PHP was written for hello-world.',
			],
		];
	}//end buildSampleMessages()
}//end class
