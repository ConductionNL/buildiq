<?php

/**
 * Unit tests for PageLayoutLeafProvider's host read, single-item read,
 * read-only contract, drift flagging and override base.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\Buildiq\Service\PageLayoutFrozenBase;
use OCA\Buildiq\Service\PageLayoutLayerStack;
use OCA\Buildiq\Service\PageLayoutParts;
use OCA\Buildiq\Service\PageLayoutPresenter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the paths around resolution: reading the host when the caller did
 * not hand it over, `get()`, the refused writes, a failed drift flag and the
 * base an override is cut against.
 *
 * @covers \OCA\Buildiq\Integration\PageLayoutLeafProvider
 * @uses   \OCA\Buildiq\Integration\AppLocalLeafProvider
 * @uses   \OCA\Buildiq\Service\LayoutDeltaService
 * @uses   \OCA\Buildiq\Support\CanonicalJson
 * @uses   \OCA\Buildiq\Service\PageLayoutFrozenBase
 * @uses   \OCA\Buildiq\Service\PageLayoutLayerStack
 * @uses   \OCA\Buildiq\Service\PageLayoutParts
 * @uses   \OCA\Buildiq\Service\PageLayoutPresenter
 */
final class PageLayoutLeafProviderContractTest extends TestCase {
	/**
	 * The register the object service was last pointed at.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the provider. `findAll` answers `$hostRows` when pointed at the
	 * host's register and `$layouts` when pointed at buildiq's own.
	 *
	 * @param array<int, mixed> $layouts What the layout read returns.
	 * @param mixed $hostRows What the host read returns, or a Throwable to throw.
	 *
	 * @return PageLayoutLeafProvider The provider.
	 */
	private function makeProvider(array $layouts, mixed $hostRows = []): PageLayoutLeafProvider {
		$this->objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();

		$this->objectService->method('setRegister')->willReturnCallback(
			function (string $register) {
				$this->register = $register;
				return $this->objectService;
			}
		);
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willReturnCallback(
			function () use ($layouts, $hostRows) {
				if ($this->register === 'layouts-register') {
					return $layouts;
				}

				if ($hostRows instanceof \Throwable) {
					throw $hostRows;
				}

				return $hostRows;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('buildiq', 'register', 'buildiq')->willReturn('layouts-register');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$layers = new PageLayoutLayerStack(userSession: $session, groupManager: $this->createMock(IGroupManager::class));
		$this->logger = $this->createMock(LoggerInterface::class);

		return new PageLayoutLeafProvider(
			objectService: $this->objectService,
			appConfig: $appConfig,
			parts: new PageLayoutParts(
				deltas: new LayoutDeltaService(),
				layers: $layers,
				frozenBase: new PageLayoutFrozenBase(layers: $layers),
				presenter: new PageLayoutPresenter()
			),
			logger: $this->logger,
		);
	}//end makeProvider()

	/**
	 * A published layout.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The layout.
	 */
	private function layout(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pl-schema',
				'status' => 'published',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => '',
				'typeValue' => '',
				'header' => ['titleField' => 'omschrijving', 'fields' => [['field' => 'a']]],
				'tabs' => [],
			],
			$overrides
		);
	}//end layout()

	/**
	 * Without a handed-over host the provider reads it, and resolves against it.
	 *
	 * @return void
	 */
	public function testTheHostIsReadWhenTheCallerDidNotHandItOver(): void {
		$provider = $this->makeProvider(
			[$this->layout(), $this->layout(['id' => 'pl-bouw', 'typeProperty' => 'caseType', 'typeValue' => 'bouw'])],
			[['id' => 'zaak-7', 'caseType' => 'bouw']]
		);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7');

		self::assertSame(1, $listed['total']);
		self::assertSame('pl-bouw', $listed['items'][0]['id']);
	}//end testTheHostIsReadWhenTheCallerDidNotHandItOver()

	/**
	 * An unreadable, missing or malformed host makes the leaf answer nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableHostAnswersNothing(): void {
		$empty = ['items' => [], 'total' => 0, 'nextCursor' => null, 'appliedLayers' => [], 'withheld' => []];

		foreach ([new RuntimeException('db down'), [], ['not-an-object']] as $hostRows) {
			$provider = $this->makeProvider([$this->layout()], $hostRows);
			self::assertSame($empty, $provider->list('dossiq', 'Zaak', 'zaak-7'));
		}
	}//end testAnUnreadableHostAnswersNothing()

	/**
	 * Malformed rows and other registers are skipped when layouts are read.
	 *
	 * @return void
	 */
	public function testMalformedAndForeignLayoutRowsAreSkipped(): void {
		$provider = $this->makeProvider(['junk', $this->layout(['id' => 'pl-other', 'register' => 'elsewhere']), $this->layout()]);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7', ['object' => ['id' => 'zaak-7']]);

		self::assertSame('pl-schema', $listed['items'][0]['id']);
	}//end testMalformedAndForeignLayoutRowsAreSkipped()

	/**
	 * `get()` returns the composed layout by id and refuses any other id with a 404.
	 *
	 * @return void
	 */
	public function testGetReturnsTheAppliedLayoutAndRefusesAnyOther(): void {
		$provider = $this->makeProvider([$this->layout()], [['id' => 'zaak-7']]);

		self::assertSame('pl-schema', $provider->get('dossiq', 'Zaak', 'zaak-7', 'pl-schema')['id']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('404 No published layout with that id applies to this object.');
		$provider->get('dossiq', 'Zaak', 'zaak-7', 'pl-missing');
	}//end testGetReturnsTheAppliedLayoutAndRefusesAnyOther()

	/**
	 * Update and delete are refused: layouts are authored in buildiq.
	 *
	 * @return void
	 */
	public function testUpdateAndDeleteAreRefused(): void {
		$provider = $this->makeProvider([]);

		try {
			$provider->update('dossiq', 'Zaak', 'zaak-7', 'pl-schema', ['tabs' => []]);
			self::fail('update must be refused');
		} catch (RuntimeException $e) {
			self::assertSame('A page layout is edited in buildiq, where the rules that validate it live.', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A page layout is retired in buildiq, not deleted from a consuming app.');
		$provider->delete('dossiq', 'Zaak', 'zaak-7', 'pl-schema');
	}//end testUpdateAndDeleteAreRefused()

	/**
	 * Health names the leaf.
	 *
	 * @return void
	 */
	public function testHealthNamesTheLeaf(): void {
		self::assertSame(['status' => 'ok', 'leaf' => 'buildiq-page-layout'], $this->makeProvider([])->health());
	}//end testHealthNamesTheLeaf()

	/**
	 * A drift flag that cannot be saved is logged, and the page is still served on the base.
	 *
	 * @return void
	 */
	public function testAFailedDriftFlagIsLoggedAndTheBaseStillServed(): void {
		$drifted = $this->layout(
			[
				'id' => 'pl-team',
				'audience' => ['kind' => 'everyone', 'ref' => ''],
				'layoutDelta' => ['header' => ['titleField' => 'other']],
				'baseFingerprint' => 'stale',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouw',
			]
		);
		$provider = $this->makeProvider([$this->layout(), $drifted]);

		$saved = [];
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			static function (mixed ...$args) use (&$saved) {
				$saved = $args;
				throw new RuntimeException('locked');
			}
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'Buildiq: a drifted screen override could not be flagged for review',
			['layout' => 'pl-team', 'exception' => 'locked']
		);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7', ['object' => ['id' => 'zaak-7', 'caseType' => 'bouw']]);

		self::assertSame('pl-schema', $listed['items'][0]['id']);
		self::assertSame('drift', $listed['withheld'][0]['reason']);
		self::assertSame('pl-team', $saved[0]['id']);
		self::assertSame('needs-review', $saved[0]['status']);
		self::assertContains('layouts-register', $saved);
		self::assertContains('pageLayout', $saved);
	}//end testAFailedDriftFlagIsLoggedAndTheBaseStillServed()

	/**
	 * The override base composes the schema-wide layout under the type's whole layout.
	 *
	 * @return void
	 */
	public function testTheOverrideBaseComposesTheWholeLayoutsForEveryone(): void {
		$typed = $this->layout(
			['id' => 'pl-bouw', 'typeProperty' => 'caseType', 'typeValue' => 'bouw', 'header' => ['titleField' => 'kenmerk', 'fields' => [['field' => 'b']]]]
		);
		$provider = $this->makeProvider(
			[$this->layout(['footer' => 'schema-only']), $typed, $this->layout(['id' => 'pl-draft', 'status' => 'draft', 'footer' => 'draft'])]
		);

		$base = $provider->baseForOverride(['register' => 'dossiq', 'schema' => 'Zaak', 'typeProperty' => 'caseType', 'typeValue' => 'bouw']);

		self::assertSame('pl-bouw', $base['id']);
		self::assertSame('kenmerk', $base['header']['titleField']);
		self::assertSame('schema-only', $base['footer']);
	}//end testTheOverrideBaseComposesTheWholeLayoutsForEveryone()

	/**
	 * With nothing published the override base is empty.
	 *
	 * @return void
	 */
	public function testTheOverrideBaseIsEmptyWithNothingPublished(): void {
		self::assertSame([], $this->makeProvider([])->baseForOverride(['register' => 'dossiq', 'schema' => 'Zaak']));
	}//end testTheOverrideBaseIsEmptyWithNothingPublished()
}//end class
