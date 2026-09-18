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

use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
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
	 * @param IUserSession $userSession The calling user, whose audiences decide which overrides apply.
	 * @param IGroupManager $groupManager The caller's group and team membership.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LayoutDeltaService $deltas,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
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
			return ['items' => [], 'total' => 0, 'nextCursor' => null, 'appliedLayers' => [], 'withheld' => []];
		}

		$published = $this->publishedLayoutsFor($register, $schema);
		$layers = $this->orderLayers($published, $object);

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

		foreach ($layers as $layer) {
			$delta = ($layer['layoutDelta'] ?? null);

			if (is_array($delta) === false || $delta === []) {
				// A whole layout, not a patch: this is the pre-override shape and
				// it keeps working exactly as it did.
				$composed = ($composed === [] ? $layer : $this->deltas->merge($composed, $this->patchableOf($layer)));

				// Only a layout for EVERYONE redefines the base an override is
				// pinned to. A whole layout bound to one group still composes
				// into what that group is served, but letting it move the frozen
				// base would make the same override read as current for one
				// colleague and drifted for the next, which is the caller
				// dependence this design exists to remove.
				if ((string)($this->audienceOf($layer)['kind'] ?? 'everyone') === 'everyone') {
					$overrideBase = $composed;
				}

				$applied[] = $this->describeLayer($layer);
				continue;
			}

			if ($this->deltas->isCurrent($overrideBase, (string)($layer['baseFingerprint'] ?? '')) === false) {
				// Drift. The override is neither applied nor dropped: the base is
				// served, the answer says so, and the stored patch survives so a
				// maintainer can re-cut it.
				$withheld[] = [
					'id' => (string)($layer['id'] ?? ''),
					'name' => (string)($layer['name'] ?? ''),
					'audience' => $this->audienceOf($layer),
					'reason' => 'drift',
					'orphanedPaths' => $this->deltas->orphanedPaths($overrideBase, $delta),
				];

				$this->flagForReview($layer);
				continue;
			}

			$composed = $this->deltas->merge($composed, $delta);
			$applied[] = $this->describeLayer($layer);
		}

		if ($composed === []) {
			// Nothing answers, and the consumer renders its manifest unchanged.
			// This is the path an instance without any layout takes, and it is
			// the reason buildiq can be absent entirely.
			return ['items' => [], 'total' => 0, 'nextCursor' => null, 'appliedLayers' => [], 'withheld' => $withheld];
		}

		return [
			'items' => [$this->serve($composed, $this->schemaWideOf($layers), $object)],
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
	 * The published layers that apply to this caller and this object, in the
	 * order each patches the one before it: the base named by `baseRef`, the
	 * schema-wide layout, the type layout, the audience override, the user
	 * override (REQ-OBSO-005).
	 *
	 * @param array<int, array<string, mixed>> $published Every published layout for the register and schema.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The ordered layers.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004, REQ-OBSO-005)
	 */
	private function orderLayers(array $published, array $object): array {
		$byId = [];
		foreach ($published as $layout) {
			$id = (string)($layout['id'] ?? '');
			if ($id !== '') {
				$byId[$id] = $layout;
			}
		}

		$everyone = [];
		$audienceOverrides = [];
		$userOverrides = [];

		foreach ($published as $layout) {
			$audience = $this->audienceOf($layout);
			$kind = (string)($audience['kind'] ?? 'everyone');

			if ($kind === 'everyone') {
				$everyone[] = $layout;
				continue;
			}

			if ($this->callerIsIn($audience) === false) {
				continue;
			}

			if ($kind === 'user') {
				$userOverrides[] = $layout;
				continue;
			}

			$audienceOverrides[] = $layout;
		}

		$schemaWide = null;
		$typed = null;
		foreach ($everyone as $layout) {
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

		$audienceOverrides = $this->keepThoseForThisObject($audienceOverrides, $object);
		$userOverrides = $this->keepThoseForThisObject($userOverrides, $object);

		// A visitor with no account gets `portal` first; a signed-in caller gets
		// the narrower `team` before the wider `group`.
		$rank = ($this->callerHasNoAccount() === true
			? ['portal' => 0, 'team' => 1, 'group' => 2]
			: ['team' => 0, 'group' => 1, 'portal' => 2]);

		usort(
			$audienceOverrides,
			static function (array $a, array $b) use ($rank): int {
				$rankA = ($rank[(string)($a['audience']['kind'] ?? '')] ?? 9);
				$rankB = ($rank[(string)($b['audience']['kind'] ?? '')] ?? 9);

				return ($rankA <=> $rankB);
			}
		);

		$layers = [];

		$narrowest = ($userOverrides[0] ?? ($audienceOverrides[0] ?? ($typed ?? $schemaWide)));
		$baseRef = (string)($narrowest['baseRef'] ?? '');
		if ($baseRef !== '' && array_key_exists($baseRef, $byId) === true) {
			$layers[] = $byId[$baseRef];
		}

		foreach ([$schemaWide, $typed] as $layout) {
			if ($layout !== null && in_array($layout, $layers, true) === false) {
				$layers[] = $layout;
			}
		}

		foreach (array_merge($audienceOverrides, $userOverrides) as $layout) {
			if (in_array($layout, $layers, true) === false) {
				$layers[] = $layout;
			}
		}

		return $layers;
	}//end orderLayers()

	/**
	 * Keep the overrides that are for this object's type, or for the schema as a
	 * whole.
	 *
	 * @param array<int, array<string, mixed>> $overrides The overrides.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The ones that apply.
	 */
	private function keepThoseForThisObject(array $overrides, array $object): array {
		$kept = [];
		foreach ($overrides as $override) {
			$typeProperty = (string)($override['typeProperty'] ?? '');
			$typeValue = (string)($override['typeValue'] ?? '');

			if ($typeProperty === '' || $typeValue === '') {
				$kept[] = $override;
				continue;
			}

			if ((string)($object[$typeProperty] ?? '') === $typeValue) {
				$kept[] = $override;
			}
		}

		return $kept;
	}//end keepThoseForThisObject()

	/**
	 * The audience of a layout, with an unset audience reading as `everyone` so
	 * every layout stored before overrides existed keeps exactly its reach
	 * (REQ-OBSO-004).
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return array<string, string> The audience.
	 */
	private function audienceOf(array $layout): array {
		$audience = ($layout['audience'] ?? null);
		if (is_array($audience) === false || (string)($audience['kind'] ?? '') === '') {
			return ['kind' => 'everyone', 'ref' => ''];
		}

		return ['kind' => (string)$audience['kind'], 'ref' => (string)($audience['ref'] ?? '')];
	}//end audienceOf()

	/**
	 * Whether the CALLER is in this audience.
	 *
	 * Resolved from the caller's own session, never from what the consumer asked
	 * for: a consumer that could name its own audience could ask for the
	 * handler's screen on behalf of a citizen. A visitor with no account can be
	 * in `portal` and in nothing else, whatever is asked (REQ-OBSO-004).
	 *
	 * @param array<string, string> $audience The audience.
	 *
	 * @return bool True when the caller is in it.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004)
	 */
	private function callerIsIn(array $audience): bool {
		$kind = (string)($audience['kind'] ?? 'everyone');
		$ref = (string)($audience['ref'] ?? '');

		if ($kind === 'everyone') {
			return true;
		}

		if ($kind === 'portal') {
			return true;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $ref === '') {
			// group, team and user need somebody to be, and a visitor with no
			// account is nobody.
			return false;
		}

		if ($kind === 'user') {
			return ($user->getUID() === $ref);
		}

		// `team` and `group` both resolve against Nextcloud group membership
		// until the fleet ships a team service of its own. Naming them
		// separately keeps the fall-through order meaningful today and means the
		// stored data does not have to change when it does.
		return $this->groupManager->isInGroup($user->getUID(), $ref);
	}//end callerIsIn()

	/**
	 * Whether the caller is a visitor with no account.
	 *
	 * @return bool True when there is no session.
	 */
	private function callerHasNoAccount(): bool {
		return ($this->userSession->getUser() === null);
	}//end callerHasNoAccount()

	/**
	 * Describe one applied layer for the answer.
	 *
	 * @param array<string, mixed> $layer The layer.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-006)
	 */
	private function describeLayer(array $layer): array {
		return [
			'id' => (string)($layer['id'] ?? ''),
			'name' => (string)($layer['name'] ?? ''),
			'audience' => $this->audienceOf($layer),
			'typeValue' => (string)($layer['typeValue'] ?? ''),
		];
	}//end describeLayer()

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

			if ((string)($this->audienceOf($layout)['kind'] ?? 'everyone') !== 'everyone') {
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
			$composed = ($composed === [] ? $layer : $this->deltas->merge($composed, $this->patchableOf($layer)));
		}

		return $composed;
	}//end baseForOverride()

	/**
	 * The parts of a whole layout that can be patched, so a later layer merges
	 * over them rather than over the whole record.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return array<string, mixed> The patchable parts plus the identity.
	 */
	private function patchableOf(array $layout): array {
		$out = [];
		foreach (['id', 'name', 'typeProperty', 'typeValue', 'header', 'tabs', 'widgets', 'taskList', 'uploadFields'] as $key) {
			if (array_key_exists($key, $layout) === true) {
				$out[$key] = $layout[$key];
			}
		}

		return $out;
	}//end patchableOf()

	/**
	 * The schema-wide layer among the ordered layers, for the parts a narrower
	 * layer does not declare at all.
	 *
	 * @param array<int, array<string, mixed>> $layers The ordered layers.
	 *
	 * @return array<string, mixed>|null The schema-wide layer.
	 */
	private function schemaWideOf(array $layers): ?array {
		foreach ($layers as $layer) {
			if ((string)($layer['typeValue'] ?? '') === '' && (is_array($layer['layoutDelta'] ?? null) === false)) {
				return $layer;
			}
		}

		return null;
	}//end schemaWideOf()

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
