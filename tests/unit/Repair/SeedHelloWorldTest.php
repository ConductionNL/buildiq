<?php

/**
 * Unit tests for SeedHelloWorld repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Repair
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

namespace OCA\OpenBuilt\Tests\Unit\Repair;

use OCA\OpenBuilt\Repair\SeedHelloWorld;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedHelloWorld::run.
 */
class SeedHelloWorldTest extends TestCase
{
    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock IOutput.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->output        = $this->createMock(IOutput::class);
        $this->objectService = $this->createMock(ObjectService::class);
    }//end setUp()

    /**
     * Test that getName returns a non-empty descriptive name.
     *
     * @return void
     */
    public function testGetNameReturnsDescriptiveName(): void
    {
        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);

        $name = $step->getName();

        self::assertNotEmpty($name);
        self::assertStringContainsString('hello-world', $name);
    }//end testGetNameReturnsDescriptiveName()

    /**
     * Test idempotency — when an existing hello-world Application is found, saveObject is NOT called.
     *
     * @return void
     */
    public function testRunIsIdempotentWhenSeedAlreadyExists(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([['slug' => 'hello-world']]);

        $this->objectService->expects(self::never())
            ->method('saveObject');

        $this->output->expects(self::atLeastOnce())->method('info');

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);
    }//end testRunIsIdempotentWhenSeedAlreadyExists()

    /**
     * Test fresh-install path — when no existing hello-world exists, saveObject is called for the
     * Application + BuiltAppRoute + initial ApplicationVersion + currentVersion writeback + three
     * HelloMessage objects = 7 total saves.
     *
     * Per chain spec #6 openbuilt-versioning (design.md §Seed Data) the initial snapshot is
     * created at install time so the version-history panel is non-empty on the fresh-install
     * hello-world Application.
     *
     * @return void
     */
    public function testRunCreatesApplicationAndThreeMessagesOnFreshInstall(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        // Returned entities must jsonSerialize() to an array carrying a uuid so the
        // seed code can chain (Application uuid → snapshot, snapshot uuid → patch).
        $appEntity = $this->createMock(ObjectEntity::class);
        $appEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'app-uuid-seed']]);

        $snapEntity = $this->createMock(ObjectEntity::class);
        $snapEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'snap-uuid-seed']]);

        $genericEntity = $this->createMock(ObjectEntity::class);
        $genericEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'generic']]);

        // ObjectService::saveObject(array|ObjectEntity $object, ?array $extend, mixed $register, mixed $schema):
        // the named-arg call site (object/register/schema) yields positional args [object, [], register, schema].
        $schemaOf = static fn (array $args): mixed => ($args['schema'] ?? ($args[3] ?? null));
        $objectOf = static fn (array $args): mixed => ($args['object'] ?? ($args[0] ?? null));

        $captured = [];
        $this->objectService->expects(self::exactly(7))
            ->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$captured, $appEntity, $snapEntity, $genericEntity, $schemaOf) {
                $captured[] = $args;
                $schema     = $schemaOf($args);
                if ($schema === 'application') {
                    return $appEntity;
                }
                if ($schema === 'application-version') {
                    return $snapEntity;
                }
                return $genericEntity;
            });

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);

        // Assert at least one save targets the application-version schema with a 1.0.0 manifest.
        $snapshotCalls = array_values(array_filter($captured, static function (array $args) use ($schemaOf): bool {
            return ($schemaOf($args) === 'application-version');
        }));
        self::assertCount(1, $snapshotCalls, 'Expected exactly one initial ApplicationVersion seed save.');
        $payload = $objectOf($snapshotCalls[0]);
        self::assertSame('1.0.0', $payload['version']);
        self::assertSame('app-uuid-seed', $payload['applicationUuid']);
        self::assertArrayHasKey('manifest', $payload);
    }//end testRunCreatesApplicationAndThreeMessagesOnFreshInstall()

    /**
     * Test that the seeded hello-world manifest blob is structurally valid against the
     * canonical app-manifest contract (ADR-024): a version string, a menu of well-formed
     * entries, and a non-empty list of well-formed pages whose ids are unique and whose
     * data-bound types declare a register + schema; every menu route resolves to a page id.
     * Covers bootstrap-openbuilt verification task 4.3.
     *
     * @return void
     */
    public function testSeededHelloWorldManifestIsStructurallyValid(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $captured = [];
        $entity   = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'app-uuid-seed']]);
        $this->objectService->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$captured, $entity) {
                $captured[] = $args;
                return $entity;
            });

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);

        // The Application save is the one whose schema arg is 'application'.
        $schemaOf = static fn (array $args): mixed => ($args['schema'] ?? ($args[3] ?? null));
        $objectOf = static fn (array $args): mixed => ($args['object'] ?? ($args[0] ?? null));
        $appCalls = array_values(array_filter($captured, static fn (array $a): bool => $schemaOf($a) === 'application'));
        self::assertNotEmpty($appCalls, 'Expected a saveObject call against the application schema.');

        $manifest = $objectOf($appCalls[0])['manifest'] ?? null;
        self::assertIsArray($manifest, 'Seeded Application carries a manifest array.');

        // version
        self::assertArrayHasKey('version', $manifest);
        self::assertIsString($manifest['version']);
        self::assertNotSame('', $manifest['version']);

        // pages
        self::assertArrayHasKey('pages', $manifest);
        self::assertIsArray($manifest['pages']);
        self::assertNotEmpty($manifest['pages'], 'A virtual app needs at least one page.');
        $pageIds       = [];
        $allowedTypes  = ['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'form', 'custom'];
        $dataBoundTypes = ['index', 'detail', 'form'];
        foreach ($manifest['pages'] as $page) {
            self::assertIsArray($page);
            foreach (['id', 'route', 'type', 'title'] as $required) {
                self::assertArrayHasKey($required, $page, "page is missing '$required'");
                self::assertIsString($page[$required]);
                self::assertNotSame('', $page[$required], "page '$required' is empty");
            }
            self::assertContains($page['type'], $allowedTypes, "page '{$page['id']}' has an unknown type '{$page['type']}'");
            self::assertNotContains($page['id'], $pageIds, "duplicate page id '{$page['id']}'");
            $pageIds[] = $page['id'];
            if (in_array($page['type'], $dataBoundTypes, true) === true) {
                self::assertArrayHasKey('config', $page, "data-bound page '{$page['id']}' needs a config");
                self::assertIsString($page['config']['register'] ?? null, "page '{$page['id']}' config needs a register");
                self::assertIsString($page['config']['schema'] ?? null, "page '{$page['id']}' config needs a schema");
            }
        }

        // menu
        self::assertArrayHasKey('menu', $manifest);
        self::assertIsArray($manifest['menu']);
        foreach ($manifest['menu'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['id'] ?? null);
            self::assertNotSame('', $entry['id']);
            self::assertIsString($entry['label'] ?? null);
            self::assertNotSame('', $entry['label']);
            // An entry routes to a page id, links out via href, or invokes a built-in action.
            $targets = array_filter([
                isset($entry['route']) ? 'route' : null,
                isset($entry['href']) ? 'href' : null,
                isset($entry['action']) ? 'action' : null,
            ]);
            self::assertNotEmpty($targets, "menu entry '{$entry['id']}' has no route/href/action");
            if (isset($entry['route']) === true) {
                self::assertContains($entry['route'], $pageIds, "menu entry '{$entry['id']}' routes to unknown page '{$entry['route']}'");
            }
        }
    }//end testSeededHelloWorldManifestIsStructurallyValid()
}//end class
