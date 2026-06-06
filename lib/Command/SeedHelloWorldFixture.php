<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

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
 * occ openbuild:seed-hello-world-fixture
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
 */
class SeedHelloWorldFixture extends Command
{
    private const SEED_SLUG = 'hello-world';

    private const VERSION_SLUG = 'production';

    private const SEMVER = '1.0.0';

    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
        parent::__construct();
    }//end __construct()

    protected function configure(): void
    {
        $this->setName('openbuild:seed-hello-world-fixture')
            ->setDescription('Seed the hello-world virtual app fixture for the e2e suite (idempotent; test/dev only).');
    }//end configure()

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $register = ApplicationVersionService::REGISTER_SLUG;

        try {
            if ($this->routeExists($register) === true) {
                $output->writeln('<info>hello-world fixture already present — nothing to do.</info>');
                return Command::SUCCESS;
            }

            // 1. Application (no productionVersion yet — set after the version exists).
            $application = $this->create(
                $register,
                ApplicationVersionService::APPLICATION_SCHEMA,
                [
                    'slug'        => self::SEED_SLUG,
                    'name'        => 'Hello World',
                    'description' => 'Seeded e2e fixture — your first virtual app built from a JSON manifest.',
                ]
            );
            $applicationUuid = $application->getUuid();

            // 2. Published version carrying the manifest.
            $version = $this->create(
                $register,
                ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
                [
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
                $register,
                ApplicationVersionService::APPLICATION_SCHEMA,
                [
                    'slug'              => self::SEED_SLUG,
                    'name'              => 'Hello World',
                    'description'       => 'Seeded e2e fixture — your first virtual app built from a JSON manifest.',
                    'productionVersion' => $versionUuid,
                ],
                $applicationUuid
            );

            // 4. BuiltAppRoute so getManifest()/resolveApplicationBySlug() resolve the slug.
            $this->create(
                $register,
                'built-app-route',
                [
                    'slug'            => self::SEED_SLUG,
                    'applicationUuid' => $applicationUuid,
                ]
            );

            // 5. Three sample messages rendered by the index page.
            foreach ($this->buildSampleMessages() as $message) {
                $this->create($register, 'hello-message', $message);
            }

            $output->writeln('<info>Seeded hello-world fixture (application '.$applicationUuid.').</info>');
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>Failed to seed hello-world fixture: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }//end try
    }//end execute()

    /**
     * Create an object with RBAC + multitenancy disabled. The command runs
     * via occ as the system (Anonymous) user, so the normal per-user write
     * guards must be bypassed for this system-seed operation.
     *
     * @param array<string, mixed> $data
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
     */
    private function routeExists(string $register): bool
    {
        // OR's searchObjects `@self` filter matches on numeric register/schema
        // IDs, not slugs (mirrors ApplicationsController::resolveApplicationBySlug).
        $registerId  = $this->registerMapper->find($register, _multitenancy: false)->getId();
        $routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();
        $routes = $this->objectService->searchObjects(
            ['@self' => ['register' => $registerId, 'schema' => $routeSchema], 'slug' => self::SEED_SLUG],
            _rbac: false,
            _multitenancy: false
        );
        return empty($routes) === false;
    }//end routeExists()

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
                        'register'       => $reg,
                        'schema'         => 'hello-message',
                        'mode'           => 'create',
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
            ['title' => 'Welcome to OpenBuild', 'body' => 'This message is rendered by your first virtual app — built from a JSON manifest stored in OpenRegister.'],
            ['title' => 'Edit me', 'body' => 'Open the OpenBuild shell, find hello-world, and edit its manifest to change what you see here.'],
            ['title' => 'Built from a manifest', 'body' => 'Everything here — menu, pages, columns, form — came from a JSON manifest. No PHP was written for hello-world.'],
        ];
    }//end buildSampleMessages()
}//end class
