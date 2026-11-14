<?php

/**
 * Buildiq PageCodeGuard
 *
 * Finds the keys of a manifest that hold maker code, so a save can be checked
 * and a copilot plan cleaned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The code-bearing keys of design D1 and D6: a code component's `config.code`,
 * and every `expression` and `expressionDefault` (columns, form field
 * defaults, `visibleWhen.expression`).
 *
 * Pure: it reads arrays and returns arrays.
 *
 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
 */
final class PageCodeGuard {

	/**
	 * Keys that hold an expression wherever they appear.
	 *
	 * @var array<int, string>
	 */
	public const EXPRESSION_KEYS = ['expression', 'expressionDefault'];

	/**
	 * The key that holds a code component, under a `config` object.
	 */
	public const CODE_KEY = 'code';

	/**
	 * The instance setting "Allow code on pages" (app config, off by default).
	 */
	public const SETTING = 'allow_page_code';

	/**
	 * Every code-bearing key of a manifest, by path, with its value as canonical JSON.
	 *
	 * @param array<mixed> $manifest The manifest.
	 *
	 * @return array<string, string> Path to value.
	 *
	 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
	 */
	public function codeKeys(array $manifest): array {
		$found = $this->collect(value: $manifest, at: ['path' => '', 'parent' => '']);
		ksort($found);
		return $found;
	}//end codeKeys()

	/**
	 * Whether a save adds, changes or removes code.
	 *
	 * @param array<mixed>|null $before The stored manifest, or null when there is none.
	 * @param array<mixed>      $after  The manifest being saved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
	 */
	public function changesCode(?array $before, array $after): bool {
		return $this->codeKeys(manifest: ($before ?? [])) !== $this->codeKeys(manifest: $after);
	}//end changesCode()

	/**
	 * A page or widget `config` without its code-bearing keys, and the paths removed.
	 *
	 * @param array<mixed> $config The config.
	 *
	 * @return array{0: array<mixed>, 1: list<string>}
	 *
	 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
	 */
	public function stripConfig(array $config): array {
		return $this->strip(value: $config, at: ['path' => 'config', 'parent' => 'config']);
	}//end stripConfig()

	/**
	 * The code-bearing keys below a value, by path.
	 *
	 * @param mixed                               $value The value.
	 * @param array{path: string, parent: string} $at    Its path and the key it sits under.
	 *
	 * @return array<string, string> Path to value.
	 */
	private function collect(mixed $value, array $at): array {
		if (is_array($value) === false) {
			return [];
		}

		$found = [];
		foreach ($value as $key => $child) {
			$childPath = $at['path'] . '/' . $key;
			if ($this->isCodeKey(key: $key, parentKey: $at['parent']) === true) {
				$found[$childPath] = (string)json_encode($child, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				continue;
			}

			$found += $this->collect(value: $child, at: ['path' => $childPath, 'parent' => (string)$key]);
		}

		return $found;
	}//end collect()

	/**
	 * A value without the code-bearing keys below it, and the paths removed.
	 *
	 * @param mixed                               $value The value.
	 * @param array{path: string, parent: string} $at    Its path and the key it sits under.
	 *
	 * @return array{0: mixed, 1: list<string>}
	 */
	private function strip(mixed $value, array $at): array {
		if (is_array($value) === false) {
			return [$value, []];
		}

		$removed = [];
		foreach ($value as $key => $child) {
			$childPath = $at['path'] . '/' . $key;
			if ($this->isCodeKey(key: $key, parentKey: $at['parent']) === true) {
				unset($value[$key]);
				$removed[] = $childPath;
				continue;
			}

			[$value[$key], $below] = $this->strip(value: $child, at: ['path' => $childPath, 'parent' => (string)$key]);
			array_push($removed, ...$below);
		}

		return [$value, $removed];
	}//end strip()

	/**
	 * Whether a key holds code: an expression anywhere, or `code` directly under `config`.
	 *
	 * @param int|string $key       The key.
	 * @param string     $parentKey The key of the object it is in.
	 *
	 * @return bool
	 */
	private function isCodeKey(int|string $key, string $parentKey): bool {
		if (is_int($key) === true) {
			return false;
		}

		return in_array($key, self::EXPRESSION_KEYS, true) === true || ($key === self::CODE_KEY && $parentKey === 'config');
	}//end isCodeKey()
}//end class
