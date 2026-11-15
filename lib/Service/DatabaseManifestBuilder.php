<?php

/**
 * DatabaseManifestBuilder — the pages of an app made from a connected database.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Start from a database" makes an app from tables OpenRegister already serves
 * from a database source. The pages follow from the tables, so there is nothing
 * to ask a model: per table one index page (its first eight scalar columns),
 * one detail page and one menu entry, all bound to the database register and
 * the table's schema as they are, never to the version's own register. When the
 * source does not take writes, the pages carry the props that hide the add,
 * edit and delete buttons (REQ-BQDB-002), so the app never offers a write that
 * can only fail. Pure: it reads nothing and writes nothing.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Builds a manifest of index, detail and menu entries from database tables.
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */
class DatabaseManifestBuilder {

	/**
	 * The most columns an index page starts with; the maker trims or adds in the column builder.
	 *
	 * @var integer
	 */
	public const MAX_COLUMNS = 8;

	/**
	 * The JSON Schema types an index column can show as a cell.
	 *
	 * @var list<string>
	 */
	private const SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

	/**
	 * The manifest for the chosen tables of a database register.
	 *
	 * @param string                           $register The database register's slug.
	 * @param list<array<string, mixed>>       $schemas  The chosen tables: each `{slug, title?, properties?}`, in menu order.
	 * @param bool                             $writable Whether the database's source takes writes.
	 *
	 * @return array{version: string, menu: list<array<string, mixed>>, pages: list<array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When the register is empty or no table has a slug.
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	public function build(string $register, array $schemas, bool $writable): array {
		if (trim($register) === '') {
			throw new InvalidArgumentException('A database register is required.');
		}

		$menu  = [];
		$pages = [];
		$seen  = [];
		foreach ($schemas as $schema) {
			$slug    = $this->slugOf(schema: $schema);
			$segment = $this->routeSegmentOf(slug: $slug);
			// A table whose slug gives no route, or the same route as one before it, gets no pages.
			if ($segment === '' || isset($seen[$segment]) === true) {
				continue;
			}

			$seen[$segment] = true;
			$title          = $this->titleOf(schema: $schema, slug: $slug);
			$pageId         = $this->pageIdOf(slug: $slug);
			$route          = '/' . $segment;

			$pages[] = $this->indexPage(
				page: ['id' => $pageId . 'Index', 'route' => $route, 'title' => $title],
				binding: ['register' => $register, 'schema' => $slug],
				columns: $this->columnsOf(schema: $schema),
				writable: $writable
			);
			$pages[] = $this->detailPage(
				page: ['id' => $pageId . 'Detail', 'route' => $route . '/:id', 'title' => $title],
				binding: ['register' => $register, 'schema' => $slug],
				writable: $writable
			);
			$menu[]  = [
				'id' => $segment,
				'label' => $title,
				'route' => $pageId . 'Index',
				'order' => (10 * (count($menu) + 1)),
			];
		}//end foreach

		if ($pages === []) {
			throw new InvalidArgumentException('Choose at least one table of the database.');
		}

		return ['version' => '1.0.0', 'menu' => $menu, 'pages' => $pages];
	}//end build()

	/**
	 * An index page over one table.
	 *
	 * @param array{id: string, route: string, title: string} $page     The page's id, route and title.
	 * @param array{register: string, schema: string}         $binding  The register and table.
	 * @param list<string>                                    $columns  The columns to show.
	 * @param bool                                            $writable Whether the source takes writes.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
	 */
	private function indexPage(array $page, array $binding, array $columns, bool $writable): array {
		$config = ['register' => $binding['register'], 'schema' => $binding['schema'], 'columns' => $columns];
		if ($writable === false) {
			$config['showAdd']          = false;
			$config['showEditAction']   = false;
			$config['showDeleteAction'] = false;
		}

		return ['id' => $page['id'], 'route' => $page['route'], 'type' => 'index', 'title' => $page['title'], 'config' => $config];
	}//end indexPage()

	/**
	 * A detail page over one row of a table.
	 *
	 * @param array{id: string, route: string, title: string} $page     The page's id, route and title.
	 * @param array{register: string, schema: string}         $binding  The register and table.
	 * @param bool                                            $writable Whether the source takes writes.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
	 */
	private function detailPage(array $page, array $binding, bool $writable): array {
		$config = ['register' => $binding['register'], 'schema' => $binding['schema']];
		if ($writable === false) {
			$config['showEditAction'] = false;
		}

		return ['id' => $page['id'], 'route' => $page['route'], 'type' => 'detail', 'title' => $page['title'], 'config' => $config];
	}//end detailPage()

	/**
	 * The first eight scalar properties of a table, in the table's order.
	 *
	 * @param array<string, mixed> $schema The table.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function columnsOf(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$columns = [];
		foreach ($properties as $name => $property) {
			if (is_string($name) === false || $name === '' || is_array($property) === false) {
				continue;
			}

			if ($this->isScalar(property: $property) === false) {
				continue;
			}

			$columns[] = $name;
			if (count($columns) === self::MAX_COLUMNS) {
				break;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Whether a property shows as a single cell: a scalar type, or a nullable one.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function isScalar(array $property): bool {
		$type = ($property['type'] ?? null);
		if (is_string($type) === true) {
			return in_array($type, self::SCALAR_TYPES, true);
		}

		if (is_array($type) === false) {
			return false;
		}

		$types = array_values(array_diff($type, ['null']));

		return count($types) === 1 && in_array($types[0], self::SCALAR_TYPES, true);
	}//end isScalar()

	/**
	 * A table's slug.
	 *
	 * @param mixed $schema The table.
	 *
	 * @return string The slug, or '' when there is none.
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function slugOf(mixed $schema): string {
		if (is_array($schema) === false || is_string($schema['slug'] ?? null) === false) {
			return '';
		}

		return trim($schema['slug']);
	}//end slugOf()

	/**
	 * A table's title: its own, or its slug in words with a capital ("permit_applications" is "Permit applications").
	 *
	 * @param array<string, mixed> $schema The table.
	 * @param string               $slug   Its slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function titleOf(array $schema, string $slug): string {
		$title = ($schema['title'] ?? null);
		if (is_string($title) === true && trim($title) !== '') {
			return trim($title);
		}

		return ucfirst(trim((string) preg_replace('/[\s_\-.]+/', ' ', $slug)));
	}//end titleOf()

	/**
	 * A page id from a slug: its words in PascalCase ("permit_applications" is "PermitApplications").
	 *
	 * @param string $slug The table's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function pageIdOf(string $slug): string {
		$words = preg_split('/[^A-Za-z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY);

		return implode('', array_map(static fn (string $word): string => ucfirst($word), ($words === false ? [] : $words)));
	}//end pageIdOf()

	/**
	 * A route segment from a slug: lower case, words joined by hyphens.
	 *
	 * @param string $slug The table's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	private function routeSegmentOf(string $slug): string {
		return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($slug)), '-');
	}//end routeSegmentOf()
}//end class
