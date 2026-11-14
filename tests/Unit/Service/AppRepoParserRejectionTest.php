<?php

/**
 * Unit tests for the rejection paths of AppRepoParser.
 *
 * An app repository is untrusted input: every malformed descriptor, manifest
 * or companion schema must be refused with a stable error code and the path
 * of the offending file, before anything is stored. The golden test covers
 * the accepted shapes; this one covers the refusals.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Service\AppRepoParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AppRepoParser::parse refusals.
 */
class AppRepoParserRejectionTest extends TestCase {

	/**
	 * A valid v1 repository: descriptor plus manifest.
	 *
	 * @return array<string, string>
	 */
	private static function validFiles(): array {
		return [
			'openbuild-app.json' => (string)json_encode(
				[
					'formatVersion' => '1.0',
					'appType' => 'virtual',
					'slug' => 'case-desk',
					'name' => 'Case desk',
					'version' => '1.2.0',
				]
			),
			'manifest.json' => (string)json_encode(['version' => '1.0.0', 'pages' => []]),
		];
	}//end validFiles()

	/**
	 * Malformed repositories, the error code and the file each must be blamed on.
	 *
	 * @return array<string, array{0: array<string, string|null>, 1: string, 2: string}>
	 */
	public static function malformed(): array {
		$descriptor = static fn (array $fields): string => (string)json_encode(
			$fields + ['formatVersion' => '1.0', 'appType' => 'virtual', 'slug' => 'case-desk']
		);

		return [
			'descriptor not JSON' => [['openbuild-app.json' => '{nope'], 'descriptor_unparseable', 'openbuild-app.json'],
			'descriptor is a list' => [['openbuild-app.json' => '[1,2]'], 'descriptor_unparseable', 'openbuild-app.json'],
			'descriptor too large' => [
				['openbuild-app.json' => str_repeat(' ', AppRepoParser::MAX_FILE_BYTES + 1)],
				'descriptor_unparseable',
				'openbuild-app.json',
			],
			'unknown app type' => [
				['openbuild-app.json' => $descriptor(['appType' => 'native'])],
				'app_type_unknown',
				'openbuild-app.json',
			],
			'unsupported format' => [
				['openbuild-app.json' => $descriptor(['formatVersion' => '3.0'])],
				'format_version_unsupported',
				'openbuild-app.json',
			],
			'format without a major' => [
				['openbuild-app.json' => $descriptor(['formatVersion' => 'latest'])],
				'format_version_unsupported',
				'openbuild-app.json',
			],
			'slug with a path' => [
				['openbuild-app.json' => $descriptor(['slug' => '../etc'])],
				'app_type_unknown',
				'openbuild-app.json',
			],
			'manifest missing' => [['manifest.json' => null], 'manifest_missing', 'manifest.json'],
			'manifest empty' => [['manifest.json' => '{}'], 'manifest_unparseable', 'manifest.json'],
			'manifest without version' => [['manifest.json' => '{"pages": []}'], 'manifest_invalid', 'manifest.json'],
			'manifest without pages' => [['manifest.json' => '{"version": "1.0.0"}'], 'manifest_invalid', 'manifest.json'],
			'schema not JSON' => [['schemas/person.json' => 'person'], 'schema_unparseable', 'schemas/person.json'],
			'schema without properties' => [['schemas/person.json' => '{"title": "Person"}'], 'schema_invalid', 'schemas/person.json'],
			'schema with a bad file name' => [
				['schemas/Person_Record.json' => '{"properties": {}}'],
				'schema_invalid',
				'schemas/Person_Record.json',
			],
			'schema in a sub folder' => [
				['schemas/nested/person.json' => '{"properties": {}}'],
				'schema_invalid',
				'schemas/nested/person.json',
			],
			'schema with a bad declared slug' => [
				['schemas/person.json' => '{"slug": "Person!", "properties": {}}'],
				'schema_invalid',
				'schemas/person.json',
			],
		];
	}//end malformed()

	/**
	 * Each malformed repository is refused with its code and the offending file.
	 *
	 * @param array<string, string|null> $override Files to replace (null removes one).
	 * @param string $code The expected error code.
	 * @param string $path The file the error must name.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testRefusesAMalformedRepository(array $override, string $code, string $path): void {
		$files = array_filter(
			array_merge(self::validFiles(), $override),
			static fn (?string $contents): bool => $contents !== null
		);

		try {
			(new AppRepoParser())->parse(files: $files);
			self::fail(message: 'Expected the repository to be refused with ' . $code);
		} catch (AppRepoParseException $e) {
			self::assertSame(expected: $code, actual: $e->getErrorCode());
			self::assertSame(expected: $path, actual: $e->getFilePath());
		}
	}//end testRefusesAMalformedRepository()

	/**
	 * Two companion schemas cannot claim the same slug.
	 *
	 * @return void
	 */
	public function testRefusesTwoSchemasWithOneSlug(): void {
		$files = self::validFiles() + [
			'schemas/person.json' => '{"properties": {}}',
			'schemas/contact.json' => '{"slug": "person", "properties": {}}',
		];

		$this->expectException(exception: AppRepoParseException::class);

		(new AppRepoParser())->parse(files: $files);
	}//end testRefusesTwoSchemasWithOneSlug()

	/**
	 * A repo descriptor without owner or name records no repo in the template origin.
	 *
	 * @return void
	 */
	public function testTemplateOriginWithoutAnOwner(): void {
		$parsed = (new AppRepoParser())->parse(files: self::validFiles(), repo: ['owner' => '', 'name' => 'case-desk']);

		self::assertSame(
			expected: ['source' => 'github', 'repo' => null, 'version' => '1.2.0'],
			actual: $parsed['templateOrigin']
		);
		self::assertSame(expected: 'Case desk', actual: $parsed['title']);
		self::assertArrayNotHasKey(key: 'channels', array: $parsed);
	}//end testTemplateOriginWithoutAnOwner()
}//end class
