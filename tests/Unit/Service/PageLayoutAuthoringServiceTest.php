<?php

/**
 * Unit tests for PageLayoutAuthoringService.
 *
 * The load-bearing one is the round trip: a fingerprint stamped by the save
 * path has to be accepted by the resolver that later checks it. Stamping and
 * checking live in two classes, and if they ever disagree every override is
 * withheld from every caller, which looks exactly like an override nobody
 * wrote. So the real provider is used here rather than a double.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\Buildiq\Service\PageLayoutAuthoringService;
use OCA\Buildiq\Service\PageLayoutFrozenBase;
use OCA\Buildiq\Service\PageLayoutLayerStack;
use OCA\Buildiq\Service\PageLayoutPresenter;
use OCA\Buildiq\Service\PageLayoutValidator;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the stamp, the refusal, the re-cut and the round trip.
 */
final class PageLayoutAuthoringServiceTest extends TestCase {
	/**
	 * The in-memory store, keyed by layout id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The delta helper.
	 *
	 * @var LayoutDeltaService
	 */
	private LayoutDeltaService $deltas;

	/**
	 * The resolver, shared by the service and the assertions.
	 *
	 * @var PageLayoutLeafProvider
	 */
	private PageLayoutLeafProvider $provider;

	/**
	 * The service under test.
	 *
	 * @var PageLayoutAuthoringService
	 */
	private PageLayoutAuthoringService $service;

	/**
	 * Wire a store both halves read and write.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->deltas = new LayoutDeltaService();
		$this->store = [];

		$objectService = $this->makeObjectService();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => in_array($group, ['behandelaars', 'balie'], true)
		);

		$layers = new PageLayoutLayerStack(userSession: $session, groupManager: $groupManager);

		$this->provider = new PageLayoutLeafProvider(
			objectService: $objectService,
			appConfig: $appConfig,
			deltas: $this->deltas,
			layers: $layers,
			frozenBase: new PageLayoutFrozenBase(layers: $layers),
			presenter: new PageLayoutPresenter(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->service = new PageLayoutAuthoringService(
			objectService: $objectService,
			appConfig: $appConfig,
			validator: new PageLayoutValidator(),
			deltas: $this->deltas,
			provider: $this->provider,
		);
	}//end setUp()

	/**
	 * An object service over the in-memory store.
	 *
	 * `onlyMethods` so the double cannot invent a method the contract lacks:
	 * a double that answers a call the real class would fatal on is a test that
	 * can only pass.
	 *
	 * @return ObjectServiceInterface The double.
	 */
	private function makeObjectService(): ObjectServiceInterface {
		$objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();

		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			fn (): array => array_values($this->store)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->store[(string)($object['id'] ?? '')] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object);
				$entity->method('getUuid')->willReturn((string)($object['id'] ?? ''));

				return $entity;
			}
		);

		return $objectService;
	}//end makeObjectService()

	/**
	 * The schema-wide layout every fixture patches.
	 *
	 * @return array<string, mixed> The layout.
	 */
	private function schemaWide(): array {
		return [
			'id' => 'pl-schema',
			'name' => 'Standaard',
			'status' => 'published',
			'targetApp' => 'dossiq',
			'register' => 'dossiq',
			'schema' => 'Zaak',
			'typeProperty' => '',
			'typeValue' => '',
			'tabs' => [
				['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => ['a']],
				['id' => 'documenten', 'kind' => 'leaf', 'label' => 'Documenten', 'ref' => 'filinq-documents'],
			],
		];
	}//end schemaWide()

	/**
	 * An override for the handlers' group.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The override.
	 */
	private function override(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pl-behandelaars',
				'name' => 'Behandelaarsscherm',
				'status' => 'published',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => '',
				'typeValue' => '',
				'audience' => ['kind' => 'group', 'ref' => 'behandelaars'],
				'layoutDelta' => ['tabs' => ['documenten' => ['$op' => 'remove']]],
			],
			$overrides
		);
	}//end override()

	/**
	 * The tab ids the resolver serves for one case.
	 *
	 * @return array<int, string> The ids.
	 */
	private function servedTabIds(): array {
		$answer = $this->provider->list(
			'dossiq',
			'Zaak',
			'zaak-7',
			['object' => ['id' => 'zaak-7', 'caseType' => 'bouwvergunning']]
		);

		$ids = [];
		foreach (($answer['items'][0]['tabs'] ?? []) as $tab) {
			$ids[] = (string)($tab['id'] ?? '');
		}

		return $ids;
	}//end servedTabIds()

	/**
	 * What the resolver withheld for one case.
	 *
	 * @return array<int, array<string, mixed>> The withheld overrides.
	 */
	private function withheld(): array {
		$answer = $this->provider->list(
			'dossiq',
			'Zaak',
			'zaak-7',
			['object' => ['id' => 'zaak-7', 'caseType' => 'bouwvergunning']]
		);

		return ($answer['withheld'] ?? []);
	}//end withheld()

	/**
	 * The one that matters: what the save path stamps, the resolver accepts.
	 *
	 * @return void
	 */
	public function testAStampedOverrideIsAppliedByTheResolver(): void {
		$this->service->save($this->schemaWide(), 'beheerder');
		$this->service->save($this->override(), 'beheerder');

		$this->assertSame([], $this->withheld(), 'A freshly stamped override must not read as drifted.');
		$this->assertSame(['gegevens'], $this->servedTabIds());
	}//end testAStampedOverrideIsAppliedByTheResolver()

	/**
	 * A fingerprint in the payload is a claim about a base nobody checked, so
	 * it is discarded and the stamped one is used instead.
	 *
	 * @return void
	 */
	public function testAFingerprintInThePayloadIsNotTaken(): void {
		$this->service->save($this->schemaWide(), 'beheerder');
		$saved = $this->service->save(
			$this->override(['baseFingerprint' => 'wat-de-client-zei']),
			'beheerder'
		);

		$this->assertNotSame('wat-de-client-zei', $saved['layout']['baseFingerprint']);
		$this->assertSame([], $this->withheld());
	}//end testAFingerprintInThePayloadIsNotTaken()

	/**
	 * An override with nothing published beneath it would be served on its own,
	 * and the page would show only what the patch names. Refused.
	 *
	 * @return void
	 */
	public function testAnOverrideWithNoBaseIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/no published layout for this schema to patch/');

		$this->service->save($this->override(), 'beheerder');
	}//end testAnOverrideWithNoBaseIsRefused()

	/**
	 * A new override is addressed to whoever saved it, because a drift
	 * notification with no addressee is an override nobody re-cuts.
	 *
	 * @return void
	 */
	public function testANewOverrideIsAddressedToItsAuthor(): void {
		$this->service->save($this->schemaWide(), 'beheerder');
		$saved = $this->service->save($this->override(), 'ayse');

		$this->assertSame('ayse', $saved['layout']['maintainer']);
	}//end testANewOverrideIsAddressedToItsAuthor()

	/**
	 * When the base moves, the resolver withholds the override and names the
	 * path that no longer applies, and the page still shows the base.
	 *
	 * @return void
	 */
	public function testAMovedBaseWithholdsTheOverrideAndStillServesTheBase(): void {
		$this->service->save($this->schemaWide(), 'beheerder');
		$this->service->save($this->override(), 'beheerder');

		$moved = $this->schemaWide();
		$moved['tabs'] = [['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => ['a']]];
		$this->service->save($moved, 'beheerder');

		$withheld = $this->withheld();
		$this->assertCount(1, $withheld);
		$this->assertSame('drift', $withheld[0]['reason']);
		$this->assertSame(['tabs.documenten'], $withheld[0]['orphanedPaths']);
		$this->assertSame(['gegevens'], $this->servedTabIds(), 'The base is still served, so no screen goes blank.');
	}//end testAMovedBaseWithholdsTheOverrideAndStillServesTheBase()

	/**
	 * A re-cut drops the parts that no longer apply, says which those were, and
	 * leaves an override the resolver applies again.
	 *
	 * @return void
	 */
	public function testARecutDropsTheOrphansAndAppliesAgain(): void {
		$this->service->save($this->schemaWide(), 'beheerder');
		$this->service->save(
			$this->override(['layoutDelta' => ['tabs' => ['documenten' => ['$op' => 'remove'], 'gegevens' => ['label' => 'Zaakgegevens']]]]),
			'beheerder'
		);

		$moved = $this->schemaWide();
		$moved['tabs'] = [['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => ['a']]];
		$this->service->save($moved, 'beheerder');

		$result = $this->service->recut('pl-behandelaars');

		$this->assertSame(['tabs.documenten'], $result['dropped']);
		$this->assertSame(['gegevens' => ['label' => 'Zaakgegevens']], $result['layout']['layoutDelta']['tabs']);
		$this->assertSame('published', $result['layout']['status']);
		$this->assertSame([], $this->withheld(), 'A re-cut override is pinned to the base it has now.');
	}//end testARecutDropsTheOrphansAndAppliesAgain()

	/**
	 * A re-cut of something that is not an override is refused rather than
	 * quietly turning a whole layout into a patch of itself.
	 *
	 * @return void
	 */
	public function testAWholeLayoutCannotBeRecut(): void {
		$this->service->save($this->schemaWide(), 'beheerder');

		$this->expectException(InvalidArgumentException::class);
		$this->service->recut('pl-schema');
	}//end testAWholeLayoutCannotBeRecut()

	/**
	 * A re-cut of an id nobody stored is a 404, not a new object.
	 *
	 * @return void
	 */
	public function testRecuttingAnUnknownIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->service->recut('pl-bestaat-niet');
	}//end testRecuttingAnUnknownIdIsRefused()

	/**
	 * The editor's list says which overrides are waiting to be re-cut, so a
	 * maintainer does not have to open a case to find out.
	 *
	 * @return void
	 */
	public function testTheListSaysWhichOverridesDrifted(): void {
		$this->service->save($this->schemaWide(), 'beheerder');
		$this->service->save($this->override(), 'beheerder');

		$moved = $this->schemaWide();
		$moved['tabs'] = [['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => ['a']]];
		$this->service->save($moved, 'beheerder');

		$byId = [];
		foreach ($this->service->listFor('dossiq', 'Zaak') as $layout) {
			$byId[(string)$layout['id']] = $layout;
		}

		$this->assertFalse($byId['pl-schema']['drifted']);
		$this->assertTrue($byId['pl-behandelaars']['drifted']);
		$this->assertSame(['tabs.documenten'], $byId['pl-behandelaars']['orphanedPaths']);
	}//end testTheListSaysWhichOverridesDrifted()

	/**
	 * A whole layout bound to one group composes into what that group sees, but
	 * it must not move the base another override is pinned to: otherwise the
	 * same override reads as current for one colleague and drifted for the next.
	 *
	 * @return void
	 */
	public function testAGroupScopedWholeLayoutDoesNotMoveTheFrozenBase(): void {
		$this->service->save($this->schemaWide(), 'beheerder');

		// Stored directly, and BEFORE the override, because the resolver walks
		// the layers in the order they are stored: a group-wide layout that
		// lands after the override could never have moved its base, so a test
		// written that way would pass whatever the rule said.
		$this->store['pl-groep-geheel'] = [
			'id' => 'pl-groep-geheel',
			'name' => 'Groepsscherm',
			'status' => 'published',
			'targetApp' => 'dossiq',
			'register' => 'dossiq',
			'schema' => 'Zaak',
			'typeProperty' => '',
			'typeValue' => '',
			'audience' => ['kind' => 'group', 'ref' => 'balie'],
			'tabs' => [['id' => 'planning', 'kind' => 'fieldGroup', 'label' => 'Planning', 'fields' => ['b']]],
		];

		$this->service->save($this->override(), 'beheerder');

		$this->assertSame([], $this->withheld(), 'A group-wide whole layout must not make another override read as drifted.');
	}//end testAGroupScopedWholeLayoutDoesNotMoveTheFrozenBase()
}//end class
