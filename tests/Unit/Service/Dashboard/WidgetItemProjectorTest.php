<?php

/**
 * Unit tests for WidgetItemProjector.
 *
 * The two filter-grammar tests are deliberately separate. OpenRegister's
 * objects endpoint and its aggregations endpoint spell filters OPPOSITELY, and
 * each answers the other's shape with a confident WRONG NUMBER rather than an
 * error, so the two near-identical call sites must stay near-identical and a
 * later tidy-up into one helper must fail loudly here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Dashboard
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

namespace OCA\Buildiq\Tests\Unit\Service\Dashboard;

use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\Buildiq\Service\Dashboard\AggregationGateway;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see WidgetItemProjector}.
 */
class WidgetItemProjectorTest extends TestCase {
	/**
	 * A stable, obviously fake Application UUID.
	 *
	 * @var string
	 */
	private const APP_UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * Mock object service (the objects endpoint).
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock aggregation gateway (the aggregations endpoint).
	 *
	 * @var AggregationGateway&MockObject
	 */
	private AggregationGateway&MockObject $aggregations;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->aggregations = $this->createMock(AggregationGateway::class);
	}//end setUp()

	/**
	 * A declarative data source returning three rows becomes three items, each
	 * linking into the app page the widget sits on.
	 *
	 * @return void
	 */
	public function testADeclarativeDataSourceBecomesOneItemPerRow(): void {
		$this->objectService->method('findAll')->willReturn([
			['id' => '1', 'title' => 'Lost cat', 'description' => 'Ginger'],
			['id' => '2', 'name' => 'Found dog'],
			['id' => '3'],
		]);

		$items = $this->projector()->project(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's'])
		);

		$this->assertCount(3, $items->getItems());
		$this->assertSame('Lost cat', $items->getItems()[0]->getTitle());
		$this->assertSame('Ginger', $items->getItems()[0]->getSubtitle());
		$this->assertSame('Found dog', $items->getItems()[1]->getTitle());
		$this->assertSame('3', $items->getItems()[2]->getTitle());

		foreach ($items->getItems() as $item) {
			$this->assertSame(
				'https://example.test/apps/buildiq/builder/pet-store/overview',
				$item->getLink(),
				"Each item's link must open the app page the widget is placed on."
			);
		}
	}//end testADeclarativeDataSourceBecomesOneItemPerRow()

	/**
	 * GRAMMAR TEST 1 — the OBJECTS endpoint gets BARE filter keys.
	 *
	 * A bracketed key here is read as the EMPTY SET and the widget shows
	 * nothing, which looks exactly like a widget with no matching rows.
	 *
	 * This asserts the query actually sent, not the returned count. A
	 * count-only assertion passes against the whole register.
	 *
	 * @return void
	 */
	public function testTheObjectsEndpointReceivesBareFilterKeys(): void {
		$sent = null;
		$this->objectService->method('findAll')
			->willReturnCallback(function (array $config) use (&$sent): array {
				$sent = $config;
				return [];
			});

		$this->projector()->project(
			descriptor: $this->descriptor(
				dataSource: [
					'register' => 'r',
					'schema' => 's',
					'filter' => ['status' => 'open', 'priority' => 'high'],
				]
			),
			limit: 5
		);

		$this->assertNotNull($sent, 'The objects endpoint must actually be queried.');
		$filters = $sent['filters'];

		$this->assertSame('open', $filters['status'] ?? null, 'BARE key expected at the top level.');
		$this->assertSame('high', $filters['priority'] ?? null, 'BARE key expected at the top level.');
		$this->assertArrayNotHasKey(
			'filter',
			$filters,
			'The objects endpoint reads a nested/bracketed `filter` as the EMPTY SET and '
			. 'returns nothing, with no error. Bare keys only here.'
		);
		foreach (array_keys($filters) as $key) {
			$this->assertStringNotContainsString('[', (string)$key);
		}

		$this->assertSame(5, $sent['limit']);
	}//end testTheObjectsEndpointReceivesBareFilterKeys()

	/**
	 * An aggregating data source becomes exactly one item whose title is the
	 * number.
	 *
	 * @return void
	 */
	public function testAnAggregatingDataSourceBecomesASingleNumber(): void {
		$this->aggregations->method('value')->willReturn(42);

		$items = $this->projector()->project(
			descriptor: $this->descriptor(
				dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => 'count']
			)
		);

		$this->assertCount(1, $items->getItems());
		$this->assertSame('42', $items->getItems()[0]->getTitle());
	}//end testAnAggregatingDataSourceBecomesASingleNumber()

	/**
	 * GRAMMAR TEST 2 — the AGGREGATIONS endpoint gets BRACKETED filter keys.
	 *
	 * The aggregations endpoint DROPS a bare key and counts the WHOLE
	 * REGISTER, so the wrong spelling yields a confident wrong number rather
	 * than an error. `['filter' => ['status' => 'open']]` is exactly what
	 * `filter[status]=open` parses into.
	 *
	 * @return void
	 */
	public function testTheAggregationsEndpointReceivesBracketedFilterKeys(): void {
		$sent = null;
		$this->aggregations->method('value')
			->willReturnCallback(function (string $register, string $schema, array $params) use (&$sent) {
				$sent = ['register' => $register, 'schema' => $schema, 'params' => $params];
				return 7;
			});

		$this->projector()->project(
			descriptor: $this->descriptor(
				dataSource: [
					'register' => 'cases',
					'schema' => 'case',
					'aggregate' => 'count',
					'filter' => ['status' => 'open'],
				]
			)
		);

		$this->assertNotNull($sent, 'The aggregations endpoint must actually be queried.');
		$this->assertSame('cases', $sent['register']);
		$this->assertSame('case', $sent['schema']);
		$this->assertSame(
			['status' => 'open'],
			$sent['params']['filter'] ?? null,
			'The filter must be NESTED under `filter` (the filter[x]=v spelling).'
		);
		$this->assertArrayNotHasKey(
			'status',
			$sent['params'],
			'The aggregations endpoint DROPS a bare key and returns the whole register. '
			. 'A bare key here is a confident wrong number, not an error.'
		);
	}//end testTheAggregationsEndpointReceivesBracketedFilterKeys()

	/**
	 * The two grammars are genuinely different. If the two builders are ever
	 * tidied into one helper, this fails.
	 *
	 * @return void
	 */
	public function testTheTwoEndpointsAreAddressedInDifferentGrammars(): void {
		$dataSource = ['register' => 'r', 'schema' => 's', 'filter' => ['status' => 'open']];
		$projector = $this->projector();

		$objects = $projector->objectsRequestParams(dataSource: $dataSource, limit: 7);
		$aggregations = $projector->aggregationsRequestParams(dataSource: $dataSource);

		$this->assertSame('open', $objects['status'] ?? null);
		$this->assertArrayNotHasKey('filter', $objects);

		$this->assertSame(['status' => 'open'], $aggregations['filter'] ?? null);
		$this->assertArrayNotHasKey('status', $aggregations);
	}//end testTheTwoEndpointsAreAddressedInDifferentGrammars()

	/**
	 * A chart widget returns no items, a message naming the app page, and no
	 * number anywhere in the response.
	 *
	 * @return void
	 */
	public function testAChartWidgetReturnsEmptyWithAPointerAtTheAppPage(): void {
		$this->assertHonestEmptyState(
			descriptor: $this->descriptor(
				widgetKey: 'chart',
				dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => 'count']
			)
		);
	}//end testAChartWidgetReturnsEmptyWithAPointerAtTheAppPage()

	/**
	 * A gauge widget, same.
	 *
	 * @return void
	 */
	public function testAGaugeWidgetReturnsEmptyWithAPointerAtTheAppPage(): void {
		$this->assertHonestEmptyState(
			descriptor: $this->descriptor(
				widgetKey: 'gauge',
				dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => 'count']
			)
		);
	}//end testAGaugeWidgetReturnsEmptyWithAPointerAtTheAppPage()

	/**
	 * A raw GraphQL data source returns empty rather than zero.
	 *
	 * @return void
	 */
	public function testARawGraphqlDataSourceReturnsEmptyRatherThanZero(): void {
		$this->assertHonestEmptyState(
			descriptor: $this->descriptor(
				dataSource: ['graphql' => ['query' => '{ cases { total } }', 'selectors' => []]]
			)
		);
	}//end testARawGraphqlDataSourceReturnsEmptyRatherThanZero()

	/**
	 * An aggregation the gateway cannot compute returns empty, never zero.
	 * A returned zero is indistinguishable from a real zero.
	 *
	 * @return void
	 */
	public function testAnUncomputableAggregationReturnsEmptyNeverZero(): void {
		$this->aggregations->method('value')->willReturn(null);

		$this->assertHonestEmptyState(
			descriptor: $this->descriptor(
				dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => 'count']
			)
		);
	}//end testAnUncomputableAggregationReturnsEmptyNeverZero()

	/**
	 * A failing objects query degrades to the empty state rather than throwing
	 * into the dashboard API.
	 *
	 * @return void
	 */
	public function testAFailingObjectsQueryDegradesToTheEmptyState(): void {
		$this->objectService->method('findAll')
			->willThrowException(new \RuntimeException('OpenRegister is down'));

		$this->assertHonestEmptyState(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's'])
		);
	}//end testAFailingObjectsQueryDegradesToTheEmptyState()

	/**
	 * Assert a descriptor projects to an honest empty state.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return void
	 */
	private function assertHonestEmptyState(WidgetDescriptor $descriptor): void {
		$items = $this->projector()->project(descriptor: $descriptor);

		$this->assertSame([], $items->getItems());
		$this->assertStringContainsString(
			'Pet Store',
			$items->getEmptyContentMessage(),
			'The empty message must name the app page where the widget can be seen.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\d/',
			$items->getEmptyContentMessage(),
			'No numeric value may appear. Zero is indistinguishable from a real zero.'
		);
	}//end assertHonestEmptyState()

	/**
	 * Build the projector under test.
	 *
	 * @return WidgetItemProjector The projector.
	 */
	private function projector(): WidgetItemProjector {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $params): string
					=> 'https://example.test/apps/buildiq/builder/' . $params['slug']
			);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new WidgetItemProjector(
			objectService: $this->objectService,
			aggregations: $this->aggregations,
			urlGenerator: $urlGenerator,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end projector()

	/**
	 * A descriptor for one promoted placement.
	 *
	 * @param array<string,mixed> $dataSource The placement's data source.
	 * @param string $widgetKey The runtime registry key.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function descriptor(array $dataSource, string $widgetKey = 'object-table'): WidgetDescriptor {
		return new WidgetDescriptor(
			applicationUuid: self::APP_UUID,
			applicationSlug: 'pet-store',
			applicationName: 'Pet Store',
			principals: ['group:*'],
			entryId: 'open-cases',
			widgetKey: $widgetKey,
			panel: ['title' => 'Open cases'],
			placement: ['pageRoute' => '/overview', 'dataSource' => $dataSource]
		);
	}//end descriptor()
}//end class
