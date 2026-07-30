<?php

/**
 * OpenBuild SeedHelloWorldFixture Command
 *
 * Occ command that idempotently seeds the canonical hello-world virtual app
 * fixture used by the Playwright e2e suite.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenBuild\Command
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

namespace OCA\OpenBuild\Command;

use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Occ openbuild:seed-hello-world-fixture.
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
class SeedHelloWorldFixture extends Command
{
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
     * Constructor.
     *
     * @param ObjectService  $objectService  OpenRegister object service.
     * @param RegisterMapper $registerMapper OpenRegister register mapper.
     * @param SchemaMapper   $schemaMapper   OpenRegister schema mapper.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure the command name and description.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'openbuild:seed-hello-world-fixture')
            ->setDescription(description: 'Seed the hello-world virtual app fixture for the e2e suite (idempotent; test/dev only).');
    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  The command input.
     * @param OutputInterface $output The command output.
     *
     * @return int Command exit code.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
            $application     = $this->create(
                register: $register,
                schema: ApplicationVersionService::APPLICATION_SCHEMA,
                data: [
                    'slug'        => self::SEED_SLUG,
                    'name'        => 'Hello World',
                    'description' => 'Seeded e2e fixture — your first virtual app built from a JSON manifest.',
                    // Grant the admin user owner rights so automation ops
                    // (compile/enable/dry-run — WRITE_ROLES ['owners','editors'])
                    // are permitted. Wizard-created apps set this; the seed ran
                    // in system context with permissions=null, which makes
                    // matchesCaller() deny everyone (empty-permissions = deny,
                    // allowAdminBypass=false) and 403s every automation op.
                    'permissions' => [
                        'owners'  => ['user:admin'],
                        'editors' => [],
                        'viewers' => [],
                    ],
                ]
            );
            $applicationUuid = $application->getUuid();

            // 2. Published version carrying the manifest.
            $version     = $this->create(
                register: $register,
                schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
                data: [
                    'name'        => self::SEMVER,
                    'slug'        => self::VERSION_SLUG,
                    'manifest'    => $this->buildManifest(),
                    // The version's `register` field names the app's per-app
                    // data register (pattern: openbuild-<slug>). The shared
                    // `hello-message` data + manifest pages live in the main
                    // `openbuild` register, so this is metadata only.
                    'register'    => $register.'-'.self::SEED_SLUG,
                    'semver'      => self::SEMVER,
                    'status'      => 'published',
                    'application' => $applicationUuid,
                ]
            );
            $versionUuid = $version->getUuid();

            // 3. Point the Application at its production version.
            $this->create(
                register: $register,
                schema: ApplicationVersionService::APPLICATION_SCHEMA,
                data: [
                    'slug'              => self::SEED_SLUG,
                    'name'              => 'Hello World',
                    'description'       => 'Seeded e2e fixture — your first virtual app built from a JSON manifest.',
                    'productionVersion' => $versionUuid,
                ],
                uuid: $applicationUuid
            );

            // 4. BuiltAppRoute so getManifest()/resolveApplicationBySlug() resolve the slug.
            $this->create(
                register: $register,
                schema: 'built-app-route',
                data: [
                    'slug'            => self::SEED_SLUG,
                    'applicationUuid' => $applicationUuid,
                ]
            );

            // 5. Three sample messages rendered by the index page.
            foreach ($this->buildSampleMessages() as $message) {
                $this->create(register: $register, schema: 'hello-message', data: $message);
            }

            $output->writeln('<info>Seeded hello-world fixture (application '.$applicationUuid.').</info>');

            // Also seed the hybrid example app (unify-apps-with-app-type).
            $this->seedHybridExample(register: $register, output: $output);
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to seed hello-world fixture: '.$e->getMessage().'</error>');
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
     * @param string          $register The openbuild register slug.
     * @param OutputInterface $output   The command output.
     *
     * @return void
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
     */
    private function seedHybridExample(string $register, OutputInterface $output): void
    {
        if ($this->hybridExists(register: $register) === true) {
            $output->writeln('<info>hybrid example already present — nothing to do.</info>');
            return;
        }

        $baseRef = ['kind' => 'fleet-app', 'id' => self::HYBRID_SLUG];

        $application     = $this->create(
            register: $register,
            schema: ApplicationVersionService::APPLICATION_SCHEMA,
            data: [
                'slug'        => self::HYBRID_SLUG,
                'name'        => 'OpenCatalogi',
                'description' => 'Seeded hybrid example — a local layout customization layered over the installed OpenCatalogi app.',
                'appType'     => 'hybrid',
                'baseRef'     => $baseRef,
            ]
        );
        $applicationUuid = $application->getUuid();

        $version     = $this->create(
            register: $register,
            schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
            data: [
                'name'          => 'Production',
                'slug'          => self::VERSION_SLUG,
                'manifest'      => (object) [],
                'manifestDelta' => [
                    'pages' => [
                        'Publications' => ['title' => 'Open Data'],
                    ],
                ],
                'baseRef'       => $baseRef,
                'register'      => $register.'-'.self::HYBRID_SLUG,
                'semver'        => '0.1.0',
                'status'        => 'published',
                'application'   => $applicationUuid,
            ]
        );
        $versionUuid = $version->getUuid();

        $this->create(
            register: $register,
            schema: ApplicationVersionService::APPLICATION_SCHEMA,
            data: [
                'slug'              => self::HYBRID_SLUG,
                'name'              => 'OpenCatalogi',
                'appType'           => 'hybrid',
                'baseRef'           => $baseRef,
                'productionVersion' => $versionUuid,
            ],
            uuid: $applicationUuid
        );

        $output->writeln('<info>Seeded hybrid example app (application '.$applicationUuid.').</info>');
    }//end seedHybridExample()

    /**
     * Whether the hybrid example Application already exists.
     *
     * @param string $register The openbuild register slug.
     *
     * @return bool True when the hybrid example is already present.
     */
    private function hybridExists(string $register): bool
    {
        $registerId = $this->registerMapper->find($register, _multitenancy: false)->getId();
        $appSchema  = $this->schemaMapper->find(
            ApplicationVersionService::APPLICATION_SCHEMA,
            _multitenancy: false
        )->getId();
        $apps       = $this->objectService->searchObjects(
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
     * @param string               $register The register slug.
     * @param string               $schema   The schema slug.
     * @param array<string, mixed> $data     The object data.
     * @param string|null          $uuid     Optional UUID to update an existing object.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity The saved object.
     */
    private function create(string $register, string $schema, array $data, ?string $uuid=null): \OCA\OpenRegister\Db\ObjectEntity
    {
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
    private function routeExists(string $register): bool
    {
        // OR's searchObjects `@self` filter matches on numeric register/schema
        // IDs, not slugs (mirrors ApplicationsController::resolveApplicationBySlug).
        $registerId  = $this->registerMapper->find($register, _multitenancy: false)->getId();
        $routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();
        $routes      = $this->objectService->searchObjects(
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
     * @param string $register The OpenBuild register slug.
     *
     * @return bool True when a hello-world Application object already exists.
     */
    private function applicationExists(string $register): bool
    {
        $registerId = $this->registerMapper->find($register, _multitenancy: false)->getId();
        $appSchema  = $this->schemaMapper->find(ApplicationVersionService::APPLICATION_SCHEMA, _multitenancy: false)->getId();
        $apps       = $this->objectService->searchObjects(
            ['@self' => ['register' => $registerId, 'schema' => $appSchema], 'slug' => self::SEED_SLUG],
            _rbac: false,
            _multitenancy: false
        );
        return empty($apps) === false;
    }//end applicationExists()

    /**
     * The canonical hello-world manifest — index + detail + form pages over
     * the seeded `hello-message` schema in the shared `openbuild` register.
     *
     * @return array<string, mixed>
     */
    private function buildManifest(): array
    {
        $reg = ApplicationVersionService::REGISTER_SLUG;
        return [
            'version'      => self::SEMVER,
            'dependencies' => ['openregister'],
            'menu'         => [
                ['id' => 'Messages', 'label' => 'Messages', 'icon' => 'icon-comment', 'route' => 'Messages', 'order' => 1],
            ],
            'pages'        => [
                [
                    'id'     => 'Messages',
                    'route'  => '/',
                    'type'   => 'index',
                    'title'  => 'Messages',
                    'config' => ['register' => $reg, 'schema' => 'hello-message', 'columns' => ['title', 'body', '@self.created']],
                ],
                [
                    'id'     => 'MessageDetail',
                    'route'  => '/messages/:id',
                    'type'   => 'detail',
                    'title'  => 'Message',
                    'config' => ['register' => $reg, 'schema' => 'hello-message'],
                ],
                [
                    'id'     => 'MessageCreate',
                    'route'  => '/messages/new',
                    'type'   => 'form',
                    'title'  => 'New message',
                    'config' => [
                        'register' => $reg,
                        'schema'   => 'hello-message',
                        'mode'     => 'create',
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
                        'fields'   => [
                            ['key' => 'title', 'label' => 'Title', 'type' => 'string'],
                            ['key' => 'body', 'label' => 'Body', 'type' => 'string'],
                        ],
                        'submitEndpoint' => '/index.php/apps/openregister/api/objects/'.$reg.'/hello-message',
                    ],
                ],
            ],
        ];
    }//end buildManifest()

    /**
     * The three sample HelloMessage objects rendered by the index page.
     *
     * @return array<int, array<string, string>>
     */
    private function buildSampleMessages(): array
    {
        return [
            [
                'title' => 'Welcome to OpenBuild',
                'body'  => 'This message is rendered by your first virtual app — built from a JSON manifest stored in OpenRegister.',
            ],
            [
                'title' => 'Edit me',
                'body'  => 'Open the OpenBuild shell, find hello-world, and edit its manifest to change what you see here.',
            ],
            [
                'title' => 'Built from a manifest',
                'body'  => 'Everything here — menu, pages, columns, form — came from a JSON manifest. No PHP was written for hello-world.',
            ],
        ];
    }//end buildSampleMessages()
}//end class
