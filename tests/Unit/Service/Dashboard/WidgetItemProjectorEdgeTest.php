<?php

/**
 * Unit tests for the edges of WidgetItemProjector: which descriptors it
 * refuses to project, how a row's title, subtitle and since id are chosen,
 * where an item links, and whose name the empty state carries.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Dashboard;

use OCA\Buildiq\Dashboard\WidgetApplication;
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
 * Edge cases of WidgetItemProjector.
 *
 * @covers \OCA\Buildiq\Service\Dashboard\WidgetItemProjector
 * @uses   \OCA\Buildiq\Dashboard\WidgetDescriptor
 * @uses   \OCA\Buildiq\Dashboard\WidgetApplication
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class WidgetItemProjectorEdgeTest extends TestCase {

	/**
	 * The objects endpoint.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The aggregations endpoint.
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
		$this->aggregations  = $this->createMock(AggregationGateway::class);
	}//end setUp()

	/**
	 * A data source without a register or a schema is not queried and shows the empty state.
	 *
	 * @return void
	 */
	public function testASourceWithoutRegisterOrSchemaIsNotQueried(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->aggregations->expects($this->never())->method('value');

		foreach ([['register' => 'r'], ['schema' => 's'], []] as $source) {
			$items = $this->projector()->project(descriptor: $this->descriptor(dataSource: $source));
			self::assertSame([], $items->getItems());
			self::assertSame('Open Pet Store to see this widget.', $items->getEmptyContentMessage());
		}
	}//end testASourceWithoutRegisterOrSchemaIsNotQueried()

	/**
	 * The object form of `aggregate` is a group-by, not a number: neither endpoint is asked.
	 *
	 * @return void
	 */
	public function testAGroupByAggregateIsNotProjected(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->aggregations->expects($this->never())->method('value');

		$items = $this->projector()->project(
			descriptor: $this->descriptor(
				dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => ['groupBy' => 'status']]
			)
		);

		self::assertSame([], $items->getItems());
	}//end testAGroupByAggregateIsNotProjected()

	/**
	 * A string aggregate other than `count` falls back to listing rows.
	 *
	 * @return void
	 */
	public function testAnOtherStringAggregateListsRows(): void {
		$this->aggregations->expects($this->never())->method('value');
		$this->objectService->expects($this->once())->method('findAll')->willReturn([['title' => 'One']]);

		$items = $this->projector()->project(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => 'sum'])
		);

		self::assertSame('One', $items->getItems()[0]->getTitle());
	}//end testAnOtherStringAggregateListsRows()

	/**
	 * The rows query asks for the given limit and the declared filter, and nothing else.
	 *
	 * @return void
	 */
	public function testTheRowsQueryCarriesTheLimitAndFilter(): void {
		$this->objectService->expects($this->once())->method('findAll')->with(
			[
				'filters' => ['register' => 'r', 'schema' => 's', 'status' => 'open'],
				'limit'   => 3,
			]
		)->willReturn([['title' => 'One']]);

		$this->projector()->project(
			descriptor: $this->descriptor(
				dataSource: ['register' => 'r', 'schema' => 's', 'filter' => ['status' => 'open']]
			),
			limit: 3
		);
	}//end testTheRowsQueryCarriesTheLimitAndFilter()

	/**
	 * A filter that is not a map is dropped from both request grammars.
	 *
	 * @return void
	 */
	public function testANonArrayFilterIsDropped(): void {
		$source = ['register' => 'r', 'schema' => 's', 'filter' => 'status=open'];

		self::assertSame(
			['register' => 'r', 'schema' => 's', '_limit' => 5],
			$this->projector()->objectsRequestParams(dataSource: $source, limit: 5)
		);
		self::assertSame(
			['metric' => 'count', 'filter' => []],
			$this->projector()->aggregationsRequestParams(dataSource: $source)
		);
	}//end testANonArrayFilterIsDropped()

	/**
	 * A row's title is the first non-empty of title, name, label, summary, subject, then id, then uuid.
	 *
	 * @return void
	 */
	public function testTheRowTitleFallsBackInOrder(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				['title' => '', 'name' => 'Named', 'label' => 'Labelled'],
				['label' => 'Labelled', 'summary' => 'Summed'],
				['summary' => 'Summed', 'subject' => 'Subject'],
				['subject' => 'Subject', 'id' => 9],
				['title' => 5, 'id' => 9],
				['uuid' => 'abc'],
				[],
			]
		);

		$titles = array_map(
			static fn ($item): string => $item->getTitle(),
			$this->projector()->project(
				descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's'])
			)->getItems()
		);

		self::assertSame(['Named', 'Labelled', 'Summed', 'Subject', '9', 'abc', ''], $titles);
	}//end testTheRowTitleFallsBackInOrder()

	/**
	 * The since id is the row's updated time, else its created time, else empty; the icon is empty.
	 *
	 * @return void
	 */
	public function testTheSinceIdPrefersUpdatedOverCreated(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				['title' => 'a', 'updated' => '2026-02-02', 'created' => '2026-01-01'],
				['title' => 'b', 'created' => '2026-01-01'],
				['title' => 'c'],
			]
		);

		$items = $this->projector()->project(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's'])
		)->getItems();

		self::assertSame('2026-02-02', $items[0]->getSinceId());
		self::assertSame('2026-01-01', $items[1]->getSinceId());
		self::assertSame('', $items[2]->getSinceId());
		self::assertSame('', $items[0]->getIconUrl());
		self::assertSame('', $items[1]->getSubtitle());
	}//end testTheSinceIdPrefersUpdatedOverCreated()

	/**
	 * No rows is the empty state, not an empty list of items with no message.
	 *
	 * @return void
	 */
	public function testNoRowsIsTheEmptyState(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$items = $this->projector()->project(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's'])
		);

		self::assertSame([], $items->getItems());
		self::assertSame('Open Pet Store to see this widget.', $items->getEmptyContentMessage());
	}//end testNoRowsIsTheEmptyState()

	/**
	 * A panel link wins over the generated app page link, and the URL generator is not asked.
	 *
	 * @return void
	 */
	public function testAPanelLinkWins(): void {
		$this->objectService->method('findAll')->willReturn([['title' => 'One']]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->never())->method('linkToRouteAbsolute');

		$items = $this->projector(urls: $urls)->project(
			descriptor: $this->descriptor(
				dataSource: ['register' => 'r', 'schema' => 's'],
				panel: ['title' => 'Open cases', 'link' => 'https://elsewhere.test/cases']
			)
		);

		self::assertSame('https://elsewhere.test/cases', $items->getItems()[0]->getLink());
	}//end testAPanelLinkWins()

	/**
	 * The app page link is the builder route for the app's slug, joined to the page route with one slash.
	 *
	 * @return void
	 */
	public function testTheAppPageLinkJoinsTheRouteWithOneSlash(): void {
		$this->objectService->method('findAll')->willReturn([['title' => 'One']]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')
			->with('buildiq.dashboard.builder', ['slug' => 'pet-store'])
			->willReturn('https://example.test/apps/buildiq/builder/pet-store/');

		$link = fn (string $route): string => $this->projector(urls: $urls)->project(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's'], pageRoute: $route)
		)->getItems()[0]->getLink();

		self::assertSame('https://example.test/apps/buildiq/builder/pet-store/cases/open', $link('/cases/open'));
		self::assertSame('https://example.test/apps/buildiq/builder/pet-store/cases', $link('cases'));
		self::assertSame('https://example.test/apps/buildiq/builder/pet-store/', $link('/'));
	}//end testTheAppPageLinkJoinsTheRouteWithOneSlash()

	/**
	 * A counted aggregate shows the number as its title and the panel title as subtitle.
	 *
	 * @return void
	 */
	public function testACountShowsTheNumberUnderThePanelTitle(): void {
		$this->aggregations->expects($this->once())->method('value')
			->with('r', 's', ['metric' => 'count', 'filter' => []])
			->willReturn(12.5);

		$items = $this->projector()->project(
			descriptor: $this->descriptor(dataSource: ['register' => 'r', 'schema' => 's', 'aggregate' => 'count'])
		)->getItems();

		self::assertCount(1, $items);
		self::assertSame('12.5', $items[0]->getTitle());
		self::assertSame('Open cases', $items[0]->getSubtitle());
	}//end testACountShowsTheNumberUnderThePanelTitle()

	/**
	 * Without an app name the empty state names the app by its slug.
	 *
	 * @return void
	 */
	public function testTheEmptyStateFallsBackToTheSlug(): void {
		$items = $this->projector()->project(
			descriptor: $this->descriptor(dataSource: ['graphql' => '{ x }'], appName: '')
		);

		self::assertSame('Open pet-store to see this widget.', $items->getEmptyContentMessage());
	}//end testTheEmptyStateFallsBackToTheSlug()

	/**
	 * Build the projector under test.
	 *
	 * @param IURLGenerator|null $urls The URL generator; a slug-based stub when null.
	 *
	 * @return WidgetItemProjector The projector.
	 */
	private function projector(?IURLGenerator $urls = null): WidgetItemProjector {
		if ($urls === null) {
			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('linkToRouteAbsolute')->willReturnCallback(
				static fn (string $route, array $params): string
					=> 'https://example.test/apps/buildiq/builder/'.$params['slug']
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		return new WidgetItemProjector(
			objectService: $this->objectService,
			aggregations: $this->aggregations,
			urlGenerator: $urls,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end projector()

	/**
	 * A descriptor for one promoted placement.
	 *
	 * @param array<string,mixed> $dataSource The placement's data source.
	 * @param array<string,mixed> $panel      The panel.
	 * @param string              $pageRoute  The page route.
	 * @param string              $appName    The app name.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function descriptor(
		array $dataSource,
		array $panel = ['title' => 'Open cases'],
		string $pageRoute = '/overview',
		string $appName = 'Pet Store'
	): WidgetDescriptor {
		return new WidgetDescriptor(
			application: new WidgetApplication(
				uuid: '11111111-2222-3333-4444-555555555555',
				slug: 'pet-store',
				name: $appName
			),
			principals: ['group:*'],
			entryId: 'open-cases',
			widgetKey: 'object-table',
			panel: $panel,
			placement: ['pageRoute' => $pageRoute, 'dataSource' => $dataSource]
		);
	}//end descriptor()
}//end class
