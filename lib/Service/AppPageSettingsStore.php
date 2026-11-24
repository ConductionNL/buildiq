<?php

/**
 * Buildiq App Page Settings Store
 *
 * Keeps the values a user saved on a settings page of a virtual app, so the
 * next visit shows them again (REQ-BQCV-011). One app config entry per app,
 * keyed by the Application's uuid, holds the values of each of its settings
 * pages keyed by page id.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;
use OCA\Buildiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads and writes the saved values of a virtual app's settings pages.
 *
 * The values are app-wide (every user of the app sees the same reply
 * period), stored in Nextcloud's app configuration and never in the
 * manifest, so saving an option does not make a new design save.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
final class AppPageSettingsStore {
	/**
	 * The app config key prefix; the Application uuid follows.
	 */
	public const KEY_PREFIX = 'page_settings_';

	/**
	 * The most bytes the values of one page may take as JSON.
	 */
	public const MAX_BYTES = 65536;

	/**
	 * A page id as the manifest names it.
	 */
	private const PAGE_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

	/**
	 * An option key as a settings field names it.
	 */
	private const FIELD_KEY = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud's app configuration.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether a page id is one this store keeps values for.
	 *
	 * @param string $pageId The page id.
	 *
	 * @return bool True for a manifest-shaped page id.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
	 */
	public function isPageId(string $pageId): bool {
		return preg_match(self::PAGE_ID, $pageId) === 1;
	}//end isPageId()

	/**
	 * The saved values of one settings page, `[]` when nothing was saved.
	 *
	 * @param string $appUuid The Application uuid.
	 * @param string $pageId  The settings page id.
	 *
	 * @return array<string,mixed> The values keyed by option key.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
	 */
	public function values(string $appUuid, string $pageId): array {
		$pages = $this->pages(appUuid: $appUuid);
		$saved = ($pages[$pageId] ?? []);
		if (is_array($saved) === false) {
			return [];
		}

		return $saved;
	}//end values()

	/**
	 * Save the values of one settings page, replacing what it held.
	 *
	 * @param string              $appUuid The Application uuid.
	 * @param string              $pageId  The settings page id.
	 * @param array<string,mixed> $values  The values keyed by option key.
	 *
	 * @return array<string,mixed> The values as saved.
	 *
	 * @throws InvalidArgumentException When the page id, a key or the size is refused.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
	 */
	public function save(string $appUuid, string $pageId, array $values): array {
		if ($this->isPageId(pageId: $pageId) === false) {
			throw new InvalidArgumentException('invalid_page_id');
		}

		foreach ($values as $key => $value) {
			if (is_string($key) === false || preg_match(self::FIELD_KEY, $key) !== 1) {
				throw new InvalidArgumentException('invalid_option_key');
			}

			if (is_object($value) === true) {
				throw new InvalidArgumentException('invalid_option_value');
			}
		}

		$encoded = json_encode($values);
		if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
			throw new InvalidArgumentException('values_too_large');
		}

		$pages          = $this->pages(appUuid: $appUuid);
		$pages[$pageId] = $values;
		$this->appConfig->setValueString(AppIdentity::APP_ID, $this->key(appUuid: $appUuid), (string)json_encode($pages), lazy: true);

		return $values;
	}//end save()

	/**
	 * Every saved page of an app, keyed by page id.
	 *
	 * @param string $appUuid The Application uuid.
	 *
	 * @return array<string,mixed> The pages; `[]` for nothing or unreadable JSON.
	 */
	private function pages(string $appUuid): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, $this->key(appUuid: $appUuid), '', lazy: true);
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end pages()

	/**
	 * The app config key of an app.
	 *
	 * @param string $appUuid The Application uuid.
	 *
	 * @return string The key.
	 */
	private function key(string $appUuid): string {
		return self::KEY_PREFIX . $appUuid;
	}//end key()
}//end class
