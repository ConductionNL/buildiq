<?php

/**
 * Unit tests for ApplicationConnectionsService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ApplicationConnectionsService;
use OCA\Buildiq\Service\FleetAppId;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sources from the manifest, integrations from integriq, health from integriq's checks.
 */
#[CoversClass(ApplicationConnectionsService::class)]
#[UsesClass(FleetAppId::class)]
#[UsesClass(ObjectRecord::class)]
final class ApplicationConnectionsServiceTest extends TestCase {

	/**
	 * The Vergunningen manifest: two connector-bound pages and a widget on the first source again.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(): array {
		return [
			'pages' => [
				['id' => 'kvk', 'config' => ['dataSource' => ['connector' => ['endpointPath' => '/kvk/companies']]]],
				['id' => 'brp', 'config' => ['dataSource' => ['connector' => ['endpointPath' => 'brp/persons/']]]],
				['id' => 'home', 'config' => ['widgets' => [['dataSource' => ['connector' => ['endpointPath' => 'kvk/companies']]]]]],
				['id' => 'local', 'config' => ['dataSource' => ['register' => 'r', 'schema' => 's']]],
			],
		];
	}//end manifest()

	/**
	 * A service whose integriq answers these rows per schema, recording each read's filters.
	 *
	 * @param bool                                          $installed Whether integriq is installed.
	 * @param array<string, list<array<string, mixed>>>     $rows      Rows by schema.
	 * @param array<int, array<string, mixed>>              $reads     Collects each read's config.
	 *
	 * @return ApplicationConnectionsService
	 */
	private function service(bool $installed, array $rows = [], array &$reads = []): ApplicationConnectionsService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $installed && $app === 'integriq');
		$apps->method('isEnabledForUser')->willReturn($installed);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows, &$reads): array {
				$reads[] = ['config' => $config, 'rbac' => $_rbac];
				$schema = (string)($config['filters']['schema'] ?? '');
				if ($schema === 'broken') {
					throw new RuntimeException('down');
				}

				return ($rows[$schema] ?? []);
			}
		);

		return new ApplicationConnectionsService(objectService: $objects, appManager: $apps, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Integriq's rows for the Vergunningen scenario, with another app's connection mixed in.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function integriqRows(): array {
		return [
			'endpoint' => [
				['endpoint' => '/kvk/companies', 'targetType' => 'api', 'targetId' => 'src-kvk'],
				['endpoint' => 'brp/persons', 'targetType' => 'api', 'targetId' => '7'],
				['endpoint' => 'zaken', 'targetType' => 'register/schema', 'targetId' => 'zrc/zaak'],
			],
			'source' => [
				['uuid' => 'src-kvk', 'name' => 'KvK Handelsregister', 'password' => 'secret'],
				['@self' => ['id' => 'src-brp'], 'id' => 7, 'name' => 'BRP', 'apikey' => 'k'],
			],
			'app_connection' => [
				['@self' => ['id' => 'con-1'], 'app' => 'vergunningen', 'title' => 'Zaaksysteem', 'source' => 'src-brp', 'status' => 'error', 'statusMessage' => '401 Unauthorized', 'checkedAt' => '2026-11-17T08:00:00Z'],
				['@self' => ['id' => 'con-9'], 'app' => 'subsidies', 'title' => 'Other app', 'source' => 'src-kvk', 'status' => 'configured', 'checkedAt' => '2026-11-17T08:00:00Z'],
			],
		];
	}//end integriqRows()

	/**
	 * Two sources and one integration; the error and its message show, an unchecked source reads null.
	 *
	 * @return void
	 */
	public function testListsTheAppsSourcesAndIntegrationsWithTheirHealth(): void {
		$reads = [];
		$result = $this->service(installed: true, rows: self::integriqRows(), reads: $reads)->list(appSlug: 'vergunningen', manifest: self::manifest());

		self::assertFalse($result['healthNeedsIntegriq']);
		self::assertSame(
			[
				['kind' => 'source', 'id' => 'src-brp', 'name' => 'BRP', 'status' => 'error', 'statusMessage' => '401 Unauthorized', 'checkedAt' => '2026-11-17T08:00:00Z', 'linkId' => 'src-brp'],
				['kind' => 'source', 'id' => 'src-kvk', 'name' => 'KvK Handelsregister', 'status' => null, 'statusMessage' => null, 'checkedAt' => null, 'linkId' => 'src-kvk'],
				['kind' => 'integration', 'id' => 'con-1', 'name' => 'Zaaksysteem', 'status' => 'error', 'statusMessage' => '401 Unauthorized', 'checkedAt' => '2026-11-17T08:00:00Z', 'linkId' => 'con-1'],
			],
			$result['connections']
		);
		self::assertStringNotContainsString('secret', (string)json_encode($result));

		$connectionRead = array_values(array_filter($reads, static fn (array $read): bool => $read['config']['filters']['schema'] === 'app_connection'))[0];
		self::assertSame(['register' => 'integriq', 'schema' => 'app_connection', 'app' => 'vergunningen'], $connectionRead['config']['filters']);
		self::assertFalse($connectionRead['rbac']);
	}//end testListsTheAppsSourcesAndIntegrationsWithTheirHealth()

	/**
	 * A row of another app never comes back, even when the filter lets it through.
	 *
	 * @return void
	 */
	public function testRowsOfAnotherAppAreNeverReturned(): void {
		$result = $this->service(installed: true, rows: self::integriqRows())->list(appSlug: 'vergunningen', manifest: ['pages' => []]);

		self::assertSame(['con-1'], array_column($result['connections'], 'id'));
		self::assertStringNotContainsString('Other app', (string)json_encode($result));
	}//end testRowsOfAnotherAppAreNeverReturned()

	/**
	 * Without integriq the manifest's sources are listed by path, without health, and nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqTheSourcesAreListedWithoutStatus(): void {
		$reads = [];
		$result = $this->service(installed: false, reads: $reads)->list(appSlug: 'vergunningen', manifest: self::manifest());

		self::assertTrue($result['healthNeedsIntegriq']);
		self::assertSame(['brp/persons', 'kvk/companies'], array_column($result['connections'], 'name'));
		self::assertSame([null, null], array_column($result['connections'], 'status'));
		self::assertSame([], $reads);
	}//end testWithoutIntegriqTheSourcesAreListedWithoutStatus()

	/**
	 * An unknown status reads as not checked, and a path whose source is gone is listed by its path.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusAndAnUnresolvedPath(): void {
		$rows = [
			'endpoint' => [],
			'app_connection' => [['uuid' => 'con-2', 'app' => 'vergunningen', 'title' => 'Odd', 'status' => 'ok', 'statusMessage' => 'x', 'checkedAt' => '2026-11-17T08:00:00Z']],
		];
		$result = $this->service(installed: true, rows: $rows)->list(appSlug: 'vergunningen', manifest: self::manifest());

		self::assertSame(
			[['source', 'brp/persons', null], ['source', 'kvk/companies', null], ['integration', 'Odd', null]],
			array_map(static fn (array $row): array => [$row['kind'], $row['name'], $row['status']], $result['connections'])
		);
		self::assertNull($result['connections'][2]['statusMessage']);
		self::assertSame(['brp/persons', 'kvk/companies'], ApplicationConnectionsService::endpointPaths(manifest: self::manifest()));
	}//end testAnUnknownStatusAndAnUnresolvedPath()
}//end class
