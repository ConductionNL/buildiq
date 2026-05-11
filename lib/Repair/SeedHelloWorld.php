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
            // Idempotency guard — if a hello-world Application already exists, do nothing.
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

            if (empty($existing) === false) {
                $output->info('hello-world Application already exists; skipping seed.');
                return;
            }

            // Create the Application object with the canonical hello-world manifest.
            // NOTE (design.md OQ-1): OR's current x-openregister-lifecycle engine does
            // not yet support `on_transition.upsert_relation` as a declarative action
            // that creates a sibling object. Until OR ships that hook we explicitly
            // create the BuiltAppRoute here. This is the ADR-031 §Exceptions(1) path.
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

            // ObjectEntity exposes its fields via jsonSerialize() (returns an array
            // including the OR-assigned uuid). __call-based getters like getUuid()
            // are invisible to method_exists, so we read through the array.
            // OR places the canonical uuid under @self.id in the serialized shape.
            $applicationData = $application->jsonSerialize();
            $applicationSelf = ($applicationData['@self'] ?? []);
            $applicationUuid = ($applicationSelf['id'] ?? ($applicationSelf['uuid'] ?? $applicationData['uuid'] ?? null));

            $output->info('Created hello-world Application (uuid='.($applicationUuid ?? 'unknown').').');

            // Explicit BuiltAppRoute upkeep — fallback for the missing lifecycle hook.
            if ($applicationUuid !== null) {
                $this->objectService->saveObject(
                    object: [
                        'slug'            => self::SEED_SLUG,
                        'applicationUuid' => $applicationUuid,
                    ],
                    register: 'openbuilt',
                    schema: 'built-app-route'
                );
                $output->info('Created BuiltAppRoute for hello-world.');

                // Seed one ApplicationVersion snapshot (chain spec #6 openbuilt-versioning)
                // so the version-history panel is non-empty on a fresh install and the
                // diff view has something to render in the walkthrough. Same
                // ADR-031 §Exceptions(1) rationale as the BuiltAppRoute upkeep above —
                // the lifecycle action declared on Application is not yet executed by
                // OR's engine, so we wire the seed snapshot explicitly. The listener
                // does the same on every subsequent publish.
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
                $snapshotSelf = ($snapshotData['@self'] ?? []);
                $snapshotUuid = ($snapshotSelf['id'] ?? ($snapshotSelf['uuid'] ?? ($snapshotData['uuid'] ?? null)));

                if ($snapshotUuid !== null) {
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
                }
            }

            // Seed three sample HelloMessage objects.
            foreach ($this->buildSampleMessages() as $message) {
                $this->objectService->saveObject(
                    object: $message,
                    register: 'openbuilt',
                    schema: 'hello-message'
                );
            }

            $output->info('Seeded three sample HelloMessage objects.');

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
