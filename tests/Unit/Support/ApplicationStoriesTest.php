<?php

/**
 * ApplicationStories unit test.
 *
 * A plan's servedBy step indexes become the schema or page each pointed at,
 * and only well-formed stories are kept, trimmed and capped.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\ApplicationStories;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Support\ApplicationStories
 */
final class ApplicationStoriesTest extends TestCase {

	/**
	 * The steps of a small plan.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function steps(): array {
		return [
			['tool' => 'buildiq.createApp', 'arguments' => ['slug' => 'tuin', 'name' => 'Tuin']],
			['tool' => 'buildiq.upsertSchema', 'arguments' => ['slug' => 'aanvraag', 'title' => 'Aanvraag']],
			['tool' => 'buildiq.upsertPage', 'arguments' => ['pageId' => 'mine', 'title' => 'Mijn aanvragen']],
			['tool' => 'buildiq.upsertPage', 'arguments' => ['pageId' => 'all']],
			['tool' => 'buildiq.upsertMenuItem', 'arguments' => ['label' => 'Mijn aanvragen']],
		];
	}//end steps()

	/**
	 * Indexes become named schema and page references; other steps, unknown and non-integer indexes are dropped.
	 *
	 * @return void
	 */
	public function testFromPlanNamesWhatServesEachStory(): void {
		$stories = [['id' => 's1', 'role' => 'an applicant', 'goal' => 'track my permit', 'benefit' => 'I know where it stands', 'servedBy' => [1, 2, 3, 4, 9, '2']]];

		$this->assertSame(
			[
				[
					'id' => 's1',
					'role' => 'an applicant',
					'goal' => 'track my permit',
					'benefit' => 'I know where it stands',
					'servedBy' => [
						['kind' => 'schema', 'id' => 'aanvraag', 'title' => 'Aanvraag'],
						['kind' => 'page', 'id' => 'mine', 'title' => 'Mijn aanvragen'],
						['kind' => 'page', 'id' => 'all', 'title' => 'all'],
					],
				],
			],
			ApplicationStories::fromPlan(stories: $stories, steps: $this->steps())
		);
	}//end testFromPlanNamesWhatServesEachStory()

	/**
	 * A story without a role or goal, or not an object, is dropped; text is trimmed and capped.
	 *
	 * @return void
	 */
	public function testSanitiseKeepsOnlyWellFormedStories(): void {
		$kept = ApplicationStories::sanitise(
			stories: [
				'not a story',
				['role' => 'a reviewer'],
				['role' => ' ', 'goal' => 'x'],
				['role' => ' a clerk ', 'goal' => str_repeat('g', 400), 'benefit' => 7, 'servedBy' => 'mine'],
			]
		);

		$this->assertSame([['id' => '', 'role' => 'a clerk', 'goal' => str_repeat('g', 300), 'servedBy' => []]], $kept);
		$this->assertSame([], ApplicationStories::sanitise(stories: 'none'));
	}//end testSanitiseKeepsOnlyWellFormedStories()

	/**
	 * A reference needs a known kind and an id; a missing title falls back to the id.
	 *
	 * @return void
	 */
	public function testSanitiseKeepsOnlySchemaAndPageReferences(): void {
		$kept = ApplicationStories::sanitise(
			stories: [
				[
					'role' => 'r',
					'goal' => 'g',
					'servedBy' => [['kind' => 'menu', 'id' => 'm'], ['kind' => 'page', 'id' => ''], ['kind' => 'schema', 'id' => 'aanvraag'], 'x'],
				],
			]
		);

		$this->assertSame([['kind' => 'schema', 'id' => 'aanvraag', 'title' => 'aanvraag']], $kept[0]['servedBy']);
	}//end testSanitiseKeepsOnlySchemaAndPageReferences()

	/**
	 * At most twenty stories, and at most twenty references each.
	 *
	 * @return void
	 */
	public function testTheCapsHold(): void {
		$references = array_map(static fn (int $i): array => ['kind' => 'page', 'id' => 'p' . $i], range(1, 25));
		$stories = array_fill(0, 25, ['role' => 'r', 'goal' => 'g', 'servedBy' => $references]);

		$kept = ApplicationStories::sanitise(stories: $stories);

		$this->assertCount(20, $kept);
		$this->assertCount(20, $kept[0]['servedBy']);
	}//end testTheCapsHold()

	/**
	 * Every kept key is a property the register fragment declares, so OpenRegister drops nothing.
	 *
	 * @return void
	 */
	public function testKeptKeysAreDeclaredInTheFragment(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/81-application-stories.json'), true);
		$item = $fragment['components']['schemas']['Application']['properties']['stories']['items'];
		$kept = ApplicationStories::fromPlan(
			stories: [['id' => 's1', 'role' => 'r', 'goal' => 'g', 'benefit' => 'b', 'servedBy' => [2]]],
			steps: $this->steps()
		);

		$this->assertSame([], array_diff(array_keys($kept[0]), array_keys($item['properties'])));
		$this->assertSame([], array_diff(array_keys($kept[0]['servedBy'][0]), array_keys($item['properties']['servedBy']['items']['properties'])));
		$this->assertSame(ApplicationStories::MAX_STORIES, $fragment['components']['schemas']['Application']['properties']['stories']['maxItems']);
	}//end testKeptKeysAreDeclaredInTheFragment()
}//end class
