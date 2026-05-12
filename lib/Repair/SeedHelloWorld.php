<?php

/**
 * OpenBuilt Seed Hello World Repair Step
 *
 * Seeds the canonical `hello-world` virtual app on install/upgrade so
 * the install is testable out of the box. Idempotent — guarded on
 * existing-slug; re-running on a seeded install is a no-op.
 *
 * Per design.md Seed Data section (ADR-001 compliance), this step
 * creates one published Application with slug `hello-world` plus its
 * companion `hello-message` schema's three sample objects. The
 * Application's x-openregister-lifecycle handles the BuiltAppRoute
 * upkeep on publish.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenBuilt\Repair
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

namespace OCA\OpenBuilt\Repair;

use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds the hello-world virtual app + sample messages.
 */
class SeedHelloWorld implements IRepairStep
{
    private const SEED_SLUG    = 'hello-world';
    private const SEED_VERSION = '1.0.0';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger        Logger for diagnostics
     * @param ObjectService   $objectService OpenRegister object service (hard dep via info.xml)
     *
     * @return void
     */
    public function __construct(
        private LoggerInterface $logger,
        private ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed the canonical hello-world virtual app and sample messages';
    }//end getName()

    /**
     * Run the repair step to seed the hello-world virtual app.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding hello-world virtual app...');

        try {
            if ($this->seedAlreadyExists() === true) {
                $output->info('hello-world Application already exists; skipping seed.');
                return;
            }

            $applicationUuid = $this->seedApplicationAndRoute(output: $output);

            if ($applicationUuid !== null) {
                $this->seedInitialSnapshot(output: $output, applicationUuid: $applicationUuid);
            }

            $this->seedSampleMessages(output: $output);

            $this->logger->info('OpenBuilt: hello-world virtual app seeded successfully');
        } catch (\Throwable $e) {
            $output->warning('Could not seed hello-world: '.$e->getMessage());
            $this->logger->error(
                'OpenBuilt: SeedHelloWorld failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Idempotency guard — true when a hello-world Application is already present.
     *
     * @return bool
     */
    private function seedAlreadyExists(): bool
    {
        $existing = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => 'openbuilt',
                    'schema'   => 'application',
                    'slug'     => self::SEED_SLUG,
                ],
                'limit'   => 1,
            ]
        );

        return empty($existing) === false;
    }//end seedAlreadyExists()

    /**
     * Create the hello-world Application AND the BuiltAppRoute pointing at it.
     *
     * Returns the application UUID (or null if OR did not return one) so the
     * caller can chain the initial snapshot seed. Per design.md OQ-1, OR's
     * x-openregister-lifecycle engine does not yet support
     * `on_transition.upsert_relation`, so the BuiltAppRoute is created
     * explicitly — ADR-031 §Exceptions(1) declarative-first failure mode.
     *
     * @param IOutput $output Progress reporter.
     *
     * @return string|null The created Application's UUID, or null on miss.
     */
    private function seedApplicationAndRoute(IOutput $output): ?string
    {
        $seedManifest = $this->buildHelloWorldManifest();
        $application  = $this->objectService->saveObject(
            object: [
                'slug'        => self::SEED_SLUG,
                'name'        => 'Hello World',
                'description' => 'The canonical seed virtual app for OpenBuilt. Exercises index + detail + form page types.',
                'version'     => self::SEED_VERSION,
                'status'      => 'published',
                'manifest'    => $seedManifest,
            ],
            register: 'openbuilt',
            schema: 'application'
        );

        $applicationData = $application->jsonSerialize();
        $applicationUuid = $this->extractUuid(data: $applicationData);

        $output->info('Created hello-world Application (uuid='.($applicationUuid ?? 'unknown').').');

        if ($applicationUuid === null) {
            return null;
        }

        $this->objectService->saveObject(
            object: [
                'slug'            => self::SEED_SLUG,
                'applicationUuid' => $applicationUuid,
            ],
            register: 'openbuilt',
            schema: 'built-app-route'
        );
        $output->info('Created BuiltAppRoute for hello-world.');

        return $applicationUuid;
    }//end seedApplicationAndRoute()

    /**
     * Seed one ApplicationVersion snapshot AND point Application.currentVersion at it.
     *
     * Chain spec #6 openbuilt-versioning — same ADR-031 §Exceptions(1) rationale
     * as the BuiltAppRoute upkeep above. The listener does the same on every
     * subsequent publish.
     *
     * @param IOutput $output          Progress reporter.
     * @param string  $applicationUuid The parent Application's UUID.
     *
     * @return void
     */
    private function seedInitialSnapshot(IOutput $output, string $applicationUuid): void
    {
        $seedManifest = $this->buildHelloWorldManifest();

        $snapshot = $this->objectService->saveObject(
            object: [
                'applicationUuid' => $applicationUuid,
                'version'         => self::SEED_VERSION,
                'manifest'        => $seedManifest,
                'publishedAt'     => gmdate('Y-m-d\TH:i:s\Z'),
                'publishedBy'     => 'system',
                'notes'           => 'Seeded by OpenBuilt install — initial published version',
            ],
            register: 'openbuilt',
            schema: 'application-version'
        );

        $snapshotData = $snapshot->jsonSerialize();
        $snapshotUuid = $this->extractUuid(data: $snapshotData);

        if ($snapshotUuid === null) {
            return;
        }

        // Patch the Application with currentVersion pointing at the snapshot.
        $this->objectService->saveObject(
            object: [
                'slug'           => self::SEED_SLUG,
                'name'           => 'Hello World',
                'description'    => 'The canonical seed virtual app for OpenBuilt. Exercises index + detail + form page types.',
                'version'        => self::SEED_VERSION,
                'status'         => 'published',
                'manifest'       => $seedManifest,
                'currentVersion' => $snapshotUuid,
            ],
            register: 'openbuilt',
            schema: 'application'
        );
        $output->info('Seeded initial ApplicationVersion '.$snapshotUuid.' (currentVersion set).');
    }//end seedInitialSnapshot()

    /**
     * Seed three sample HelloMessage objects.
     *
     * @param IOutput $output Progress reporter.
     *
     * @return void
     */
    private function seedSampleMessages(IOutput $output): void
    {
        foreach ($this->buildSampleMessages() as $message) {
            $this->objectService->saveObject(
                object: $message,
                register: 'openbuilt',
                schema: 'hello-message'
            );
        }

        $output->info('Seeded three sample HelloMessage objects.');
    }//end seedSampleMessages()

    /**
     * Read the canonical UUID out of an OR-serialised object array.
     *
     * @param array<string, mixed> $data Serialised object array.
     *
     * @return string|null The UUID or null if not present.
     */
    private function extractUuid(array $data): ?string
    {
        $self = [];
        if (isset($data['@self']) === true && is_array($data['@self']) === true) {
            $self = $data['@self'];
        }

        if (isset($self['id']) === true) {
            return (string) $self['id'];
        }

        if (isset($self['uuid']) === true) {
            return (string) $self['uuid'];
        }

        if (isset($data['uuid']) === true) {
            return (string) $data['uuid'];
        }

        return null;
    }//end extractUuid()

    /**
     * Build the canonical hello-world manifest.
     *
     * Per design.md Seed Data: exercises index + detail + form page types
     * against the seeded `hello-message` schema. Labels and titles use
     * i18n keys consumed by the consuming app's t() (ADR-024 §6, ADR-007).
     *
     * @return array<string, mixed>
     */
    private function buildHelloWorldManifest(): array
    {
        return [
            'version'      => '1.0.0',
            'dependencies' => ['openregister'],
            'menu'         => [
                [
                    'id'    => 'Messages',
                    'label' => 'openbuilt.helloworld.menu.messages',
                    'icon'  => 'icon-comment',
                    'route' => 'Messages',
                    'order' => 1,
                ],
            ],
            'pages'        => [
                [
                    'id'     => 'Messages',
                    'route'  => '/',
                    'type'   => 'index',
                    'title'  => 'openbuilt.helloworld.title.messages',
                    'config' => [
                        'register' => 'openbuilt',
                        'schema'   => 'hello-message',
                        'columns'  => ['title', 'body', '@self.created'],
                    ],
                ],
                [
                    'id'     => 'MessageDetail',
                    'route'  => '/messages/:id',
                    'type'   => 'detail',
                    'title'  => 'openbuilt.helloworld.title.message',
                    'config' => [
                        'register' => 'openbuilt',
                        'schema'   => 'hello-message',
                    ],
                ],
                [
                    'id'     => 'MessageCreate',
                    'route'  => '/messages/new',
                    'type'   => 'form',
                    'title'  => 'openbuilt.helloworld.title.create',
                    'config' => [
                        'register'       => 'openbuilt',
                        'schema'         => 'hello-message',
                        'mode'           => 'create',
                        'submitEndpoint' => '/index.php/apps/openregister/api/objects/openbuilt/hello-message',
                    ],
                ],
            ],
        ];
    }//end buildHelloWorldManifest()

    /**
     * Build the three sample HelloMessage objects.
     *
     * Bodies are kept under the 150-character line limit for PHPCS.
     *
     * @return array<int, array<string, string>>
     */
    private function buildSampleMessages(): array
    {
        return [
            [
                'title' => 'Welcome to OpenBuilt',
                'body'  => 'This message is rendered by your first virtual app — built from a JSON manifest stored in OpenRegister.',
            ],
            [
                'title' => 'Edit me',
                'body'  => 'Open the OpenBuilt shell, find hello-world, and edit its manifest to change what you see here.',
            ],
            [
                'title' => 'Built from a manifest',
                'body'  => 'Everything here — menu, pages, columns, form — came from a JSON manifest. No PHP was written for hello-world.',
            ],
        ];
    }//end buildSampleMessages()
}//end class
