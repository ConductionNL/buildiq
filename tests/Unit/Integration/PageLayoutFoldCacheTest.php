<?php

/**
 * The fold of a page's layers is cached on the composing layers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/tasks.md#task-2.6
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\Buildiq\Service\PageLayoutFrozenBase;
use OCA\Buildiq\Service\PageLayoutLayerStack;
use OCA\Buildiq\Service\PageLayoutParts;
use OCA\Buildiq\Service\PageLayoutPresenter;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A second read of the same layers is answered from the cache; an edited layer is folded afresh.
 *
 * @covers \OCA\Buildiq\Integration\PageLayoutLeafProvider
 * @uses   \OCA\Buildiq\Service\LayoutDeltaService
 * @uses   \OCA\Buildiq\Service\PageLayoutFrozenBase
 * @uses   \OCA\Buildiq\Service\PageLayoutLayerStack
 * @uses   \OCA\Buildiq\Service\PageLayoutParts
 * @uses   \OCA\Buildiq\Service\PageLayoutPresenter
 */
final class PageLayoutFoldCacheTest extends TestCase {

	/**
	 * Every object the provider saved: a drift flag is a save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The layouts the register returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $layouts = [];

	/**
	 * An in-memory cache shared by the providers of one test.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * A provider over the current layouts, with or without a cache.
	 *
	 * @param bool $cached Whether the provider gets a cache.
	 *
	 * @return PageLayoutLeafProvider
	 */
	private function provider(bool $cached = true): PageLayoutLeafProvider {
		$objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(fn (): array => $this->layouts);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object);
				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$layers = new PageLayoutLayerStack(userSession: $session, groupManager: $this->createMock(IGroupManager::class), container: null);

		$factory = null;
		if ($cached === true) {
			$cache = $this->createMock(ICache::class);
			$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->store[$key] ?? null));
			$cache->method('set')->willReturnCallback(
				function (string $key, mixed $value): bool {
					$this->store[$key] = $value;
					return true;
				}
			);
			$factory = $this->createMock(ICacheFactory::class);
			$factory->method('createLocal')->with('buildiq-page-layout')->willReturn($cache);
		}

		return new PageLayoutLeafProvider(
			objectService: $objectService,
			appConfig: $appConfig,
			parts: new PageLayoutParts(
				deltas: new LayoutDeltaService(),
				layers: $layers,
				frozenBase: new PageLayoutFrozenBase(layers: $layers),
				presenter: new PageLayoutPresenter()
			),
			logger: $this->createMock(LoggerInterface::class),
			cacheFactory: $factory,
		);
	}//end provider()

	/**
	 * The schema-wide layout and a drifted override for everyone.
	 *
	 * @param string $label The first tab's label.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function layouts(string $label = 'Gegevens'): array {
		return [
			[
				'id' => 'pl-schema',
				'status' => 'published',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => '',
				'typeValue' => '',
				'tabs' => [['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => $label, 'fields' => ['a']]],
			],
			[
				'id' => 'pl-drifted',
				'name' => 'Oud scherm',
				'status' => 'published',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => '',
				'typeValue' => '',
				'audience' => ['kind' => 'user', 'ref' => 'handler'],
				'layoutDelta' => ['tabs' => ['gegevens' => ['label' => 'Anders']]],
				'baseFingerprint' => 'stale',
				'maintainer' => 'beheerder',
			],
		];
	}//end layouts()

	/**
	 * Read the page of one case.
	 *
	 * @param PageLayoutLeafProvider $provider The provider.
	 *
	 * @return array<string, mixed>
	 */
	private static function read(PageLayoutLeafProvider $provider): array {
		return $provider->list(register: 'dossiq', schema: 'Zaak', objectId: 'zaak-7', filters: ['object' => ['id' => 'zaak-7']]);
	}//end read()

	/**
	 * The second read of the same layers is the cached fold: the same answer, and the drift is not folded (or flagged) again.
	 *
	 * @return void
	 */
	public function testTheSameLayersAreFoldedOnce(): void {
		$this->layouts = self::layouts();

		$first  = self::read(provider: $this->provider());
		$second = self::read(provider: $this->provider());

		$this->assertSame($first, $second);
		$this->assertSame('drift', $first['withheld'][0]['reason']);
		$this->assertCount(1, $this->saved, 'the drifted override is flagged on the fold, not on every read');
		$this->assertCount(1, $this->store);
	}//end testTheSameLayersAreFoldedOnce()

	/**
	 * An edited layer is a different key: the new content is folded and served.
	 *
	 * @return void
	 */
	public function testAnEditedLayerIsFoldedAfresh(): void {
		$this->layouts = self::layouts();
		self::read(provider: $this->provider());

		$this->layouts = self::layouts(label: 'Zaakgegevens');
		$answer = self::read(provider: $this->provider());

		$this->assertSame('Zaakgegevens', $answer['items'][0]['tabs'][0]['label']);
		$this->assertCount(2, $this->store);
	}//end testAnEditedLayerIsFoldedAfresh()

	/**
	 * Without a cache every read folds, as before.
	 *
	 * @return void
	 */
	public function testWithoutACacheEveryReadFolds(): void {
		$this->layouts = self::layouts();

		self::read(provider: $this->provider(cached: false));
		self::read(provider: $this->provider(cached: false));

		$this->assertCount(2, $this->saved);
	}//end testWithoutACacheEveryReadFolds()
}//end class
