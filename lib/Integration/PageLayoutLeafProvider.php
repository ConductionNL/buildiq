<?php

/**
 * Page Layout Leaf Provider
 *
 * `buildiq-page-layout`. A consuming app asks: what should this object's detail
 * page show. Buildiq answers with at most ONE layout, and the app merges it
 * over its own manifest config.
 *
 * AT MOST ONE, AND THE FALL-BACK IS THE WHOLE DESIGN
 * --------------------------------------------------
 * The type value's layout wins. Failing that, the schema-wide one. Failing
 * that, nothing at all, and the app renders its manifest unchanged. That last
 * case is what makes buildiq optional: an instance without it behaves exactly
 * as it did before, and nobody has to install a builder to see a case.
 *
 * A type-specific header REPLACES the schema-wide header whole. Merging it
 * field by field would leave a page showing four fields an administrator
 * thought they had removed, with nothing on the page to say where they came
 * from.
 *
 * A widget whose display conditions do not hold is NOT SENT. Sending it with a
 * flag and asking the consumer to hide it would put the data on the page for
 * anyone who opens the network tab, which is the same mistake as a hidden form
 * field.
 *
 * Buildiq never writes the consuming app's object, and this leaf offers no
 * create at all: a layout is authored in buildiq's own editor (ADR-066).
 *
 * @category Integration
 * @package  OCA\Buildiq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003, REQ-OBPL-006, REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Resolves and serves the detail-page layout for a host object.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
 */
final class PageLayoutLeafProvider implements IntegrationProvider {
	/**
	 * The leaf id, equal on both halves so gate-24 can pair them.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'buildiq-page-layout';

	/**
	 * The schema holding layouts.
	 *
	 * @var string
	 */
	private const SCHEMA = 'pageLayout';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The leaf id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::LEAF_ID;
	}//end getId()

	/**
	 * The label shown on the leaf.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return 'Page layout';
	}//end getLabel()

	/**
	 * The MDI icon name.
	 *
	 * @return string The icon.
	 */
	public function getIcon(): string {
		return 'ViewDashboardOutline';
	}//end getIcon()

	/**
	 * The group the leaf sorts under.
	 *
	 * @return string|null The group.
	 */
	public function getGroup(): ?string {
		return 'Design';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string|null The app id.
	 */
	public function getRequiredApp(): ?string {
		return 'buildiq';
	}//end getRequiredApp()

	/**
	 * Layouts live in buildiq's own register.
	 *
	 * @return string The storage strategy.
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source.
	 *
	 * @return string|null The source.
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever buildiq is installed.
	 *
	 * @return bool True.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Reading a layout needs no right of its own; it is page furniture, and the
	 * object's own permissions still decide what the page can show.
	 *
	 * @return string|null Null.
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()

	/**
	 * The one layout that applies to this host object, or nothing.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $filters Optional `object`, the host object itself, so conditions can be evaluated without a second read.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor}` envelope, with at most one item.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003, REQ-OBPL-006)
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$object = (is_array($filters['object'] ?? null) === true ? $filters['object'] : $this->readHost($register, $schema, $objectId));
		if ($object === null) {
			return ['items' => [], 'total' => 0, 'nextCursor' => null];
		}

		$published = $this->publishedLayoutsFor($register, $schema);

		$typed = null;
		$schemaWide = null;
		foreach ($published as $layout) {
			$typeProperty = (string)($layout['typeProperty'] ?? '');
			$typeValue = (string)($layout['typeValue'] ?? '');

			if ($typeProperty === '' || $typeValue === '') {
				$schemaWide = ($schemaWide ?? $layout);
				continue;
			}

			if ((string)($object[$typeProperty] ?? '') === $typeValue) {
				$typed = ($typed ?? $layout);
			}
		}

		$resolved = ($typed ?? $schemaWide);
		if ($resolved === null) {
			// Nothing answers, and the consumer renders its manifest unchanged.
			// This is the path an instance without any layout takes, and it is
			// the reason buildiq can be absent entirely.
			return ['items' => [], 'total' => 0, 'nextCursor' => null];
		}

		return [
			'items' => [$this->serve($resolved, $schemaWide, $object)],
			'total' => 1,
			'nextCursor' => null,
		];
	}//end list()

	/**
	 * One layout by id, scoped to the host object it applies to.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The layout id.
	 *
	 * @return array<string, mixed> The served layout.
	 *
	 * @throws RuntimeException When that layout does not apply here.
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		$listed = $this->list($register, $schema, $objectId);
		foreach ($listed['items'] as $layout) {
			if ((string)$layout['id'] === $entityId) {
				return $layout;
			}
		}

		throw new RuntimeException('404 No published layout with that id applies to this object.');
	}//end get()

	/**
	 * A layout is authored in buildiq's editor, never appended from a consumer.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		throw new RuntimeException('A page layout is authored in buildiq; this leaf reads and never appends.');
	}//end create()

	/**
	 * The same for an edit.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The layout id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		throw new RuntimeException('A page layout is edited in buildiq, where the rules that validate it live.');
	}//end update()

	/**
	 * The same for a delete.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The layout id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		throw new RuntimeException('A page layout is retired in buildiq, not deleted from a consuming app.');
	}//end delete()

	/**
	 * Health of the leaf.
	 *
	 * @return array<string, mixed> The health report.
	 */
	public function health(): array {
		return ['status' => 'ok', 'leaf' => self::LEAF_ID];
	}//end health()

	/**
	 * Turn a resolved layout into what the consumer renders.
	 *
	 * @param array<string, mixed> $layout The resolved layout.
	 * @param array<string, mixed>|null $schemaWide The schema-wide layout, for the parts this one does not declare.
	 * @param array<string, mixed> $object The host object, for the display conditions.
	 *
	 * @return array<string, mixed> The served layout.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006, REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
	 */
	private function serve(array $layout, ?array $schemaWide, array $object): array {
		$tabs = [];
		foreach (($layout['tabs'] ?? []) as $tab) {
			if (is_array($tab) === false) {
				continue;
			}

			if (is_array($tab['widgets'] ?? null) === true) {
				$tab['widgets'] = $this->keepWidgetsThatHold($tab['widgets'], $object);
			}

			$tabs[] = $tab;
		}

		usort($tabs, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));

		return [
			'id' => (string)($layout['id'] ?? ''),
			'targetApp' => (string)($layout['targetApp'] ?? ''),
			'register' => (string)($layout['register'] ?? ''),
			'schema' => (string)($layout['schema'] ?? ''),
			'typeProperty' => (string)($layout['typeProperty'] ?? ''),
			'typeValue' => (string)($layout['typeValue'] ?? ''),
			// A type header REPLACES the schema-wide header whole. The fall-back
			// below is for a type layout that declares NO header at all, which is
			// a different thing from one that declares a shorter header.
			'header' => (is_array($layout['header'] ?? null) === true
				? $layout['header']
				: (is_array($schemaWide['header'] ?? null) === true ? $schemaWide['header'] : null)),
			'tabs' => $tabs,
			'widgets' => $this->keepWidgetsThatHold(($layout['widgets'] ?? []), $object),
			'taskList' => (is_array($layout['taskList'] ?? null) === true
				? $layout['taskList']
				: (is_array($schemaWide['taskList'] ?? null) === true ? $schemaWide['taskList'] : null)),
			'uploadFields' => (is_array($layout['uploadFields'] ?? null) === true && $layout['uploadFields'] !== []
				? $layout['uploadFields']
				: (is_array($schemaWide['uploadFields'] ?? null) === true ? $schemaWide['uploadFields'] : [])),
		];
	}//end serve()

	/**
	 * Drop the widgets whose display conditions do not hold, keeping the stored
	 * order of the rest.
	 *
	 * Dropped, not flagged. A widget sent with a "hidden" flag is still on the
	 * wire and still readable by anyone who opens the network tab.
	 *
	 * @param mixed $widgets The widget list.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The widgets that hold.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006)
	 */
	private function keepWidgetsThatHold(mixed $widgets, array $object): array {
		if (is_array($widgets) === false) {
			return [];
		}

		$kept = [];
		foreach ($widgets as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			if ($this->conditionsHold(($widget['conditions'] ?? []), $object) === true) {
				$kept[] = $widget;
			}
		}

		usort($kept, static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)));

		return $kept;
	}//end keepWidgetsThatHold()

	/**
	 * Whether every condition holds against the host object. An empty list always
	 * holds, which is what makes conditions opt-in.
	 *
	 * @param mixed $conditions The conditions.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return bool True when they all hold.
	 */
	private function conditionsHold(mixed $conditions, array $object): bool {
		if (is_array($conditions) === false || $conditions === []) {
			return true;
		}

		foreach ($conditions as $condition) {
			if (is_array($condition) === false) {
				continue;
			}

			$field = (string)($condition['field'] ?? '');
			$operator = (string)($condition['operator'] ?? '');
			$expected = (string)($condition['value'] ?? '');
			$actual = ($object[$field] ?? null);
			$actualText = (is_scalar($actual) === true ? (string)$actual : '');

			$holds = match ($operator) {
				'equals' => ($actualText === $expected),
				'notEquals' => ($actualText !== $expected),
				'isEmpty' => ($actualText === ''),
				'isNotEmpty' => ($actualText !== ''),
				'contains' => ($expected !== '' && str_contains($actualText, $expected)),
				// An operator nothing evaluates must not silently pass: a widget
				// shown on every case because of a typo is worse than one missing.
				default => false,
			};

			if ($holds === false) {
				return false;
			}
		}

		return true;
	}//end conditionsHold()

	/**
	 * Read the host object, so its type value and its condition fields can be
	 * looked at.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string $objectId The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function readHost(string $register, string $schema, string $objectId): ?array {
		try {
			$rows = $this->objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll(['filters' => ['id' => $objectId], 'limit' => 1]);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($rows) === false || $rows === [] || is_array($rows[0]) === false) {
			return null;
		}

		return $rows[0];
	}//end readHost()

	/**
	 * Every published layout for this register and schema.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The layouts.
	 */
	private function publishedLayoutsFor(string $register, string $schema): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['register' => $register], 'limit' => 500]);

		if (is_array($rows) === false) {
			return [];
		}

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === false
				|| (string)($row['status'] ?? '') !== 'published'
				|| (string)($row['register'] ?? '') !== $register
				|| (string)($row['schema'] ?? '') !== $schema
			) {
				continue;
			}

			$mine[] = $row;
		}

		return $mine;
	}//end publishedLayoutsFor()

	/**
	 * The register slug holding buildiq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('buildiq', 'register', 'buildiq');
	}//end registerSlug()
}//end class
