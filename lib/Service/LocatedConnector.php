<?php

/**
 * Buildiq LocatedConnector
 *
 * A connector object found for a reference, with the identity it dedupes on.
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
 * A connector object resolved from the connector register.
 *
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
 */
final class LocatedConnector {

	/**
	 * Constructor.
	 *
	 * @param ConnectorReference  $reference What the object was looked up by.
	 * @param array<string,mixed> $object    The object payload.
	 * @param string              $identity  The object's own UUID, or the reference when it has none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ConnectorReference $reference,
		public readonly array $object,
		public readonly string $identity,
	) {
	}//end __construct()

	/**
	 * The dedupe key: kind and the object's own identity, never the raw reference.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
	 */
	public function key(): string {
		return $this->reference->kind . '/' . $this->identity;
	}//end key()
}//end class
