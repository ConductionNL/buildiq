<?php

/**
 * ServedManifestShape names the first part of a manifest the renderer cannot draw.
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

use OCA\Buildiq\Service\ServedManifestShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Service\ServedManifestShape
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
 */
final class ServedManifestShapeTest extends TestCase {

	/**
	 * A manifest with one sound page.
	 *
	 * @return array<string, mixed>
	 */
	private static function sound(): array {
		return [
			'version' => '1.0.0',
			'menu' => [['label' => 'Home', 'route' => '/']],
			'pages' => [['id' => 'home', 'route' => '/', 'type' => 'dashboard', 'title' => 'Home']],
		];
	}//end sound()

	/**
	 * A sound manifest has no problem, with or without a menu.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
	 */
	public function testASoundManifestHasNoProblem(): void {
		$shape = new ServedManifestShape();
		$withoutMenu = self::sound();
		unset($withoutMenu['menu']);

		$this->assertNull(actual: $shape->problem(manifest: self::sound()));
		$this->assertNull(actual: $shape->problem(manifest: $withoutMenu));
		$this->assertNull(actual: $shape->problem(manifest: ['version' => '1.0.0', 'pages' => []]));
	}//end testASoundManifestHasNoProblem()

	/**
	 * Each broken manifest and the problem it is named by.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function brokenManifests(): array {
		$sound = self::sound();
		$page = $sound['pages'][0];

		return [
			'no version' => [['pages' => []], 'the manifest has no string `version`'],
			'numeric version' => [['version' => 1, 'pages' => []], 'the manifest has no string `version`'],
			'menu not a list' => [[...$sound, 'menu' => 'home'], '`menu` is not an array'],
			'no pages' => [['version' => '1.0.0'], 'the manifest has no `pages` array'],
			'pages keyed by id' => [[...$sound, 'pages' => ['home' => $page]], 'the manifest has no `pages` array'],
			'page not an object' => [[...$sound, 'pages' => ['home']], '`pages[0]` is not an object'],
			'page without id' => [[...$sound, 'pages' => [[...$page, 'id' => null]]], '`pages[0].id` is not a non-empty string'],
			'page with blank id' => [[...$sound, 'pages' => [[...$page, 'id' => ' ']]], '`pages[0].id` is not a non-empty string'],
			'repeated id' => [[...$sound, 'pages' => [$page, $page]], '`pages[1].id` repeats the id "home"'],
			'page without route' => [[...$sound, 'pages' => [[...$page, 'route' => null]]], '`pages[0].route` is not a string'],
			'page without type' => [[...$sound, 'pages' => [[...$page, 'type' => 3]]], '`pages[0].type` is not a string'],
			'page without title' => [[...$sound, 'pages' => [['id' => 'home', 'route' => '/', 'type' => 'index']]], '`pages[0].title` is not a string'],
		];
	}//end brokenManifests()

	/**
	 * A broken manifest is named by its first problem.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 * @param string               $problem  The expected problem.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
	 */
	#[DataProvider('brokenManifests')]
	public function testABrokenManifestIsNamedByItsFirstProblem(array $manifest, string $problem): void {
		$this->assertSame(expected: $problem, actual: (new ServedManifestShape())->problem(manifest: $manifest));
	}//end testABrokenManifestIsNamedByItsFirstProblem()
}//end class
