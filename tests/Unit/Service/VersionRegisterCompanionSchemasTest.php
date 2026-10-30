<?php

/**
 * Publishing reads the register the version names, not one built from the slug.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\TemplateRepoSerializer;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A published repository carries the app's schemas.
 *
 * ## The defect
 *
 * The creation wizard gives every version its own register, named
 * `openbuild-{slug}-{version}`, and writes that name on the ApplicationVersion
 * record. The serialiser asked for `openbuild-{slug}`, which exists for no
 * wizard-made app. The mapper threw, the collector returned no companions, and
 * the publish succeeded: a repository with no `schemas/` directory and a
 * descriptor reading `"schemas": 0`. Nothing failed, so nothing was noticed
 * until someone installed the published app and found it had no data model.
 *
 * ## Watched failing
 *
 * With `collectCompanionSchemas()` reverted to `find('openbuild-' . $slug)`,
 * `testTheVersionsRegisterIsTheOneRead` fails with "Failed asserting that two
 * strings are identical. -'openbuild-permits-production' +'openbuild-permits'"
 * and `testTheSchemasOfAVersionedRegisterArePublished` fails with "Failed
 * asserting that an array has the key 'schemas/permit.json'" — the defect
 * itself, the schema absent from the published repository.
 * `testAVersionWithoutARegisterFallsBackToTheSlug` passes either way, which is
 * why the old behaviour looked correct: for a record carrying no `register`
 * the derived name is still the right answer.
 *
 * @covers \OCA\Buildiq\Service\AppRepoSerializer
 *
 * @uses \OCA\Buildiq\Service\AppRepoPayloadSafety
 * @uses \OCA\Buildiq\Service\CompanionSchemaCollector
 * @uses \OCA\Buildiq\Service\TemplateRepoSerializer
 * @uses \OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver
 */
final class VersionRegisterCompanionSchemasTest extends TestCase {

	/**
	 * Every register slug the serialiser asked the mapper for, in order.
	 *
	 * @var list<string>
	 */
	private array $asked = [];

	/**
	 * Serialise one app + version against an instance carrying one register.
	 *
	 * @param string $presentRegister The single register slug this instance has.
	 * @param array<string,mixed> $version The ApplicationVersion record to publish.
	 *
	 * @return array<string,string> The emitted `path => contents` file map.
	 */
	private function serialize(string $presentRegister, array $version): array {
		$this->asked = [];

		$schema = new Schema();
		$schema->setSlug('permit');
		$schema->setTitle('Permit');
		$schema->setDescription('A building permit');
		$schema->setVersion('1.0.0');
		$schema->setRequired(['reference']);
		$schema->setProperties(['reference' => ['type' => 'string']]);

		$register = new Register();
		$register->setSlug($presentRegister);
		$register->setSchemas([7]);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function (mixed $slug) use ($presentRegister, $register): Register {
				$this->asked[] = (string)$slug;
				if ((string)$slug !== $presentRegister) {
					throw new RuntimeException('register not found');
				}

				return $register;
			}
		);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$serializer = new AppRepoSerializer(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $logger,
			templateSerializer: new TemplateRepoSerializer(
				schemaMapper: $schemaMapper,
				logger: $logger
			),
			slugResolver: new FakeSlugResolver(present: ['buildiq'])
		);

		return $serializer->serialize(
			application: [
				'slug' => 'permits',
				'name' => 'Permits',
				'description' => 'A demo',
				'appType' => 'virtual',
			],
			version: $version
		);
	}//end serialize()

	/**
	 * The register asked for is the one the version names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function testTheVersionsRegisterIsTheOneRead(): void {
		$this->serialize(
			presentRegister: 'openbuild-permits-production',
			version: ['manifest' => [], 'register' => 'openbuild-permits-production']
		);

		$this->assertSame(
			expected: 'openbuild-permits-production',
			actual: $this->asked[0] ?? '',
			message: 'The first register read must name the register the version carries.'
		);
	}//end testTheVersionsRegisterIsTheOneRead()

	/**
	 * The schemas of a versioned register reach the published repository.
	 *
	 * Asserting the slug alone would not be enough: the defect is that a
	 * published app silently arrived without its data model.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function testTheSchemasOfAVersionedRegisterArePublished(): void {
		$files = $this->serialize(
			presentRegister: 'openbuild-permits-production',
			version: ['manifest' => [], 'register' => 'openbuild-permits-production']
		);

		$this->assertArrayHasKey(
			key: 'schemas/permit.json',
			array: $files,
			message: 'The version register\'s schema must be published, not silently dropped.'
		);

		$descriptor = json_decode($files['openbuild-app.json'], true);
		$this->assertSame(
			expected: 1,
			actual: $descriptor['channels']['schemas'],
			message: 'The descriptor must count the schema it published.'
		);
	}//end testTheSchemasOfAVersionedRegisterArePublished()

	/**
	 * A version record with no register still resolves `openbuild-{slug}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function testAVersionWithoutARegisterFallsBackToTheSlug(): void {
		$files = $this->serialize(
			presentRegister: 'openbuild-permits',
			version: ['manifest' => []]
		);

		$this->assertSame(
			expected: ['openbuild-permits'],
			actual: $this->asked,
			message: 'Without a register on the version, the app slug is the only candidate.'
		);
		$this->assertArrayHasKey(key: 'schemas/permit.json', array: $files);
	}//end testAVersionWithoutARegisterFallsBackToTheSlug()
}//end class
