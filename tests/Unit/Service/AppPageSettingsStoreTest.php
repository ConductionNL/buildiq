<?php

/**
 * Tests for AppPageSettingsStore: the values saved on a settings page of a
 * virtual app are kept per app and page (REQ-BQCV-011).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\AppPageSettingsStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the settings page store.
 *
 * @covers \OCA\Buildiq\Service\AppPageSettingsStore
 */
final class AppPageSettingsStoreTest extends TestCase {
	/**
	 * The app config entries the double holds.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * A saved reply period is read back on the next visit.
	 *
	 * @return void
	 */
	public function testASavedReplyPeriodIsReadBack(): void {
		$store = $this->store();
		$store->save(appUuid: 'app-uuid', pageId: 'settings', values: ['replyPeriod' => 21]);

		$this->assertSame(['replyPeriod' => 21], $store->values(appUuid: 'app-uuid', pageId: 'settings'));
		$this->assertArrayHasKey(AppPageSettingsStore::KEY_PREFIX . 'app-uuid', $this->config);
	}//end testASavedReplyPeriodIsReadBack()

	/**
	 * A page nothing was saved on has no values.
	 *
	 * @return void
	 */
	public function testAPageWithoutSavesHasNoValues(): void {
		$this->assertSame([], $this->store()->values(appUuid: 'app-uuid', pageId: 'settings'));
	}//end testAPageWithoutSavesHasNoValues()

	/**
	 * Saving one page keeps the other pages and apps as they were.
	 *
	 * @return void
	 */
	public function testSavingOnePageKeepsTheOthers(): void {
		$store = $this->store();
		$store->save(appUuid: 'app-uuid', pageId: 'settings', values: ['replyPeriod' => 21]);
		$store->save(appUuid: 'app-uuid', pageId: 'mail', values: ['sender' => 'desk@example.org']);
		$store->save(appUuid: 'other-uuid', pageId: 'settings', values: ['replyPeriod' => 5]);
		$store->save(appUuid: 'app-uuid', pageId: 'settings', values: ['replyPeriod' => 30]);

		$this->assertSame(['replyPeriod' => 30], $store->values(appUuid: 'app-uuid', pageId: 'settings'));
		$this->assertSame(['sender' => 'desk@example.org'], $store->values(appUuid: 'app-uuid', pageId: 'mail'));
		$this->assertSame(['replyPeriod' => 5], $store->values(appUuid: 'other-uuid', pageId: 'settings'));
	}//end testSavingOnePageKeepsTheOthers()

	/**
	 * Unreadable stored JSON reads as nothing saved.
	 *
	 * @return void
	 */
	public function testUnreadableStoredValuesReadAsNone(): void {
		$this->config[AppPageSettingsStore::KEY_PREFIX . 'app-uuid'] = '{not json';

		$this->assertSame([], $this->store()->values(appUuid: 'app-uuid', pageId: 'settings'));
	}//end testUnreadableStoredValuesReadAsNone()

	/**
	 * A bad page id, option key or oversized save is refused and nothing is written.
	 *
	 * @param string              $pageId The page id.
	 * @param array<string,mixed> $values The values.
	 * @param string              $code   The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedSaves
	 */
	public function testARefusedSaveWritesNothing(string $pageId, array $values, string $code): void {
		try {
			$this->store()->save(appUuid: 'app-uuid', pageId: $pageId, values: $values);
			$this->fail('The save was not refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($code, $e->getMessage());
		}

		$this->assertSame([], $this->config);
	}//end testARefusedSaveWritesNothing()

	/**
	 * Saves the store refuses.
	 *
	 * @return array<string,array{0: string, 1: array<string,mixed>, 2: string}>
	 */
	public static function refusedSaves(): array {
		return [
			'page id with a slash' => ['../settings', ['replyPeriod' => 1], 'invalid_page_id'],
			'empty page id'        => ['', ['replyPeriod' => 1], 'invalid_page_id'],
			'option key with space' => ['settings', ['reply period' => 1], 'invalid_option_key'],
			'numeric option key'   => ['settings', [0 => 1], 'invalid_option_key'],
			'too large'            => ['settings', ['text' => str_repeat('x', AppPageSettingsStore::MAX_BYTES)], 'values_too_large'],
		];
	}//end refusedSaves()

	/**
	 * The store over an in-memory app config.
	 *
	 * @return AppPageSettingsStore The store.
	 */
	private function store(): AppPageSettingsStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new AppPageSettingsStore(appConfig: $appConfig);
	}//end store()
}//end class
