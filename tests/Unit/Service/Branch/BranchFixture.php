<?php

/**
 * Shared fakes for the version branch and merge tests.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Branch;

use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * In-memory registers and schemas, keyed by slug, behind mocked mappers.
 */
final class BranchFixture {

	/**
	 * Registers by slug.
	 *
	 * @var array<string,Register>
	 */
	public array $registers = [];

	/**
	 * Schemas by id.
	 *
	 * @var array<int,Schema>
	 */
	public array $schemas = [];

	/**
	 * The next schema id.
	 *
	 * @var int
	 */
	private int $nextId = 100;

	/**
	 * Add a register holding schemas.
	 *
	 * @param string                               $slug    The register.
	 * @param array<string,array<string,mixed>>    $schemas Schema slug => properties.
	 *
	 * @return void
	 */
	public function register(string $slug, array $schemas = []): void {
		$register = new Register();
		$register->setSlug($slug);
		$ids = [];
		foreach ($schemas as $schemaSlug => $properties) {
			$ids[] = $this->schema(definition: ['slug' => $schemaSlug, 'title' => 'Permit', 'properties' => $properties])->getId();
		}

		$register->setSchemas($ids);
		$this->registers[$slug] = $register;
	}//end register()

	/**
	 * Create a schema.
	 *
	 * @param array<string,mixed> $definition Slug, title, properties, required.
	 *
	 * @return Schema
	 */
	public function schema(array $definition): Schema {
		$schema = new Schema();
		$schema->setId($this->nextId++);
		$schema->setSlug((string)$definition['slug']);
		$schema->setTitle(($definition['title'] ?? null));
		$schema->setProperties(($definition['properties'] ?? []));
		$schema->setRequired(($definition['required'] ?? []));
		$this->schemas[$schema->getId()] = $schema;

		return $schema;
	}//end schema()

	/**
	 * Point a register mapper mock at the in-memory registers.
	 *
	 * @param RegisterMapper&MockObject $mapper The mock.
	 *
	 * @return RegisterMapper
	 */
	public function registerMapper(RegisterMapper&MockObject $mapper): RegisterMapper {
		$mapper->method('find')->willReturnCallback(
			function (string|int $slug): Register {
				if (isset($this->registers[(string)$slug]) === false) {
					throw new DoesNotExistException('no register ' . $slug);
				}

				return $this->registers[(string)$slug];
			}
		);
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $fields): Register {
				$this->register(slug: (string)$fields['slug']);
				return $this->registers[(string)$fields['slug']];
			}
		);
		$mapper->method('update')->willReturnArgument(0);

		return $mapper;
	}//end registerMapper()

	/**
	 * Point a schema mapper mock at the in-memory schemas.
	 *
	 * @param SchemaMapper&MockObject $mapper The mock.
	 *
	 * @return SchemaMapper
	 */
	public function schemaMapper(SchemaMapper&MockObject $mapper): SchemaMapper {
		$mapper->method('find')->willReturnCallback(
			function (string|int $key): Schema {
				foreach ($this->schemas as $schema) {
					if ((string)$schema->getId() === (string)$key || $schema->getSlug() === (string)$key) {
						return $schema;
					}
				}

				throw new DoesNotExistException('no schema ' . $key);
			}
		);
		$mapper->method('createFromArray')->willReturnCallback(fn (array $definition): Schema => $this->schema(definition: $definition));
		$mapper->method('update')->willReturnArgument(0);

		return $mapper;
	}//end schemaMapper()

	/**
	 * The real version service: hashes manifests and stamps the semver.
	 *
	 * @param callable(string): object $mock The test's createMock.
	 *
	 * @return ApplicationVersionService
	 */
	public static function versions(callable $mock): ApplicationVersionService {
		return new ApplicationVersionService(
			new NullLogger(),
			$mock(ObjectServiceInterface::class),
			$mock(RegisterService::class),
			$mock(RegisterMapper::class),
			$mock(AutomationCompilerService::class)
		);
	}//end versions()
}//end class
