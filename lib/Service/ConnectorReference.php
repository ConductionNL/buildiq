<?php

/**
 * Buildiq ConnectorReference
 *
 * A reference to one connector object, as authored: its kind, its UUID or slug, and the declared binding it came from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * A connector object an application repository refers to.
 *
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
 */
final class ConnectorReference {

	/**
	 * Constructor.
	 *
	 * @param string              $kind    The connector kind.
	 * @param string              $uuid    The reference as authored — a UUID *or* a slug.
	 * @param array<string,mixed> $binding The declared binding (empty for a resolved dependency).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $uuid,
		public readonly array $binding = [],
	) {
	}//end __construct()

	/**
	 * The reference an application's declared connector binding makes.
	 *
	 * @param array<string,mixed> $binding The declared binding.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
	 */
	public static function fromBinding(array $binding): self {
		return new self(kind: (string)($binding['kind'] ?? ''), uuid: (string)($binding['uuid'] ?? ''), binding: $binding);
	}//end fromBinding()
}//end class
