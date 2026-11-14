<?php

/**
 * Unit tests for the ADR-037 modular register fragment deep-merge.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that disjoint register fragments union cleanly so concurrent
 * OpenSpec change builds never collide on the shared register file (ADR-037).
 */
final class RegisterFragmentMergeTest extends TestCase {
	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Two fragments adding disjoint OpenAPI schemas/paths union by key.
	 *
	 * @return void
	 */
	public function testDisjointFragmentsUnionSchemasAndPaths(): void {
		$base = [
			'components' => ['schemas' => ['Existing' => ['type' => 'object']]],
			'paths' => ['/existing' => ['get' => []]],
		];

		$base = $this->merge(
			$base,
			[
				'components' => ['schemas' => ['AlphaProject' => ['type' => 'object']]],
				'paths' => ['/alpha' => ['get' => []]],
			]
		);
		$base = $this->merge(
			$base,
			[
				'components' => ['schemas' => ['BetaComponent' => ['type' => 'object']]],
				'paths' => ['/beta' => ['post' => []]],
			]
		);

		$this->assertArrayHasKey('Existing', $base['components']['schemas']);
		$this->assertArrayHasKey('AlphaProject', $base['components']['schemas']);
		$this->assertArrayHasKey('BetaComponent', $base['components']['schemas']);
		$this->assertCount(3, $base['components']['schemas']);
		$this->assertArrayHasKey('/existing', $base['paths']);
		$this->assertArrayHasKey('/alpha', $base['paths']);
		$this->assertArrayHasKey('/beta', $base['paths']);
	}//end testDisjointFragmentsUnionSchemasAndPaths()

	/**
	 * Seed objects in components.objects[] union additively across fragments
	 * (fleet-standard ADR-037 rule relied on by the business-rules-engine
	 * fragment, which ships RuleSet/DecisionTable/TestCase seed objects).
	 *
	 * @return void
	 */
	public function testSeedObjectsUnionAdditively(): void {
		// Base has no objects key — first fragment seeds it.
		$base = $this->merge(
			['components' => ['schemas' => ['Existing' => ['type' => 'object']]]],
			['components' => ['objects' => [['@self' => ['slug' => 'alpha']]]]]
		);
		$this->assertCount(1, $base['components']['objects']);

		// Second fragment concatenates its objects onto the accumulated list.
		$base = $this->merge(
			$base,
			['components' => ['objects' => [['@self' => ['slug' => 'beta']]]]]
		);

		$slugs = array_map(
			static fn (array $o): string => $o['@self']['slug'],
			$base['components']['objects']
		);
		$this->assertSame(['alpha', 'beta'], $slugs);
		// Schemas from the base survive the objects-bearing overlay.
		$this->assertArrayHasKey('Existing', $base['components']['schemas']);
	}//end testSeedObjectsUnionAdditively()

	/**
	 * List arrays are concatenated; scalars overwrite.
	 *
	 * @return void
	 */
	public function testListsConcatenateAndScalarsOverwrite(): void {
		$merged = $this->merge(
			['required' => ['a', 'b'], 'info' => ['version' => '0.1.0']],
			['required' => ['c'], 'info' => ['version' => '0.2.0']]
		);
		$this->assertSame(['a', 'b', 'c'], $merged['required']);
		$this->assertSame('0.2.0', $merged['info']['version']);
	}//end testListsConcatenateAndScalarsOverwrite()

	/**
	 * The shipped base with every shipped fragment, in filename order, gives
	 * ApplicationVersion its branch properties and keeps what it had.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
	 */
	public function testTheShippedFragmentsGiveAVersionItsBranchProperties(): void {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode((string)file_get_contents($settings . '/openbuild_register.json'), true, 512, JSON_THROW_ON_ERROR);
		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$merged = $this->merge($merged, json_decode((string)file_get_contents($fragment), true, 512, JSON_THROW_ON_ERROR));
		}

		$version = $merged['components']['schemas']['ApplicationVersion'];
		$this->assertSame('0.5.0', $version['version']);
		$this->assertSame(['source', 'manifestHash', 'manifest', 'schemas'], $version['properties']['branchBase']['required']);
		$this->assertSame('^[0-9a-f]{64}$', $version['properties']['branchBase']['properties']['manifestHash']['pattern']);
		$this->assertSame('^[A-Za-z0-9._/-]{1,255}$', $version['properties']['githubBranch']['pattern']);
		$this->assertSame('object', $version['properties']['manifest']['type'], 'The properties the schema had are kept.');
		$this->assertSame('applicationVersion', $version['slug']);
	}//end testTheShippedFragmentsGiveAVersionItsBranchProperties()
}//end class
