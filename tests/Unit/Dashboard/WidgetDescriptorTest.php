<?php

/**
 * Unit tests for WidgetDescriptor, including the id-stability regression.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\DashboardWidgetRegistrar;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see WidgetDescriptor}.
 */
class WidgetDescriptorTest extends TestCase {
	/**
	 * The failure message every id assertion in this file carries.
	 *
	 * @var string
	 */
	private const ONE_WAY_DOOR =
		"The promoted widget id changed.\n"
		. "Nextcloud's Dashboard app stores each user's chosen widgets by id in its OWN\n"
		. "appconfig namespace, which this app cannot read and no migration it ships can\n"
		. "reach. A changed id therefore DROPS THE PANEL FROM EVERY DASHBOARD THAT HAD IT,\n"
		. "with no error, no log line and no 404 anywhere. The user simply sees a dashboard\n"
		. "that looks exactly like one where they never added the widget.\n"
		. 'Widget ids are a one-way door. Key them on the Application UUID, never the slug.';

	/**
	 * A stable, obviously fake Application UUID.
	 *
	 * @var string
	 */
	private const APP_UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * The id is prefixed, UUID-keyed and inside Nextcloud's alphabet.
	 *
	 * @return void
	 */
	public function testIdIsPrefixedAndMatchesNextcloudsAlphabet(): void {
		$id = WidgetDescriptor::deriveId(applicationUuid: self::APP_UUID, entryId: 'open-cases');

		$this->assertSame('buildiq-' . self::APP_UUID . '-open-cases', $id, self::ONE_WAY_DOOR);
		$this->assertMatchesRegularExpression(WidgetDescriptor::ID_PATTERN, $id);
	}//end testIdIsPrefixedAndMatchesNextcloudsAlphabet()

	/**
	 * THE REGRESSION. Rename the Application's slug and its name, leave the
	 * manifest otherwise alone, and every promoted widget id must be
	 * byte-identical before and after.
	 *
	 * This test exists to fail when someone later decides the slug reads
	 * better in the id than a UUID does.
	 *
	 * @return void
	 */
	public function testRenamingTheSlugDoesNotChangeAnyWidgetId(): void {
		$before = $this->collectIds(slug: 'pet-store', name: 'Pet Store');
		$after = $this->collectIds(slug: 'animal-desk', name: 'Animal Desk');

		$this->assertNotEmpty($before, 'The fixture must produce at least one promoted widget.');
		$this->assertSame($before, $after, self::ONE_WAY_DOOR);
	}//end testRenamingTheSlugDoesNotChangeAnyWidgetId()

	/**
	 * Neither the slug nor the app name may appear anywhere in a derived id.
	 *
	 * @return void
	 */
	public function testTheSlugAndTheNameAppearNowhereInTheId(): void {
		$ids = $this->collectIds(slug: 'pet-store', name: 'Pet Store');

		foreach ($ids as $id) {
			$this->assertStringNotContainsString('pet-store', $id, self::ONE_WAY_DOOR);
			$this->assertStringNotContainsString('pet', $id, self::ONE_WAY_DOOR);
			$this->assertStringNotContainsString('store', $id, self::ONE_WAY_DOOR);
		}
	}//end testTheSlugAndTheNameAppearNowhereInTheId()

	/**
	 * An entry id outside the supported alphabet is folded into it, and the
	 * fold is deterministic.
	 *
	 * @return void
	 */
	public function testAnUnsupportedEntryIdIsNormalisedDeterministically(): void {
		$first = WidgetDescriptor::deriveId(
			applicationUuid: self::APP_UUID,
			entryId: 'Open Cases (2026)!'
		);
		$second = WidgetDescriptor::deriveId(
			applicationUuid: self::APP_UUID,
			entryId: 'Open Cases (2026)!'
		);

		$this->assertMatchesRegularExpression(WidgetDescriptor::ID_PATTERN, $first);
		$this->assertSame($second, $first, 'Re-deriving from the same entry must give the same id.');
	}//end testAnUnsupportedEntryIdIsNormalisedDeterministically()

	/**
	 * Two entry ids that fold onto the same alphabet must NOT collide.
	 *
	 * A collision would be silent: Manager::registerWidget() throws on a
	 * duplicate id and loadLazyPanels() catches and logs that throw, so the
	 * second widget would simply never appear in the picker.
	 *
	 * @return void
	 */
	public function testTwoEntryIdsThatFoldTheSameWayDoNotCollide(): void {
		$one = WidgetDescriptor::deriveId(applicationUuid: self::APP_UUID, entryId: 'Open Cases');
		$two = WidgetDescriptor::deriveId(applicationUuid: self::APP_UUID, entryId: 'open cases');

		$this->assertNotSame($two, $one, 'Two different entry ids must not fold onto one widget id.');
	}//end testTwoEntryIdsThatFoldTheSameWayDoNotCollide()

	/**
	 * Collect every promoted widget id from a fixture Application, built at
	 * the given slug and name.
	 *
	 * The whole path is exercised, not just deriveId(): the registrar reads
	 * the manifest and constructs the descriptors, so a slug leaking in
	 * anywhere along that path is caught.
	 *
	 * @param string $slug The Application's slug.
	 * @param string $name The Application's display name.
	 *
	 * @return array<int,string> The derived widget ids.
	 */
	private function collectIds(string $slug, string $name): array {
		$registrar = new DashboardWidgetRegistrar(
			applications: new PublishedApplicationProvider(
				objectService: $this->createMock(ObjectServiceInterface::class)
			),
			visibility: new AppVisibilityResolver(),
			logger: $this->createMock(LoggerInterface::class)
		);

		$descriptors = $registrar->collectDescriptors(
			applications: [self::fixtureApplication(slug: $slug, name: $name)]
		);

		return array_map(static fn (WidgetDescriptor $d): string => $d->id, $descriptors);
	}//end collectIds()

	/**
	 * A published Application carrying two promoted widgets and one unpromoted.
	 *
	 * @param string $slug The Application's slug.
	 * @param string $name The Application's display name.
	 *
	 * @return array<string,mixed> The Application record.
	 */
	public static function fixtureApplication(string $slug, string $name): array {
		return [
			'uuid' => self::APP_UUID,
			'slug' => $slug,
			'name' => $name,
			'status' => 'published',
			'permissions' => ['owners' => ['group:*'], 'editors' => [], 'viewers' => []],
			'productionVersion' => [
				'uuid' => '99999999-8888-7777-6666-555555555555',
				'manifest' => [
					'pages' => [
						[
							'id' => 'overview',
							'route' => '/overview',
							'widgets' => [
								[
									'id' => 'open-cases',
									'widgetKey' => 'stat',
									'slot' => 'body',
									'ncDashboard' => ['title' => 'Open cases', 'order' => 12],
									'dataSource' => ['register' => 'r', 'schema' => 's', 'aggregate' => 'count'],
								],
								[
									'id' => 'recent-cases',
									'widgetKey' => 'object-table',
									'slot' => 'body',
									'ncDashboard' => ['title' => 'Recent cases'],
									'dataSource' => ['register' => 'r', 'schema' => 's'],
								],
								[
									'id' => 'internal-only',
									'widgetKey' => 'stat',
									'slot' => 'body',
								],
							],
						],
					],
				],
			],
		];
	}//end fixtureApplication()
}//end class
