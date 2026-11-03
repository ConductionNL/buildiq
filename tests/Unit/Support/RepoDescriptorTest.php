<?php

/**
 * CanonicalJson and RepoDescriptor answer what the serialisers' own copies answered before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\CanonicalJson;
use OCA\Buildiq\Support\RepoDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixture holds the answers of the former private copies
 * (TemplateRepoSerializer sortKeysRecursive() and resolveCategory(),
 * LayoutDeltaService normalise(), and TemplateRepoSerializer deriveCredentials()
 * in its own fixture), recorded on the old code.
 *
 * @covers \OCA\Buildiq\Support\CanonicalJson
 * @covers \OCA\Buildiq\Support\RepoDescriptor
 */
class RepoDescriptorTest extends TestCase {

	/**
	 * The recorded answers, keyed by former copy.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function golden(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/repo-descriptor-golden.json'), true);
	}//end golden()

	/**
	 * The former sort copies.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function sortCopies(): array {
		return [
			'TemplateRepoSerializer' => ['OCA\Buildiq\Service\TemplateRepoSerializer::sortKeysRecursive'],
			'LayoutDeltaService' => ['OCA\Buildiq\Service\LayoutDeltaService::normalise'],
		];
	}//end sortCopies()

	/**
	 * The former category copies.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function categoryCopies(): array {
		return [
			'TemplateRepoSerializer' => ['OCA\Buildiq\Service\TemplateRepoSerializer::resolveCategory'],
		];
	}//end categoryCopies()

	/**
	 * sortKeys() serialises every input to the recorded bytes.
	 *
	 * @param string $copy The former copy, as `Class::method`.
	 *
	 * @return void
	 */
	#[DataProvider('sortCopies')]
	public function testSortKeysAnswersAsTheFormerCopy(string $copy): void {
		$golden = self::golden()[$copy];
		$this->assertSame(expected: array_keys(self::sortInputs()), actual: array_keys($golden));
		foreach (self::sortInputs() as $name => $input) {
			$this->assertSame(expected: $golden[$name], actual: json_encode(CanonicalJson::sortKeys(value: $input)), message: $name);
		}
	}//end testSortKeysAnswersAsTheFormerCopy()

	/**
	 * category() answers every record and manifest pair as recorded.
	 *
	 * @param string $copy The former copy, as `Class::method`.
	 *
	 * @return void
	 */
	#[DataProvider('categoryCopies')]
	public function testCategoryAnswersAsTheFormerCopy(string $copy): void {
		$golden = self::golden()[$copy];
		$this->assertSame(expected: array_keys(self::categoryInputs()), actual: array_keys($golden));
		foreach (self::categoryInputs() as $name => [$record, $manifest]) {
			$this->assertSame(expected: $golden[$name], actual: RepoDescriptor::category(record: $record, manifest: $manifest), message: $name);
		}
	}//end testCategoryAnswersAsTheFormerCopy()

	/**
	 * credentials() derives every recorded manifest's list as before.
	 *
	 * @return void
	 */
	public function testCredentialsAnswerAsTheFormerCopy(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/repo-descriptor-credentials-golden.json'), true);
		$golden = $golden['OCA\Buildiq\Service\TemplateRepoSerializer::deriveCredentials'];
		$this->assertSame(expected: array_keys(self::credentialInputs()), actual: array_keys($golden));
		foreach (self::credentialInputs() as $name => $manifest) {
			$this->assertSame(expected: $golden[$name], actual: RepoDescriptor::credentials(manifest: $manifest), message: $name);
		}
	}//end testCredentialsAnswerAsTheFormerCopy()

	/**
	 * The recorded manifests.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function credentialInputs(): array {
		return [
			'none' => [],
			'notList' => ['credentials' => 'x'],
			'mixed' => [
				'credentials' => [
					['provider' => 'github', 'reason' => 'Push', 'scopes' => ['repo', 7]],
					['reason' => 'no provider'],
					'text',
					null,
					['provider' => 5, 'scopes' => 'repo'],
					['provider' => 'mail', 'scopes' => ['a' => 'x', 'b' => 'y']],
					['provider' => null],
				],
			],
		];
	}//end credentialInputs()

	/**
	 * The recorded structures.
	 *
	 * @return array<string,mixed>
	 */
	private static function sortInputs(): array {
		return [
			'scalar' => 'x',
			'null' => null,
			'int' => 3,
			'empty' => [],
			'list' => [3, 1, 2],
			'map' => ['b' => 1, 'a' => 2, 'c' => ['z' => 1, 'y' => [2, ['q' => 1, 'p' => 2]]]],
			'mixedKeys' => [10 => 'ten', 'b' => 'bee', 2 => 'two', 'a' => 'ay', '1' => 'one'],
			'gappedList' => [1 => 'a', 0 => 'b'],
			'nestedLists' => [[['b' => 1, 'a' => 2]], []],
			'unicode' => ['é' => 1, 'e' => 2, 'Z' => 3, '_' => 4],
		];
	}//end sortInputs()

	/**
	 * The recorded record and manifest pairs.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	private static function categoryInputs(): array {
		return [
			'record' => [['category' => 'Forms'], ['category' => 'x']],
			'paddedRecord' => [['category' => '  Forms '], []],
			'blankRecord' => [['category' => '   '], ['category' => ' Case ']],
			'manifestOnly' => [[], ['category' => 'Case']],
			'neither' => [[], []],
			'numeric' => [['category' => 5], []],
			'nullRecord' => [['category' => null], ['category' => null]],
		];
	}//end categoryInputs()
}//end class
