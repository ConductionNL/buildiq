<?php

/**
 * ManifestEntryList — upsert a page or menu item into a manifest list by id.
 *
 * A manifest's `pages[]` and `menu[]` are lists of entries addressed by `id`,
 * and every writer upserts into them the same way: the first entry whose id
 * matches ignoring case is replaced where it stands, otherwise the entry is
 * appended. Case-insensitive so a model can target an existing entry whatever
 * case it writes the id in (issue #166). The MCP handlers that store a
 * manifest and the copilot's preview of the same step both go through this
 * one rule, so the page reviewed and the page stored cannot disagree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * One manifest list, upserted by entry id.
 *
 * @spec openspec/specs/ai-copilot/spec.md
 */
final class ManifestEntryList {
	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $entries The list as stored; non-array entries are kept untouched.
	 *
	 * @return void
	 */
	public function __construct(
		private array $entries,
	) {
	}//end __construct()

	/**
	 * Replace the first entry whose id matches the new entry's ignoring case, or append it.
	 *
	 * @param array<string, mixed> $entry The entry to store; its `id` addresses it.
	 *
	 * @return bool True when an existing entry was replaced, false when it was appended.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function upsert(array $entry): bool {
		$idLc = strtolower((string)($entry['id'] ?? ''));
		foreach ($this->entries as $i => $existing) {
			if (is_array($existing) === true && strtolower((string)($existing['id'] ?? '')) === $idLc) {
				$this->entries[$i] = $entry;
				return true;
			}
		}

		$this->entries[] = $entry;
		return false;
	}//end upsert()

	/**
	 * The list after the upserts so far.
	 *
	 * @return array<int, mixed> The entries.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function entries(): array {
		return $this->entries;
	}//end entries()
}//end class
