<?php

/**
 * ManifestDeltaMerger unit test.
 *
 * The PHP merge gives exactly what `@conduction/nextcloud-vue`'s
 * `mergeManifestDelta` gives, case for case, on the fixture file recorded from
 * the JS util; plus the properties the fixtures alone do not show.
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

use OCA\Buildiq\Service\ManifestDeltaMerger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Service\ManifestDeltaMerger
 * @covers \OCA\Buildiq\Service\ManifestDeltaShape
 */
final class ManifestDeltaMergerTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/manifest-delta-merge.json';

	/**
	 * The recorded JS cases.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function recordedCases(): array {
		$cases = [];
		foreach (json_decode((string)file_get_contents(self::FIXTURES), true, 512, JSON_THROW_ON_ERROR) as $case) {
			$cases[$case['name']] = [$case['base'], $case['delta'], $case['expected']];
		}

		return $cases;
	}//end recordedCases()

	/**
	 * Each case merges to the JS util's output, byte for byte once encoded.
	 *
	 * @param array<string, mixed> $base     The base manifest.
	 * @param array<string, mixed> $delta    The delta.
	 * @param array<string, mixed> $expected What the JS util answered.
	 *
	 * @dataProvider recordedCases
	 *
	 * @return void
	 */
	public function testItMatchesTheJsUtil(array $base, array $delta, array $expected): void {
		$merged = (new ManifestDeltaMerger())->merge(base: $base, delta: $delta);

		$this->assertSame($expected, $merged);
		$this->assertSame(json_encode($expected), json_encode($merged));
	}//end testItMatchesTheJsUtil()

	/**
	 * The fixture file holds every kind of case the contract names.
	 *
	 * @return void
	 */
	public function testTheFixturesCoverTheContract(): void {
		$names = array_keys(self::recordedCases());

		$this->assertGreaterThanOrEqual(15, count($names));
		foreach (['patched by id', 'appended', 'removed', 'orphan', '__order', 'children', 'defaultSavedViews', 'replaced wholesale'] as $kind) {
			$this->assertNotEmpty(array_filter($names, static fn (string $name): bool => str_contains($name, $kind)), $kind);
		}
	}//end testTheFixturesCoverTheContract()

	/**
	 * The inputs are left as they were.
	 *
	 * @return void
	 */
	public function testTheInputsAreNotChanged(): void {
		$base = ['pages' => [['id' => 'home', 'title' => 'Home'], ['id' => 'old']]];
		$delta = ['pages' => [['id' => 'home', 'title' => 'Start'], ['id' => 'old', '$op' => 'remove']]];
		$baseBefore = $base;
		$deltaBefore = $delta;

		$merged = (new ManifestDeltaMerger())->merge(base: $base, delta: $delta);

		$this->assertSame(['pages' => [['id' => 'home', 'title' => 'Start']]], $merged['manifest']);
		$this->assertSame($baseBefore, $base);
		$this->assertSame($deltaBefore, $delta);
	}//end testTheInputsAreNotChanged()

	/**
	 * Keys of different types stay apart, as they do in JS: `1` does not match `"1"`.
	 *
	 * @return void
	 */
	public function testKeysOfDifferentTypesStayApart(): void {
		$merged = (new ManifestDeltaMerger())->merge(
			base: ['pages' => [['id' => 1, 'title' => 'Numeric']]],
			delta: ['pages' => [['id' => '1', 'title' => 'Text'], ['id' => '1', '$op' => 'remove'], ['id' => 1, '$op' => 'remove']]]
		);

		$this->assertSame(['pages' => [['id' => '1', 'title' => 'Text']]], $merged['manifest']);
		$this->assertSame(['pages/1'], $merged['orphanedDeltaPaths']);
	}//end testKeysOfDifferentTypesStayApart()
}//end class
