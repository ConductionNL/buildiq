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
		$found = [];
		$this->collect(value: $manifest, path: '', parentKey: '', found: $found);
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
		$removed = [];
		$cleaned = $this->strip(value: $config, path: 'config', parentKey: 'config', removed: $removed);
		return [$cleaned, $removed];
	}//end stripConfig()

	/**
	 * Collect the code-bearing keys below a value.
	 *
	 * @param mixed                 $value     The value.
	 * @param string                $path      Its path.
	 * @param string                $parentKey The key it sits under.
	 * @param array<string, string> $found     Collected so far.
	 *
	 * @return void
	 */
	private function collect(mixed $value, string $path, string $parentKey, array &$found): void {
		if (is_array($value) === false) {
			return;
		}

		foreach ($value as $key => $child) {
			$childPath = $path . '/' . $key;
			if ($this->isCodeKey(key: $key, parentKey: $parentKey) === true) {
				$found[$childPath] = (string)json_encode($child, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				continue;
			}

			$this->collect(value: $child, path: $childPath, parentKey: (string)$key, found: $found);
		}
	}//end collect()

	/**
	 * A value without the code-bearing keys below it.
	 *
	 * @param mixed        $value     The value.
	 * @param string       $path      Its path.
	 * @param string       $parentKey The key it sits under.
	 * @param list<string> $removed   The paths removed so far.
	 *
	 * @return mixed
	 */
	private function strip(mixed $value, string $path, string $parentKey, array &$removed): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $child) {
			$childPath = $path . '/' . $key;
			if ($this->isCodeKey(key: $key, parentKey: $parentKey) === true) {
				unset($value[$key]);
				$removed[] = $childPath;
				continue;
			}

			$value[$key] = $this->strip(value: $child, path: $childPath, parentKey: (string)$key, removed: $removed);
		}

		return $value;
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
