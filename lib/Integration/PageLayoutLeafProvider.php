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
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003, REQ-OBPL-006)
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Integration;

use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\Buildiq\Service\PageLayoutLayerStack;
use OCA\Buildiq\Service\PageLayoutPresenter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
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
	 * @param LayoutDeltaService $deltas The keyed-delta merge and the base fingerprint.
	 * @param PageLayoutLayerStack $layers Which layers apply to this caller and this object, and in which order.
	 * @param PageLayoutPresenter $presenter The shape a resolved layout takes on its way out.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LayoutDeltaService $deltas,
		private readonly PageLayoutLayerStack $layers,
		private readonly PageLayoutPresenter $presenter,
		private readonly LoggerInterface $logger,
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
	 * @return string The group.
	 */
	public function getGroup(): string {
		return 'Design';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string The app id.
	 */
	public function getRequiredApp(): string {
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
		// The host object comes with the call when the consumer already has it,
		// and is read here only when it does not: this path runs on every detail
		// page, and a second read of an object the caller just handed over is a
		// query nobody asked for.
		$object = ($filters['object'] ?? null);
		if (is_array($object) === false) {
			$object = $this->readHost(register: $register, schema: $schema, objectId: $objectId);
		}

		if ($object === null) {
			return ['items' => [], 'total' => 0, 'nextCursor' => null, 'appliedLayers' => [], 'withheld' => []];
		}

		$published = $this->publishedLayoutsFor(register: $register, schema: $schema);
		$stack = $this->layers->orderLayers(published: $published, object: $object);

		$composed = [];
		$applied = [];
		$withheld = [];

		// The base an override is cut against is the stack of WHOLE layouts
		// beneath it, frozen before the first patch lands. Checking each patch
		// against the running composition instead would make an override's base
		// depend on which OTHER overrides the caller happens to match, so the
		// same override would read as drifted for one colleague and current for
		// the next, and stacking two overrides could never work at all.
		$overrideBase = [];

		foreach ($stack as $layer) {
			$delta = ($layer['layoutDelta'] ?? null);

			if (is_array($delta) === false || $delta === []) {
				// A whole layout, not a patch: this is the pre-override shape and
				// it keeps working exactly as it did.
				// The first whole layout IS the composition; a later one merges
				// its patchable parts over what is already there.
				$stacked = $layer;
				if ($composed !== []) {
					$stacked = $this->deltas->merge($composed, $this->layers->patchableOf(layout: $layer));
				}

				$composed = $stacked;

				// Only a layout for EVERYONE redefines the base an override is
				// pinned to. A whole layout bound to one group still composes
				// into what that group is served, but letting it move the frozen
				// base would make the same override read as current for one
				// colleague and drifted for the next, which is the caller
				// dependence this design exists to remove.
				if ((string)($this->layers->audienceOf(layout: $layer)['kind'] ?? 'everyone') === 'everyone') {
					$overrideBase = $composed;
				}

				$applied[] = $this->layers->describeLayer(layer: $layer);
				continue;
			}

			if ($this->deltas->isCurrent($overrideBase, (string)($layer['baseFingerprint'] ?? '')) === false) {
				// Drift. The override is neither applied nor dropped: the base is
				// served, the answer says so, and the stored patch survives so a
				// maintainer can re-cut it.
				$withheld[] = [
					'id' => (string)($layer['id'] ?? ''),
					'name' => (string)($layer['name'] ?? ''),
					'audience' => $this->layers->audienceOf(layout: $layer),
					'reason' => 'drift',
					'orphanedPaths' => $this->deltas->orphanedPaths($overrideBase, $delta),
				];

				$this->flagForReview(layer: $layer);
				continue;
			}

			$composed = $this->deltas->merge($composed, $delta);
			$applied[] = $this->layers->describeLayer(layer: $layer);
		}

		if ($composed === []) {
			// Nothing answers, and the consumer renders its manifest unchanged.
			// This is the path an instance without any layout takes, and it is
			// the reason buildiq can be absent entirely.
			return ['items' => [], 'total' => 0, 'nextCursor' => null, 'appliedLayers' => [], 'withheld' => $withheld];
		}

		return [
			'items' => [$this->presenter->serve(layout: $composed, schemaWide: $this->layers->schemaWideOf(layers: $stack), object: $object)],
			'total' => 1,
			'nextCursor' => null,
			// A handler who sees fewer tabs than a colleague has no way to learn
			// why, and neither has the administrator who is asked about it. This
			// is that answer. A consumer is never required to read it to render.
			'appliedLayers' => $applied,
			'withheld' => $withheld,
		];
	}//end list()

	/**
	 * The base an override is cut against, composed the way resolution composes
	 * it.
	 *
	 * The save path has to stamp the fingerprint of exactly the stack the
	 * resolver will later check against, and the honest way to guarantee that is
	 * to compose it here, in the class that resolves. A second composition in
	 * the authoring service would be a second notion of the base, and the first
	 * override to disagree with it would be withheld from every caller for a
	 * reason nobody could see.
	 *
	 * Whole layouts only, and only those for everyone: those are the layers that
	 * are frozen before the first patch lands.
	 *
	 * @param array<string, mixed> $override The override about to be saved, for its register, schema, type scope and baseRef.
	 *
	 * @return array<string, mixed> The composed base, empty when nothing published applies.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function baseForOverride(array $override): array {
		$published = $this->publishedLayoutsFor(
			(string)($override['register'] ?? ''),
			(string)($override['schema'] ?? '')
		);

		$byId = [];
		$schemaWide = null;
		$typed = null;
		$typeProperty = (string)($override['typeProperty'] ?? '');
		$typeValue = (string)($override['typeValue'] ?? '');

		foreach ($published as $layout) {
			$delta = ($layout['layoutDelta'] ?? null);
			if (is_array($delta) === true && $delta !== []) {
				continue;
			}

			if ((string)($this->layers->audienceOf(layout: $layout)['kind'] ?? 'everyone') !== 'everyone') {
				continue;
			}

			$id = (string)($layout['id'] ?? '');
			if ($id !== '') {
				$byId[$id] = $layout;
			}

			if ((string)($layout['typeProperty'] ?? '') === '' || (string)($layout['typeValue'] ?? '') === '') {
				$schemaWide = ($schemaWide ?? $layout);
				continue;
			}

			if ($typeProperty !== ''
				&& (string)$layout['typeProperty'] === $typeProperty
				&& (string)$layout['typeValue'] === $typeValue
			) {
				$typed = ($typed ?? $layout);
			}
		}

		$layers = [];
		$baseRef = (string)($override['baseRef'] ?? '');
		if ($baseRef !== '' && array_key_exists($baseRef, $byId) === true) {
			$layers[] = $byId[$baseRef];
		}

		foreach ([$schemaWide, $typed] as $layout) {
			if ($layout !== null && in_array($layout, $layers, true) === false) {
				$layers[] = $layout;
			}
		}

		$composed = [];
		foreach ($layers as $layer) {
			$composed = ($composed === [] ? $layer : $this->deltas->merge($composed, $this->layers->patchableOf(layout: $layer)));
		}

		return $composed;
	}//end baseForOverride()

	/**
	 * Move a drifted override to `needs-review`, which is what fires the
	 * declarative notification to its maintainer.
	 *
	 * Idempotent: an override already in review is left alone, so a busy page
	 * does not notify its maintainer once per request. A write that fails is
	 * logged and swallowed, because a screen must not fail to render because an
	 * override drifted (REQ-OBSO-003).
	 *
	 * @param array<string, mixed> $layer The drifted override.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	private function flagForReview(array $layer): void {
		if ((string)($layer['status'] ?? '') !== 'published') {
			return;
		}

		$layer['status'] = 'needs-review';

		try {
			$this->objectService->saveObject(
				object: $layer,
				register: $this->registerSlug(),
				schema: self::SCHEMA,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Buildiq: a drifted screen override could not be flagged for review',
				['layout' => (string)($layer['id'] ?? ''), 'exception' => $e->getMessage()]
			);
		}
	}//end flagForReview()

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
		$listed = $this->list(register: $register, schema: $schema, objectId: $objectId);
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
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameters are mandated by the
	 *   IntegrationProvider interface; this method refuses the call, and a provider that
	 *   dropped them could not be registered as a leaf at all.
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
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameters are mandated by the
	 *   IntegrationProvider interface; this method refuses the call, and a provider that
	 *   dropped them could not be registered as a leaf at all.
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
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameters are mandated by the
	 *   IntegrationProvider interface; this method refuses the call, and a provider that
	 *   dropped them could not be registered as a leaf at all.
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
