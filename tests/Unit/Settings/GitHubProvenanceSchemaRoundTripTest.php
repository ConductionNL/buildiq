<?php

/**
 * Schema round-trip test for the GitHub linkage and provenance fields.
 *
 * Covers github-app-repo-format task 6.5 (REQ-OBA-010, REQ-OBV-112): the
 * Application schema declares the optional `githubRepo` and
 * `githubDefaultBranch` properties, the ApplicationVersion schema declares
 * the optional `commitSha` and `sourceRef` properties, a provenance-only save
 * passes through the version save hook without bumping `semver`, and legacy
 * objects without the fields stay valid against the schemas.
 *
 * The live OpenRegister save path needs a booted Nextcloud; this test asserts
 * the declared schema contract and the save hook that both feed it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-app-repo-format/tasks.md#task-6.5
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the GitHub provenance fields of the Application and ApplicationVersion schemas.
 *
 * @covers \OCA\Buildiq\Service\ApplicationVersionService
 */
class GitHubProvenanceSchemaRoundTripTest extends TestCase {

	/**
	 * Decoded `components.schemas` of the register seed.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $schemas = null;

	/**
	 * Load the schemas of the canonical register seed.
	 *
	 * @return array<string, mixed>
	 */
	private function schemas(): array {
		if (self::$schemas === null) {
			$path = __DIR__ . '/../../../lib/Settings/openbuild_register.json';
			self::assertFileExists($path);
			$decoded = json_decode((string)file_get_contents($path), true);
			self::assertIsArray($decoded);
			self::$schemas = $decoded['components']['schemas'];
		}

		return self::$schemas;
	}//end schemas()

	/**
	 * Validate an object against a schema's `required` list and the declared
	 * types of the properties it carries (one level of object nesting).
	 *
	 * @param array<string, mixed> $schema The schema block
	 * @param array<string, mixed> $object The candidate object
	 *
	 * @return list<string> Violations; empty when valid
	 */
	private function violations(array $schema, array $object): array {
		$errors = [];
		foreach ($schema['required'] ?? [] as $key) {
			if (array_key_exists($key, $object) === false) {
				$errors[] = 'missing ' . $key;
			}
		}

		foreach ($object as $key => $value) {
			$property = $schema['properties'][$key] ?? null;
			if ($property === null) {
				continue;
			}

			if (($property['type'] ?? null) === 'string' && is_string($value) === false) {
				$errors[] = $key . ' not a string';
			}

			if (($property['type'] ?? null) === 'object') {
				if (is_array($value) === false) {
					$errors[] = $key . ' not an object';
					continue;
				}

				$errors = array_merge($errors, array_map(
					static fn (string $e): string => $key . '.' . $e,
					$this->violations(schema: $property, object: $value)
				));
				if (($property['additionalProperties'] ?? true) === false) {
					foreach (array_keys($value) as $sub) {
						if (isset($property['properties'][$sub]) === false) {
							$errors[] = $key . '.' . $sub . ' not allowed';
						}
					}
				}
			}
		}//end foreach

		return $errors;
	}//end violations()

	/**
	 * Build the version service with every collaborator mocked.
	 *
	 * @return ApplicationVersionService
	 */
	private function versionService(): ApplicationVersionService {
		return new ApplicationVersionService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
			registerService: $this->createMock(RegisterService::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			automationCompiler: $this->createMock(AutomationCompilerService::class),
		);
	}//end versionService()

	/**
	 * The Application linkage fields are declared, typed and optional.
	 *
	 * @return void
	 */
	public function testApplicationDeclaresOptionalLinkageFields(): void {
		$schema = $this->schemas()['Application'];

		self::assertSame('object', $schema['properties']['githubRepo']['type']);
		self::assertSame(['owner', 'name'], $schema['properties']['githubRepo']['required']);
		self::assertFalse($schema['properties']['githubRepo']['additionalProperties']);
		self::assertSame('string', $schema['properties']['githubDefaultBranch']['type']);
		self::assertNotContains('githubRepo', $schema['required']);
		self::assertNotContains('githubDefaultBranch', $schema['required']);
	}//end testApplicationDeclaresOptionalLinkageFields()

	/**
	 * The ApplicationVersion provenance fields are declared, typed and optional.
	 *
	 * @return void
	 */
	public function testApplicationVersionDeclaresOptionalProvenanceFields(): void {
		$schema = $this->schemas()['ApplicationVersion'];

		self::assertSame('string', $schema['properties']['commitSha']['type']);
		self::assertSame('string', $schema['properties']['sourceRef']['type']);
		self::assertNotContains('commitSha', $schema['required']);
		self::assertNotContains('sourceRef', $schema['required']);
	}//end testApplicationVersionDeclaresOptionalProvenanceFields()

	/**
	 * A linked Application validates; a malformed githubRepo is refused.
	 *
	 * @return void
	 */
	public function testLinkedApplicationRoundTripsAndMalformedRepoIsRefused(): void {
		$schema = $this->schemas()['Application'];
		$linked = [
			'slug'                => 'hello-world',
			'name'                => 'Hello World',
			'githubRepo'          => ['owner' => 'acme', 'name' => 'hello-world'],
			'githubDefaultBranch' => 'main',
		];

		$roundTripped = json_decode(json_encode($linked, JSON_THROW_ON_ERROR), true);
		self::assertSame($linked, $roundTripped);
		self::assertSame([], $this->violations(schema: $schema, object: $roundTripped));

		$missingName = ['githubRepo' => ['owner' => 'acme']] + $linked;
		self::assertSame(['githubRepo.missing name'], $this->violations(schema: $schema, object: $missingName));

		$extraKey = ['githubRepo' => ['owner' => 'acme', 'name' => 'x', 'url' => 'y']] + $linked;
		self::assertSame(['githubRepo.url not allowed'], $this->violations(schema: $schema, object: $extraKey));
	}//end testLinkedApplicationRoundTripsAndMalformedRepoIsRefused()

	/**
	 * Legacy objects without the new fields stay valid.
	 *
	 * @return void
	 */
	public function testLegacyObjectsWithoutFieldsStayValid(): void {
		$schemas = $this->schemas();
		$legacyApp = ['slug' => 'hello-world', 'name' => 'Hello World'];
		$legacyVersion = [
			'name'        => 'Hello World v0.1.0',
			'slug'        => 'hello-world-v0-1-0',
			'manifest'    => ['version' => '1.0.0'],
			'register'    => 'hello-world',
			'semver'      => '0.1.0',
			'status'      => 'draft',
			'application' => 'hello-world',
		];

		self::assertSame([], $this->violations(schema: $schemas['Application'], object: $legacyApp));
		self::assertSame([], $this->violations(schema: $schemas['ApplicationVersion'], object: $legacyVersion));
	}//end testLegacyObjectsWithoutFieldsStayValid()

	/**
	 * A provenance-only save keeps the fields and does not bump semver.
	 *
	 * @return void
	 */
	public function testProvenanceOnlySaveDoesNotBumpSemver(): void {
		$service = $this->versionService();
		$manifest = ['version' => '1.0.0', 'pages' => [['id' => 'home']]];
		$current = [
			'semver'       => '0.3.1',
			'manifest'     => $manifest,
			'manifestHash' => $service->hashManifest(manifest: $manifest),
		];
		$next = $current + ['commitSha' => 'c0ffee1234', 'sourceRef' => 'refs/heads/main'];

		$saved = $service->onSave(current: $current, next: $next);

		self::assertSame('0.3.1', $saved['semver']);
		self::assertSame('c0ffee1234', $saved['commitSha']);
		self::assertSame('refs/heads/main', $saved['sourceRef']);
		self::assertSame([], $this->violations(
			schema: $this->schemas()['ApplicationVersion'],
			object: ['commitSha' => $saved['commitSha'], 'sourceRef' => $saved['sourceRef']] + [
				'name' => 'v', 'slug' => 'v', 'manifest' => $manifest, 'register' => 'r',
				'semver' => $saved['semver'], 'status' => 'draft', 'application' => 'a',
			]
		));
	}//end testProvenanceOnlySaveDoesNotBumpSemver()

	/**
	 * A manifest change still bumps semver when provenance is set too.
	 *
	 * @return void
	 */
	public function testManifestChangeStillBumpsWithProvenance(): void {
		$service = $this->versionService();
		$manifest = ['version' => '1.0.0'];
		$current = [
			'semver'       => '0.3.1',
			'manifest'     => $manifest,
			'manifestHash' => $service->hashManifest(manifest: $manifest),
		];
		$next = ['manifest' => ['version' => '1.0.1'], 'commitSha' => 'abc'] + $current;

		$saved = $service->onSave(current: $current, next: $next);

		self::assertSame('0.3.2', $saved['semver']);
		self::assertSame('abc', $saved['commitSha']);
	}//end testManifestChangeStillBumpsWithProvenance()
}//end class
