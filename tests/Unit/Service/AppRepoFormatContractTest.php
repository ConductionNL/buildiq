<?php

/**
 * The github-app-repo-format contract, end to end on the serializer and parser.
 *
 * Serialising an Application with two companion schemas yields the descriptor, the
 * manifest and one schema file each, the manifest round-trips, the descriptor
 * `credentials[]` comes from the manifest and an unchanged app re-serialises byte for
 * byte. Hostile input (a deeply nested or oversized manifest, a `schemas/` entry that
 * climbs out of the folder) is refused before anything is decoded or imported. The
 * register carries the optional linkage and provenance fields, and objects without
 * them stay valid.
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
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Service\AppRepoParser;
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
 * Serializer output, hostile-input refusals and the provenance fields on the register.
 *
 * @covers \OCA\Buildiq\Service\AppRepoSerializer
 * @covers \OCA\Buildiq\Service\AppRepoParser
 * @uses   \OCA\Buildiq\Service\CompanionSchemaCollector
 * @uses   \OCA\Buildiq\Service\RepoDescriptor
 * @uses   \OCA\Buildiq\Service\TemplateRepoSerializer
 * @uses   \OCA\Buildiq\Service\AppRepoPayloadSafety
 * @uses   \OCA\Buildiq\Exception\AppRepoParseException
 */
class AppRepoFormatContractTest extends TestCase {

	/**
	 * A serializer whose per-app register `openbuild-case-desk` holds two schemas.
	 *
	 * @return AppRepoSerializer
	 */
	private function serializer(): AppRepoSerializer {
		$register = new Register();
		$register->setTitle('Case desk');
		$register->setSchemas([1, 2]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($slug) use ($register): Register {
				if ($slug === 'openbuild-case-desk') {
					return $register;
				}

				throw new RuntimeException('no register');
			}
		);

		$schema = static function (string $slug, string $title, array $properties): Schema {
			$entity = new Schema();
			$entity->setSlug($slug);
			$entity->setTitle($title);
			$entity->setDescription($title . ' records');
			$entity->setVersion('1.0.0');
			$entity->setRequired([]);
			$entity->setProperties($properties);
			return $entity;
		};
		$schemas = [
			1 => $schema('request', 'Request', ['subject' => ['type' => 'string']]),
			2 => $schema('applicant', 'Applicant', ['name' => ['type' => 'string']]),
		];
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(static fn ($id): Schema => $schemas[$id]);
		$logger = $this->createMock(LoggerInterface::class);

		return new AppRepoSerializer($registerMapper, $schemaMapper, $logger, new TemplateRepoSerializer($schemaMapper, $logger), new FakeSlugResolver(['integriq']));

	}//end serializer()

	/**
	 * The Application and the version being published.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function app(): array {
		return [
			['slug' => 'case-desk', 'name' => 'Case desk', 'description' => 'Requests at the desk', 'appType' => 'virtual', 'category' => 'cases'],
			[
				'semver' => '1.2.0',
				'manifest' => [
					'version' => '1.2.0',
					'pages' => [['id' => 'requests', 'route' => '/requests', 'type' => 'index', 'title' => 'Requests']],
					'credentials' => [['provider' => 'github', 'reason' => 'Read the issues of the case repository', 'scopes' => ['repo']]],
				],
			],
		];

	}//end app()

	/**
	 * Two companion schemas give two schema files beside the descriptor and the manifest;
	 * the manifest round-trips and the descriptor's credentials come from the manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function testAnAppSerialisesToItsRepositoryFiles(): void {
		[$application, $version] = $this->app();

		$files = $this->serializer()->serialize(application: $application, version: $version);

		self::assertSame(['openbuild-app.json', 'manifest.json', 'schemas/applicant.json', 'schemas/request.json', 'README.md'], array_keys($files));
		self::assertEquals($version['manifest'], json_decode($files['manifest.json'], true));
		self::assertSame('Applicant', json_decode($files['schemas/applicant.json'], true)['title']);

		$descriptor = json_decode($files['openbuild-app.json'], true);
		self::assertSame('case-desk', $descriptor['slug']);
		self::assertSame('1.2.0', $descriptor['version']);
		self::assertSame('cases', $descriptor['category']);
		self::assertSame([['provider' => 'github', 'reason' => 'Read the issues of the case repository', 'scopes' => ['repo']]], $descriptor['credentials']);
		self::assertStringContainsString('Built with [Buildiq]', $files['README.md']);

	}//end testAnAppSerialisesToItsRepositoryFiles()

	/**
	 * An unchanged app re-serialises byte for byte, every JSON file with a trailing newline,
	 * and the serialised repository parses back to the same slug, version and schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function testSerialisingIsByteStableAndParsesBack(): void {
		[$application, $version] = $this->app();
		$serializer = $this->serializer();

		$first = $serializer->serialize(application: $application, version: $version);
		self::assertSame($first, $serializer->serialize(application: $application, version: $version));
		foreach ($first as $path => $contents) {
			self::assertStringEndsWith("\n", $contents, $path);
		}

		$payload = (new AppRepoParser())->parse(files: $first, repo: ['owner' => 'acme', 'name' => 'case-desk']);
		self::assertSame('case-desk', $payload['slug']);
		self::assertSame('1.2.0', $payload['version']);
		self::assertSame(['applicant', 'request'], array_values(array_map(static fn (array $schema): string => (string)$schema['slug'], $payload['companionSchemas'])));
		self::assertSame('github', $payload['templateOrigin']['source']);

	}//end testSerialisingIsByteStableAndParsesBack()

	/**
	 * Hostile input is refused with a stable code, blamed on the right file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function testHostileInputIsRefused(): void {
		$valid = [
			'openbuild-app.json' => (string)json_encode(['formatVersion' => '1.0', 'appType' => 'virtual', 'slug' => 'case-desk', 'name' => 'Case desk', 'version' => '1.2.0']),
			'manifest.json' => (string)json_encode(['version' => '1.0.0', 'pages' => []]),
		];
		$deep = str_repeat('{"a":', (AppRepoParser::MAX_JSON_DEPTH + 1)) . '1' . str_repeat('}', (AppRepoParser::MAX_JSON_DEPTH + 1));
		$cases = [
			'manifest nested too deep' => [['manifest.json' => $deep], 'manifest_unparseable', 'manifest.json'],
			'manifest too large' => [['manifest.json' => '{"version":"1.0.0","pages":[],"note":"' . str_repeat('x', AppRepoParser::MAX_FILE_BYTES) . '"}'], 'manifest_unparseable', 'manifest.json'],
			'schema climbing out' => [['schemas/../evil.json' => '{"type":"object","properties":{}}'], 'schema_invalid', 'schemas/../evil.json'],
		];

		foreach ($cases as $name => [$override, $code, $path]) {
			try {
				(new AppRepoParser())->parse(files: array_merge($valid, $override));
				self::fail($name . ' was accepted');
			} catch (AppRepoParseException $e) {
				self::assertSame($code, $e->getErrorCode(), $name);
				self::assertSame($path, $e->getFilePath(), $name);
			}
		}

	}//end testHostileInputIsRefused()

	/**
	 * The register carries the optional linkage and provenance fields, never required,
	 * so an Application or version saved before them stays valid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/openbuild-application-register/spec.md
	 * @spec openspec/changes/github-app-repo-format/specs/application-versions/spec.md
	 */
	public function testTheRegisterCarriesOptionalProvenance(): void {
		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/openbuild_register.json'), true);
		$schemas = $register['components']['schemas'];

		$application = $schemas['Application'];
		self::assertSame('object', $application['properties']['githubRepo']['type']);
		self::assertFalse($application['properties']['githubRepo']['additionalProperties']);
		self::assertSame(['name', 'owner'], array_values(array_intersect(['name', 'owner'], array_keys($application['properties']['githubRepo']['properties']))));
		self::assertSame('string', $application['properties']['githubDefaultBranch']['type']);
		self::assertSame([], array_intersect(['githubRepo', 'githubDefaultBranch'], (array)($application['required'] ?? [])));

		$version = $schemas['ApplicationVersion'];
		self::assertSame('string', $version['properties']['commitSha']['type']);
		self::assertSame('string', $version['properties']['sourceRef']['type']);
		self::assertSame([], array_intersect(['commitSha', 'sourceRef'], (array)($version['required'] ?? [])));

	}//end testTheRegisterCarriesOptionalProvenance()
}//end class
