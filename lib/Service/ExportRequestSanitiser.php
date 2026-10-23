<?php

/**
 * Buildiq Export Request Sanitiser
 *
 * Normalises the untrusted parts of an export submit request before they are
 * written onto an ExportJob record.
 *
 * Split out of ExportJobService when the export content (#800) and the
 * start-right-away change (#804) together took that class past the class
 * complexity limit. These are pure functions over the request payload, with
 * no state and no dependencies, so they carry their weight better on their own.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Pure sanitisers for the export submit payload.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
 */
class ExportRequestSanitiser {
	/**
	 * Normalise the submit request's `dataRegisters` choice onto the shape
	 * `{register: string, includeData: bool}`, mirroring the
	 * `includeSeedData` boolean cast. Malformed entries (not an array, or
	 * missing/empty `register`) are dropped rather than rejected; no existence
	 * validation of the referenced register is performed here (matches the
	 * head spec's own Non-Goal for a dangling
	 * `Application.dataRegisters[].register` slug).
	 *
	 * @param mixed $raw The request payload's `dataRegisters` value.
	 *
	 * @return array<int,array{register:string,includeData:bool}>
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.3
	 */
	public function dataRegisters(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$register = (string)($entry['register'] ?? '');
			if ($register === '') {
				continue;
			}

			$out[] = [
				'register' => $register,
				'includeData' => (bool)($entry['includeData'] ?? false),
			];
		}

		return $out;
	}//end dataRegisters()

	/**
	 * Keep a slug-shaped string, drop anything else.
	 *
	 * @param mixed $raw The request value.
	 *
	 * @return string The slug, '' when the value is not one.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
	 */
	public function slug(mixed $raw): string {
		if (is_string($raw) === false || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $raw) !== 1) {
			return '';
		}

		return $raw;
	}//end slug()

	/**
	 * Normalise the submit request's `flows` choice.
	 *
	 * Same defensive shape as `dataRegisters()`, because this is the same
	 * untrusted request payload arriving by the same route.
	 *
	 * Only the UUID is kept. `label` is a builder-UI convenience and has no
	 * meaning to the exporter, which resolves the flow and writes the flow's
	 * own name into the bundle.
	 *
	 * There is no agents counterpart on purpose: agents carry
	 * `applicationSlug` and are found by asking which agents point at the
	 * application, so there is no agent choice in the payload to sanitise.
	 *
	 * @param mixed $raw The request payload's `flows` value.
	 *
	 * @return array<int, array{flow: string}> Normalised bindings.
	 *
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/tasks.md#1-bundle-flows-and-agents-into-the-export
	 */
	public function flows(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$flow = trim((string)($entry['flow'] ?? ''));
			if ($flow === '') {
				continue;
			}

			$out[] = ['flow' => $flow];
		}

		return $out;
	}//end flows()
}//end class
