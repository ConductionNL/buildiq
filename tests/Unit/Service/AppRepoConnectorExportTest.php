<?php

/**
 * Unit tests for the connectors channel of AppRepoSerializer.
 *
 * Pins what a declared connector binding exports with a live ObjectService:
 * the files written, the one level of direct references a synchronization
 * pulls in, the dedupe on each object's own identity, the stripped-secret,
 * declared, resolved and missing counts, and the warnings logged on the way.
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
 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\TemplateRepoSerializer;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The connectors channel of a serialised application repository.
 */
class AppRepoConnectorExportTest extends TestCase {

	private const SYNC_UUID = '11111111-1111-4111-8111-111111111111';
	private const SOURCE_UUID = '22222222-2222-4222-8222-222222222222';
	private const SOURCE_ALIAS = '33333333-3333-4333-8333-333333333333';
	private const MAPPING_UUID = '44444444-4444-4444-8444-444444444444';
	private const JOB_UUID = '55555555-5555-4555-8555-555555555555';
	private const OTHER_SOURCE = '66666666-6666-4666-8666-666666666666';

	/**
	 * Warning messages the serializer logged, in order.
	 *
	 * @var array<int,string>
	 */
	private array $warnings = [];

	/**
	 * The ids the serializer looked up, in order.
	 *
	 * @var array<int,string>
	 */
	private array $lookups = [];

	/**
	 * Every connector file, every count and every warning of one export.
	 *
	 * @return void
	 */
	public function testDeclaredConnectorsExportWithTheirDirectReferences(): void {
		$files = $this->serializer()->serialize(application: $this->application(), version: ['semver' => '1.0.0', 'manifest' => []]);

		$connectorFiles = [];
		foreach ($files as $path => $contents) {
			if (str_starts_with($path, 'connectors/') === true) {
				$connectorFiles[$path] = json_decode($contents, true);
			}
		}

		$this->assertSame(
			[
				'connectors/source/other-source.json' => ['slug' => 'other-source'],
				'connectors/source/tenderned.json' => ['apiKey' => '', 'slug' => 'Tender Ned'],
				'connectors/synchronization/ted-sync.json' => [
					'password' => '',
					'slug' => 'ted-sync',
					'sourceHashMapping' => '../escape',
					'sourceId' => 'tenderned',
					'sourceTargetMapping' => self::MAPPING_UUID,
				],
			],
			$connectorFiles
		);

		$descriptor = json_decode($files['openbuild-app.json'], true);
		$this->assertSame(
			['declared' => 2, 'missing' => 1, 'resolved' => 1, 'stripped' => 2],
			$descriptor['channels']['connectors']
		);

		$this->assertSame(
			[
				'Buildiq AppRepoSerializer: declared connector "mapping/' . self::MAPPING_UUID . '" does not exist. []',
				'Buildiq AppRepoSerializer: declared connector "job/' . self::JOB_UUID . '" does not exist. []',
				'Buildiq AppRepoSerializer: rejected unsafe connector binding. {"kind":"endpoint","uuid":"' . self::OTHER_SOURCE . '"}',
			],
			$this->warnings
		);

		$this->assertSame(
			[self::SYNC_UUID, 'tenderned', self::MAPPING_UUID, self::SOURCE_ALIAS, self::JOB_UUID, self::SYNC_UUID, self::OTHER_SOURCE],
			$this->lookups
		);

	}//end testDeclaredConnectorsExportWithTheirDirectReferences()

	/**
	 * An application declaring a synchronization (whose source is referenced by
	 * slug and whose mapping is missing), an alias of that source, a missing job,
	 * an unsafe binding, a non-array binding, a duplicate and a second source.
	 *
	 * @return array<string,mixed>
	 */
	private function application(): array {
		return [
			'slug' => 'demo-app',
			'name' => 'Demo App',
			'description' => 'A demo',
			'appType' => 'virtual',
			'connectors' => [
				['kind' => 'synchronization', 'uuid' => self::SYNC_UUID],
				['kind' => 'source', 'uuid' => self::SOURCE_ALIAS],
				['kind' => 'job', 'uuid' => self::JOB_UUID],
				['kind' => 'endpoint', 'uuid' => self::OTHER_SOURCE],
				'not-a-binding',
				['kind' => 'synchronization', 'uuid' => self::SYNC_UUID],
				['kind' => 'source', 'uuid' => self::OTHER_SOURCE, 'slug' => 'other-source'],
			],
		];

	}//end application()

	/**
	 * A serializer reading connector objects from an in-memory register.
	 *
	 * @return AppRepoSerializer
	 */
	private function serializer(): AppRepoSerializer {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new \RuntimeException('no register'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = $message . ' ' . json_encode($context);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args): ?ObjectEntityInterface {
				$this->lookups[] = (string)$args[0];
				return $this->entity(id: (string)$args[0]);
			}
		);

		return new AppRepoSerializer(
			$registerMapper,
			$schemaMapper,
			$logger,
			new TemplateRepoSerializer($schemaMapper, $logger),
			new FakeSlugResolver(['integriq', 'buildiq']),
			$objectService
		);

	}//end serializer()

	/**
	 * The stored object a reference resolves to, or null when none exists.
	 *
	 * @param string $id A UUID or slug.
	 *
	 * @return ObjectEntityInterface|null
	 */
	private function entity(string $id): ?ObjectEntityInterface {
		$objects = [
			self::SYNC_UUID => [self::SYNC_UUID, ['slug' => 'ted-sync', 'sourceId' => 'tenderned', 'sourceTargetMapping' => self::MAPPING_UUID, 'sourceHashMapping' => '../escape', 'password' => 'hunter2']],
			'tenderned' => [self::SOURCE_UUID, ['slug' => 'Tender Ned', 'apiKey' => 'secret']],
			self::SOURCE_ALIAS => [self::SOURCE_UUID, ['slug' => 'Tender Ned', 'apiKey' => 'secret']],
			self::OTHER_SOURCE => [self::OTHER_SOURCE, ['slug' => 'other-source']],
		];
		if (isset($objects[$id]) === false) {
			return null;
		}

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn($objects[$id][0]);
		$entity->method('getObject')->willReturn($objects[$id][1]);
		return $entity;

	}//end entity()
}//end class
