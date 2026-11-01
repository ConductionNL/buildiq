<?php

/**
 * Buildiq Widget Item Projector
 *
 * Computes on the server what a promoted widget computes in the browser, so
 * the Nextcloud mobile and desktop clients show real content instead of an
 * empty panel.
 *
 * A shape it cannot project honestly returns an empty item list carrying a
 * message that points at the app page. It never returns zero as a stand-in: a
 * returned zero is indistinguishable from a real zero, so the reader would see
 * a number that was never computed.
 *
 * Per ADR-031 §Exceptions(1) this is imperative because it answers
 * `IAPIWidgetV2::getItemsV2()`, a Nextcloud dashboard API whose vocabulary
 * OpenRegister has no extension for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Dashboard
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-clients-that-cannot-run-the-widget-receive-projected-items
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-an-unprojectable-widget-returns-an-honest-empty-state
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-each-openregister-endpoint-is-queried-in-its-own-filter-grammar
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Dashboard;

use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Projects a promoted widget's declarative data source into dashboard items.
 */
class WidgetItemProjector {
	/**
	 * Widget types whose value is a rendering rather than a list, so there is
	 * nothing honest to project into items.
	 *
	 * @var array<int,string>
	 */
	public const UNPROJECTABLE_WIDGET_KEYS = ['chart', 'gauge', 'delta', 'countdown'];

	/**
	 * Properties tried, in order, as a row's display title.
	 *
	 * @var array<int,string>
	 */
	private const TITLE_FIELDS = ['title', 'name', 'label', 'summary', 'subject'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (objects endpoint).
	 * @param AggregationGateway $aggregations OpenRegister aggregations endpoint.
	 * @param IURLGenerator $urlGenerator URL generator for deep links.
	 * @param IL10N $l10n Translations for the empty-state messages.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationGateway $aggregations,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Project one promoted widget into dashboard items.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 * @param int $limit Maximum number of items to return.
	 *
	 * @return WidgetItems The items, or an honest empty state.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-clients-that-cannot-run-the-widget-receive-projected-items
	 */
	public function project(WidgetDescriptor $descriptor, int $limit = 7): WidgetItems {
		if ($this->isProjectable(descriptor: $descriptor) === false) {
			return $this->emptyState(descriptor: $descriptor);
		}

		if ($this->isAggregating(dataSource: $descriptor->dataSource) === true) {
			return $this->projectAggregate(descriptor: $descriptor);
		}

		return $this->projectRows(descriptor: $descriptor, limit: $limit);
	}//end project()

	/**
	 * The OBJECTS endpoint's request parameters for a row listing.
	 *
	 * 🔴 BARE filter keys. The objects endpoint reads `filter[x]` as the empty
	 * set, so a bracketed key here returns nothing and looks exactly like a
	 * widget with no matching rows. Its sibling
	 * {@see self::aggregationsRequestParams()} is the opposite, deliberately,
	 * and the two must not be tidied into one helper.
	 *
	 * @param array<string,mixed> $dataSource The placement's data source.
	 * @param int $limit Row limit.
	 *
	 * @return array<string,mixed> Request parameters with bare filter keys.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-each-openregister-endpoint-is-queried-in-its-own-filter-grammar
	 */
	public function objectsRequestParams(array $dataSource, int $limit): array {
		$params = [
			'register' => (string)($dataSource['register'] ?? ''),
			'schema' => (string)($dataSource['schema'] ?? ''),
		];

		foreach ($this->declaredFilter(dataSource: $dataSource) as $key => $value) {
			// Bare, at the top level. Not $params['filter'][$key].
			$params[(string)$key] = $value;
		}

		$params['_limit'] = $limit;
		return $params;
	}//end objectsRequestParams()

	/**
	 * The AGGREGATIONS endpoint's request parameters for a scalar aggregate.
	 *
	 * 🔴 BRACKETED filter keys. The aggregations endpoint DROPS a bare key and
	 * returns the whole register, so a bare key here yields a confident wrong
	 * number rather than an error. `['filter' => ['x' => 'v']]` is exactly
	 * what `filter[x]=v` parses into.
	 *
	 * @param array<string,mixed> $dataSource The placement's data source.
	 *
	 * @return array<string,mixed> Request parameters with a nested filter map.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-each-openregister-endpoint-is-queried-in-its-own-filter-grammar
	 */
	public function aggregationsRequestParams(array $dataSource): array {
		return [
			'metric' => 'count',
			'filter' => $this->declaredFilter(dataSource: $dataSource),
		];
	}//end aggregationsRequestParams()

	/**
	 * Project a declarative data source into one item per row.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 * @param int $limit Row limit.
	 *
	 * @return WidgetItems One item per returned row.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-clients-that-cannot-run-the-widget-receive-projected-items
	 */
	private function projectRows(WidgetDescriptor $descriptor, int $limit): WidgetItems {
		$params = $this->objectsRequestParams(dataSource: $descriptor->dataSource, limit: $limit);
		$rowLimit = $params['_limit'];
		unset($params['_limit']);

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => $params,
					'limit' => $rowLimit,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Buildiq: row projection for dashboard widget ' . $descriptor->id
				. ' failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return $this->emptyState(descriptor: $descriptor);
		}

		$link = $this->appPageLink(descriptor: $descriptor);
		$items = [];
		foreach ($rows as $row) {
			$normalised = $this->normaliseRow(row: $row);
			$items[] = new WidgetItem(
				title: $this->rowTitle(row: $normalised),
				subtitle: (string)($normalised['description'] ?? ''),
				link: $link,
				iconUrl: '',
				sinceId: (string)($normalised['updated'] ?? $normalised['created'] ?? '')
			);
		}

		if ($items === []) {
			return $this->emptyState(descriptor: $descriptor);
		}

		return new WidgetItems(items: $items);
	}//end projectRows()

	/**
	 * Project an aggregating data source into a single item whose title is the number.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return WidgetItems Exactly one item, or an honest empty state.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-clients-that-cannot-run-the-widget-receive-projected-items
	 */
	private function projectAggregate(WidgetDescriptor $descriptor): WidgetItems {
		$value = $this->aggregations->value(
			register: (string)($descriptor->dataSource['register'] ?? ''),
			schema: (string)($descriptor->dataSource['schema'] ?? ''),
			requestParams: $this->aggregationsRequestParams(dataSource: $descriptor->dataSource)
		);

		if ($value === null) {
			// Not "0". A returned zero is indistinguishable from a real zero.
			return $this->emptyState(descriptor: $descriptor);
		}

		return new WidgetItems(
			items: [
				new WidgetItem(
					title: (string)$value,
					subtitle: $descriptor->title,
					link: $this->appPageLink(descriptor: $descriptor)
				),
			]
		);
	}//end projectAggregate()

	/**
	 * Whether this widget's shape can be projected honestly on the server.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return bool True when a projection is possible.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-an-unprojectable-widget-returns-an-honest-empty-state
	 */
	private function isProjectable(WidgetDescriptor $descriptor): bool {
		$dataSource = $descriptor->dataSource;

		// A raw GraphQL source is the author's own query with its own
		// selectors. Re-implementing it here is guessing.
		if (isset($dataSource['graphql']) === true) {
			return false;
		}

		// A chart or a gauge IS a rendering. There is no list behind it.
		if (in_array($descriptor->widgetKey, self::UNPROJECTABLE_WIDGET_KEYS, strict: true) === true) {
			return false;
		}

		// The object form of `aggregate` is a categorical group-by feeding a
		// chart, not a scalar. It has no single number to show.
		$aggregate = ($dataSource['aggregate'] ?? null);
		if ($aggregate !== null && is_string($aggregate) === false) {
			return false;
		}

		$register = (string)($dataSource['register'] ?? '');
		$schema = (string)($dataSource['schema'] ?? '');
		return ($register !== '' && $schema !== '');
	}//end isProjectable()

	/**
	 * Whether the data source aggregates to a single number.
	 *
	 * @param array<string,mixed> $dataSource The placement's data source.
	 *
	 * @return bool True for the scalar `aggregate: 'count'` shorthand.
	 */
	private function isAggregating(array $dataSource): bool {
		return (($dataSource['aggregate'] ?? null) === 'count');
	}//end isAggregating()

	/**
	 * The declared filter map, as written in the manifest.
	 *
	 * @param array<string,mixed> $dataSource The placement's data source.
	 *
	 * @return array<string,mixed> The filter map, possibly empty.
	 */
	private function declaredFilter(array $dataSource): array {
		$filter = ($dataSource['filter'] ?? []);
		if (is_array($filter) === false) {
			return [];
		}

		return $filter;
	}//end declaredFilter()

	/**
	 * An empty item list whose message names the app page to open instead.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return WidgetItems An empty list with a pointer to the app page.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-an-unprojectable-widget-returns-an-honest-empty-state
	 */
	private function emptyState(WidgetDescriptor $descriptor): WidgetItems {
		$appName = $descriptor->applicationName;
		if ($appName === '') {
			$appName = $descriptor->applicationSlug;
		}

		return new WidgetItems(
			items: [],
			emptyContentMessage: $this->l10n->t(
				'Open %s to see this widget.',
				[$appName]
			)
		);
	}//end emptyState()

	/**
	 * Deep link into the app page the widget is placed on.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return string An absolute link.
	 */
	private function appPageLink(WidgetDescriptor $descriptor): string {
		if ($descriptor->link !== '') {
			return $descriptor->link;
		}

		$base = $this->urlGenerator->linkToRouteAbsolute(
			'buildiq.dashboard.builder',
			['slug' => $descriptor->applicationSlug]
		);

		$route = ltrim($descriptor->pageRoute, '/');
		if ($route === '') {
			return $base;
		}

		return rtrim($base, '/') . '/' . $route;
	}//end appPageLink()

	/**
	 * Pick a row's display title from the schema's usual title fields.
	 *
	 * @param array<string,mixed> $row The normalised row.
	 *
	 * @return string The row's title.
	 */
	private function rowTitle(array $row): string {
		foreach (self::TITLE_FIELDS as $field) {
			$value = ($row[$field] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return (string)($row['id'] ?? $row['uuid'] ?? '');
	}//end rowTitle()

	/**
	 * Coerce an OpenRegister result entry to an associative array.
	 *
	 * @param mixed $row The OpenRegister object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseRow(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$inner = $row->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseRow()
}//end class
