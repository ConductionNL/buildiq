<?php

/**
 * Page Layout Authoring Service
 *
 * The write half of the page-layout and screen-override feature. The leaf
 * provider refuses every write with "a page layout is authored in buildiq,
 * where the rules that validate it live"; this is where they live.
 *
 * IT STAMPS THE FINGERPRINT, AND NEVER TAKES ONE
 * ----------------------------------------------
 * An override says which base it was cut against, and resolution withholds it
 * when that base has since moved. A fingerprint sent by a caller would be a
 * claim about a base nobody checked, so the payload's `baseFingerprint` is
 * discarded and the one stamped here is computed from the layouts actually
 * published, by the class that later checks it.
 *
 * AN OVERRIDE WITH NOTHING BENEATH IT IS REFUSED
 * ----------------------------------------------
 * A patch cut against an empty base is internally consistent: it pins to the
 * fingerprint of nothing, passes the drift check, and is applied to nothing.
 * What reaches the screen is then the patch alone, which is a page made of the
 * two tabs somebody meant to add to a design, not the design. That is the one
 * way a stale override could still blank a screen, so it is refused at the
 * door rather than reported afterwards.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;
use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Saves and re-cuts page layouts and the overrides that patch them.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
 */
class PageLayoutAuthoringService {
	/**
	 * The schema holding layouts, equal to the leaf provider's.
	 *
	 * @var string
	 */
	private const SCHEMA = 'pageLayout';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param PageLayoutValidator $validator The rules a layout has to pass.
	 * @param LayoutDeltaService $deltas The fingerprint and the orphan report.
	 * @param PageLayoutLeafProvider $provider The resolver, which owns what the base is.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly PageLayoutValidator $validator,
		private readonly LayoutDeltaService $deltas,
		private readonly PageLayoutLeafProvider $provider,
	) {
	}//end __construct()

	/**
	 * Save a layout or an override.
	 *
	 * @param array<string, mixed> $layout The layout to store.
	 * @param string $author The uid saving it, recorded as the maintainer of a new override.
	 *
	 * @return array<string, mixed> `{layout, warnings}`.
	 *
	 * @throws InvalidArgumentException When a rule refuses it.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001)
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function save(array $layout, string $author): array {
		$register = (string)($layout['register'] ?? '');
		$schema = (string)($layout['schema'] ?? '');
		if ($register === '' || $schema === '') {
			throw new InvalidArgumentException('A layout has to say which register and schema it is for.');
		}

		$delta = ($layout['layoutDelta'] ?? null);
		$isOverride = (is_array($delta) === true && $delta !== []);

		if ($isOverride === true) {
			$layout = $this->pin($layout);
			if ((string)($layout['maintainer'] ?? '') === '') {
				// Who to tell when this override drifts. Without it the
				// notification has no addressee and the override is one nobody
				// will ever re-cut.
				$layout['maintainer'] = $author;
			}
		} else {
			// A whole layout is not pinned to anything, and a fingerprint left
			// on it from an earlier save would make it read as a patch.
			unset($layout['baseFingerprint'], $layout['baseCutAt']);
		}

		$warnings = $this->validator->validate(
			$layout,
			$this->storedFor($register, $schema, (string)($layout['id'] ?? '')),
			null
		);

		return ['layout' => $this->store($layout), 'warnings' => $warnings];
	}//end save()

	/**
	 * Re-cut a drifted override against the base it has now.
	 *
	 * The parts of the patch that still name something the base has are kept
	 * exactly as the maintainer wrote them. The orphans are dropped, because a
	 * patch that removes a tab the base no longer has is a patch that will never
	 * do anything again, and carrying it forward would make the next drift
	 * report list it once more.
	 *
	 * What was dropped is returned rather than logged: a re-cut that quietly
	 * loses half an override is the same surprise as an override that quietly
	 * stops applying.
	 *
	 * @param string $layoutId The override's id.
	 *
	 * @return array<string, mixed> `{layout, dropped}` where dropped lists the orphaned paths.
	 *
	 * @throws RuntimeException When no such override is stored.
	 * @throws InvalidArgumentException When the re-cut result would not be a valid override.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function recut(string $layoutId): array {
		$override = $this->byId($layoutId);
		if ($override === null) {
			throw new RuntimeException('No layout with that id.');
		}

		$delta = ($override['layoutDelta'] ?? null);
		if (is_array($delta) === false || $delta === []) {
			throw new InvalidArgumentException('That layout is not an override, so there is nothing to re-cut.');
		}

		$base = $this->baseOrRefuse($override);
		$dropped = $this->deltas->orphanedPaths($base, $delta);

		$override['layoutDelta'] = $this->withoutPaths($delta, $dropped);
		$override['baseFingerprint'] = $this->deltas->fingerprint($base);
		$override['baseCutAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$override['status'] = 'published';

		$this->validator->validate(
			$override,
			$this->storedFor(
				(string)($override['register'] ?? ''),
				(string)($override['schema'] ?? ''),
				$layoutId
			),
			null
		);

		return ['layout' => $this->store($override), 'dropped' => $dropped];
	}//end recut()

	/**
	 * Every stored layout for a register and schema, with drift resolved, so the
	 * editor can show which overrides are waiting to be re-cut.
	 *
	 * @param string $register The consuming app's register.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The layouts, each with `drifted` and `orphanedPaths`.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function listFor(string $register, string $schema): array {
		$out = [];
		foreach ($this->storedFor($register, $schema, '') as $layout) {
			$delta = ($layout['layoutDelta'] ?? null);
			if (is_array($delta) === false || $delta === []) {
				$layout['drifted'] = false;
				$layout['orphanedPaths'] = [];
				$out[] = $layout;
				continue;
			}

			$base = $this->provider->baseForOverride($layout);
			$layout['drifted'] = ($this->deltas->isCurrent($base, (string)($layout['baseFingerprint'] ?? '')) === false);
			$layout['orphanedPaths'] = $this->deltas->orphanedPaths($base, $delta);
			$out[] = $layout;
		}

		return $out;
	}//end listFor()

	/**
	 * Stamp an override with the base it is being cut against.
	 *
	 * @param array<string, mixed> $override The override.
	 *
	 * @return array<string, mixed> The override, pinned.
	 *
	 * @throws InvalidArgumentException When there is no base to pin to.
	 */
	private function pin(array $override): array {
		$base = $this->baseOrRefuse($override);

		$override['baseFingerprint'] = $this->deltas->fingerprint($base);
		$override['baseCutAt'] = gmdate('Y-m-d\TH:i:s\Z');

		return $override;
	}//end pin()

	/**
	 * The base an override patches, or a refusal.
	 *
	 * @param array<string, mixed> $override The override.
	 *
	 * @return array<string, mixed> The composed base.
	 *
	 * @throws InvalidArgumentException When nothing published lies beneath it.
	 */
	private function baseOrRefuse(array $override): array {
		$base = $this->provider->baseForOverride($override);
		if ($base === []) {
			throw new InvalidArgumentException(
				'There is no published layout for this schema to patch, so this override would be served on its own '
				. 'and the page would show only what the patch names.'
			);
		}

		return $base;
	}//end baseOrRefuse()

	/**
	 * Remove the orphaned entries a re-cut drops.
	 *
	 * @param array<string, mixed> $delta The patch.
	 * @param array<int, string> $paths The orphaned paths, as `tabs.documenten`.
	 *
	 * @return array<string, mixed> The patch without them.
	 */
	private function withoutPaths(array $delta, array $paths): array {
		foreach ($paths as $path) {
			$parts = explode('.', $path, 2);
			if (count($parts) !== 2) {
				continue;
			}

			[$key, $id] = $parts;
			if (is_array($delta[$key] ?? null) === false) {
				continue;
			}

			unset($delta[$key][$id]);

			// An order that still names a dropped entry is harmless to the
			// merge, which skips ids it cannot find, but it keeps a name on
			// screen in the editor that nothing answers to.
			if (is_array($delta[$key][LayoutDeltaService::ORDER_KEY] ?? null) === true) {
				$delta[$key][LayoutDeltaService::ORDER_KEY] = array_values(
					array_filter(
						$delta[$key][LayoutDeltaService::ORDER_KEY],
						static fn (mixed $entry): bool => ($entry !== $id)
					)
				);
			}

			if ($delta[$key] === [] || array_keys($delta[$key]) === [LayoutDeltaService::ORDER_KEY]) {
				unset($delta[$key]);
			}
		}

		return $delta;
	}//end withoutPaths()

	/**
	 * The layouts already stored for a register and schema, minus the one being
	 * saved, which is what the uniqueness rule is checked against.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string $exceptId The id being saved, excluded so an edit is not its own collision.
	 *
	 * @return array<int, array<string, mixed>> The stored layouts.
	 */
	private function storedFor(string $register, string $schema, string $exceptId): array {
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
				|| (string)($row['register'] ?? '') !== $register
				|| (string)($row['schema'] ?? '') !== $schema
			) {
				continue;
			}

			if ($exceptId !== '' && (string)($row['id'] ?? '') === $exceptId) {
				continue;
			}

			$mine[] = $row;
		}

		return $mine;
	}//end storedFor()

	/**
	 * One stored layout by id.
	 *
	 * @param string $layoutId The id.
	 *
	 * @return array<string, mixed>|null The layout, or null.
	 */
	private function byId(string $layoutId): ?array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['limit' => 500]);

		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true && (string)($row['id'] ?? '') === $layoutId) {
				return $row;
			}
		}

		return null;
	}//end byId()

	/**
	 * Write a layout back.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return array<string, mixed> The layout as stored.
	 */
	private function store(array $layout): array {
		$this->objectService->saveObject(
			object: $layout,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
		);

		return $layout;
	}//end store()

	/**
	 * The register slug holding buildiq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('buildiq', 'register', 'buildiq');
	}//end registerSlug()
}//end class
