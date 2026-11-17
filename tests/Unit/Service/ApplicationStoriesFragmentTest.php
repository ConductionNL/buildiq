<?php

/**
 * Application stories register fragment test.
 *
 * The 81-application-stories fragment merges onto the base register as one
 * new optional Application property and touches nothing else.
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

use OCA\Buildiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Buildiq\Service\SettingsService
 */
final class ApplicationStoriesFragmentTest extends TestCase {

	/**
	 * A JSON file under lib/Settings, decoded.
	 *
	 * @param string $relative The path under lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settingsJson(string $relative): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $relative), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		return $data;
	}//end settingsJson()

	/**
	 * The register import accepts the property: merged onto the base config,
	 * Application gains `stories` and keeps every other property and its required list.
	 *
	 * @return void
	 */
	public function testTheFragmentAddsOnlyStoriesToApplication(): void {
		$base = $this->settingsJson(relative: 'openbuild_register.json');
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merged = $merge->invoke(null, $base, $this->settingsJson(relative: 'register.d/81-application-stories.json'));

		$before = $base['components']['schemas']['Application'];
		$after = $merged['components']['schemas']['Application'];
		self::assertArrayNotHasKey('stories', $before['properties']);
		self::assertSame(['stories'], array_values(array_diff(array_keys($after['properties']), array_keys($before['properties']))));
		self::assertSame(($before['required'] ?? null), ($after['required'] ?? null));
		self::assertSame(array_keys($base['components']['schemas']), array_keys($merged['components']['schemas']));
	}//end testTheFragmentAddsOnlyStoriesToApplication()

	/**
	 * A story needs a role and a goal; a reference is a schema or a page.
	 *
	 * @return void
	 */
	public function testAStoryNeedsARoleAndAGoal(): void {
		$stories = $this->settingsJson(relative: 'register.d/81-application-stories.json')['components']['schemas']['Application']['properties']['stories'];

		self::assertSame('array', $stories['type']);
		self::assertSame(['role', 'goal'], $stories['items']['required']);
		self::assertSame(['schema', 'page'], $stories['items']['properties']['servedBy']['items']['properties']['kind']['enum']);
	}//end testAStoryNeedsARoleAndAGoal()
}//end class
