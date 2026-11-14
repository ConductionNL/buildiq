<?php

/**
 * Device features of a manifest.
 *
 * The browser features a built app's pages ask for, read from its manifest:
 * `camera` when an index page finds records by scanning or a form has a scan
 * field (pages-qr-and-barcodes REQ-BQQR-004). The list is stored on the
 * ApplicationVersion as `deviceFeatures[]` and read by the responses that
 * serve the built app, which allow those features for the page itself only.
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
 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Derives the device features a manifest uses.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
 */
final class DeviceFeatures {

	/**
	 * The camera, for scanning a code.
	 *
	 * @var string
	 */
	public const CAMERA = 'camera';

	/**
	 * The device features a manifest uses, sorted, each once.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
	 */
	public static function derive(array $manifest): array {
		$features = [];
		foreach ((array)($manifest['pages'] ?? []) as $page) {
			if (is_array($page) === false) {
				continue;
			}

			if (self::scans(page: $page) === true) {
				$features[] = self::CAMERA;
			}
		}

		$features = array_values(array_unique($features));
		sort($features);

		return $features;
	}//end derive()

	/**
	 * Whether a page scans: an index page with `config.scan`, or a scan field anywhere in its config.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return bool
	 */
	private static function scans(array $page): bool {
		$config = $page['config'] ?? [];
		if (is_array($config) === false) {
			return false;
		}

		if (($page['type'] ?? '') === 'index' && is_array($config['scan'] ?? null) === true && $config['scan'] !== []) {
			return true;
		}

		return self::hasScanField(node: $config);
	}//end scans()

	/**
	 * Whether a config node holds a field of type `scan`, at any depth.
	 *
	 * @param array<mixed> $node The config node.
	 *
	 * @return bool
	 */
	private static function hasScanField(array $node): bool {
		foreach ($node as $key => $value) {
			if ($key === 'type' && $value === 'scan' && (array_key_exists('key', $node) === true || array_key_exists('name', $node) === true)) {
				return true;
			}

			if (is_array($value) === true && self::hasScanField(node: $value) === true) {
				return true;
			}
		}

		return false;
	}//end hasScanField()

	/**
	 * Whether a list of device features names one.
	 *
	 * @param mixed  $features The stored `deviceFeatures`.
	 * @param string $feature  The feature.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
	 */
	public static function includes(mixed $features, string $feature): bool {
		return is_array($features) === true && in_array($feature, $features, true) === true;
	}//end includes()
}//end class
