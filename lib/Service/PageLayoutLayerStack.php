<?php

/**
 * Page Layout Layer Stack
 *
 * Which published layouts apply to THIS caller looking at THIS object, and in
 * which order each patches the one beneath it: the base a layer names with
 * `baseRef`, the schema-wide layout, the type layout, the audience override and
 * the user override, narrowest last (REQ-OBSO-005).
 *
 * The caller's audience is resolved from the caller's own session and never
 * from what the consuming app asked for. A consumer that could name its own
 * audience could ask for the handler's screen on behalf of a citizen, so the
 * question this class answers is always "who is asking", never "who do you say
 * you are" (REQ-OBSO-004).
 *
 * It sits beside the leaf provider because the provider's job is to answer a
 * request: read the object, ask for the stack, merge it, serve it. Picking the
 * stack is the part with the rules in it.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004, REQ-OBSO-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Orders the layout layers that apply to a caller and an object.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-005)
 */
final class PageLayoutLayerStack {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The calling user, whose audiences decide which overrides apply.
	 * @param IGroupManager $groupManager The caller's group and team membership.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

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
	public function orderLayers(array $published, array $object): array {
		$reach = $this->byReach(published: $published);

		$everyone = $this->everyoneLayers(layouts: $reach['everyone'], object: $object);
		$audienceOverrides = $this->rankedByAudience(
			overrides: $this->keepThoseForThisObject(overrides: $reach['audience'], object: $object)
		);
		$userOverrides = $this->keepThoseForThisObject(overrides: $reach['user'], object: $object);

		$narrowest = ($userOverrides[0] ?? ($audienceOverrides[0] ?? ($everyone['typed'] ?? $everyone['schemaWide'])));

		$layers = [];
		$baseRef = (string)($narrowest['baseRef'] ?? '');
		if ($baseRef !== '' && array_key_exists($baseRef, $reach['byId']) === true) {
			$layers[] = $reach['byId'][$baseRef];
		}

		foreach ([$everyone['schemaWide'], $everyone['typed']] as $layout) {
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
	 * Split the published layouts by how far they reach: everyone, an audience
	 * the caller is in, or the caller personally. A layout bound to an audience
	 * the caller is NOT in is dropped here and never reaches the stack.
	 *
	 * @param array<int, array<string, mixed>> $published The published layouts.
	 *
	 * @return array{byId: array<string, mixed>, everyone: array<int, mixed>, audience: array<int, mixed>, user: array<int, mixed>} The split.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004)
	 */
	private function byReach(array $published): array {
		$byId = [];
		$everyone = [];
		$audienceOverrides = [];
		$userOverrides = [];

		foreach ($published as $layout) {
			$id = (string)($layout['id'] ?? '');
			if ($id !== '') {
				$byId[$id] = $layout;
			}

			$audience = $this->audienceOf(layout: $layout);
			$kind = (string)($audience['kind'] ?? 'everyone');

			if ($kind === 'everyone') {
				$everyone[] = $layout;
				continue;
			}

			if ($this->callerIsIn(audience: $audience) === false) {
				continue;
			}

			if ($kind === 'user') {
				$userOverrides[] = $layout;
				continue;
			}

			$audienceOverrides[] = $layout;
		}

		return ['byId' => $byId, 'everyone' => $everyone, 'audience' => $audienceOverrides, 'user' => $userOverrides];
	}//end byReach()

	/**
	 * The schema-wide and type layouts among the layouts that reach everyone.
	 * The first of each wins; a second one for the same type is a collision the
	 * validator refuses at save time.
	 *
	 * @param array<int, mixed> $layouts The layouts that reach everyone.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array{schemaWide: array<string, mixed>|null, typed: array<string, mixed>|null} The two.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	private function everyoneLayers(array $layouts, array $object): array {
		$schemaWide = null;
		$typed = null;

		foreach ($layouts as $layout) {
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

		return ['schemaWide' => $schemaWide, 'typed' => $typed];
	}//end everyoneLayers()

	/**
	 * The audience overrides, narrowest audience first.
	 *
	 * A visitor with no account gets `portal` first; a signed-in caller gets the
	 * narrower `team` before the wider `group`.
	 *
	 * @param array<int, mixed> $overrides The overrides that apply.
	 *
	 * @return array<int, mixed> The overrides, in the order they stack.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-005)
	 */
	private function rankedByAudience(array $overrides): array {
		$rank = ['team' => 0, 'group' => 1, 'portal' => 2];
		if ($this->callerHasNoAccount() === true) {
			$rank = ['portal' => 0, 'team' => 1, 'group' => 2];
		}

		usort(
			$overrides,
			static function (array $a, array $b) use ($rank): int {
				$rankA = ($rank[(string)($a['audience']['kind'] ?? '')] ?? 9);
				$rankB = ($rank[(string)($b['audience']['kind'] ?? '')] ?? 9);

				return ($rankA <=> $rankB);
			}
		);

		return $overrides;
	}//end rankedByAudience()

	/**
	 * Keep the overrides that are for this object's type, or for the schema as a
	 * whole.
	 *
	 * @param array<int, array<string, mixed>> $overrides The overrides.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The ones that apply.
	 */
	public function keepThoseForThisObject(array $overrides, array $object): array {
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
	public function audienceOf(array $layout): array {
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
	public function callerIsIn(array $audience): bool {
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
			// Group, team and user need somebody to be, and a visitor with no
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
	public function describeLayer(array $layer): array {
		return [
			'id' => (string)($layer['id'] ?? ''),
			'name' => (string)($layer['name'] ?? ''),
			'audience' => $this->audienceOf(layout: $layer),
			'typeValue' => (string)($layer['typeValue'] ?? ''),
		];
	}//end describeLayer()

	/**
	 * The parts of a whole layout that can be patched, so a later layer merges
	 * over them rather than over the whole record.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return array<string, mixed> The patchable parts plus the identity.
	 */
	public function patchableOf(array $layout): array {
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
	public function schemaWideOf(array $layers): ?array {
		foreach ($layers as $layer) {
			if ((string)($layer['typeValue'] ?? '') === '' && (is_array($layer['layoutDelta'] ?? null) === false)) {
				return $layer;
			}
		}

		return null;
	}//end schemaWideOf()
}//end class
