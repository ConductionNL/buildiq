<?php

/**
 * Golden test for WidgetDescriptor construction.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Dashboard
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

namespace OCA\Buildiq\Tests\Unit\Dashboard;

use OCA\Buildiq\Dashboard\WidgetApplication;
use OCA\Buildiq\Dashboard\WidgetDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * Pins every public property and the serialised form of a descriptor over
 * application identities, principals, entry ids that do and do not fit the
 * widget-id alphabet, widget keys, panel objects and placements. Set
 * GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Dashboard\WidgetDescriptor
 * @uses   \OCA\Buildiq\Dashboard\WidgetApplication
 */
class WidgetDescriptorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/widget-descriptor.golden.json';

	/**
	 * Every case, by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function cases(): array {
		$apps = [
			'plain' => ['3f2c9a10-1111-4222-8333-444455556666', 'pet-store', 'Pet Store'],
			'odd uuid' => ['UUID With Spaces', '', ''],
		];
		$entries = ['open-cases', 'Open Cases!', '', '-x-'];
		$panels = [
			'empty' => [],
			'full' => ['title' => 'Cases', 'icon' => 'icon-folder', 'order' => 5, 'link' => '/apps/x'],
			'string order' => ['order' => '12', 'title' => 7],
		];
		$placements = [
			'empty' => [],
			'full' => ['pageRoute' => '/overview', 'props' => ['a' => 1], 'dataSource' => ['register' => 'r']],
			'scalars' => ['props' => 'p', 'dataSource' => 3],
		];
		$cases = [];
		foreach ($apps as $an => [$uuid, $slug, $name]) {
			foreach ($entries as $entryId) {
				foreach ($panels as $pn => $panel) {
					foreach ($placements as $ln => $placement) {
						$cases["$an / '$entryId' / panel $pn / placement $ln"] = [
							'uuid' => $uuid,
							'slug' => $slug,
							'name' => $name,
							'principals' => ['user:a', 'group:b'],
							'entryId' => $entryId,
							'widgetKey' => 'stat-' . $pn,
							'panel' => $panel,
							'placement' => $placement,
						];
					}
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Every case matches its recorded output.
	 *
	 * @return void
	 */
	public function testDescriptorMatchesGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$descriptor = $this->build(case: $case);
			$actual[$name] = [
				'json' => $descriptor->jsonSerialize(),
				'applicationUuid' => $descriptor->applicationUuid,
				'principals' => $descriptor->principals,
			];
		}

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testDescriptorMatchesGolden()

	/**
	 * Construct the descriptor for one case.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function build(array $case): WidgetDescriptor {
		return new WidgetDescriptor(
			application: new WidgetApplication(uuid: $case['uuid'], slug: $case['slug'], name: $case['name']),
			principals: $case['principals'],
			entryId: $case['entryId'],
			widgetKey: $case['widgetKey'],
			panel: $case['panel'],
			placement: $case['placement']
		);
	}//end build()
}//end class
