<?php

/**
 * Unit tests for screen-override resolution in PageLayoutLeafProvider.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003, REQ-OBSO-004, REQ-OBSO-005, REQ-OBSO-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the layer order, the audience rules, the drift path and the answer
 * that names what composed it.
 */
final class ScreenOverrideResolutionTest extends TestCase {
	/**
	 * Layouts written back, which is how a drifted override reaching
	 * `needs-review` is observed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The delta helper, shared so a fixture can fingerprint its own base.
	 *
	 * @var LayoutDeltaService
	 */
	private LayoutDeltaService $deltas;

	/**
	 * Build the helper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->deltas = new LayoutDeltaService();
	}//end setUp()

	/**
	 * Build the provider over the stored layouts.
	 *
	 * @param array<int, array<string, mixed>> $layouts The stored layouts.
	 * @param string|null $uid The signed-in user, or null for a visitor with no account.
	 * @param array<int, string> $groups The groups that user is in.
	 *
	 * @return PageLayoutLeafProvider The provider.
	 */
	private function makeProvider(array $layouts, ?string $uid = 'handler', array $groups = []): PageLayoutLeafProvider {
		$saved = &$this->saved;

		// onlyMethods: the double may not invent a method the real contract
		// lacks, which is how a green suite once covered a call that 500s.
		$objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();

		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($layouts);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved) {
				$saved[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object);
				$entity->method('getUuid')->willReturn((string)($object['id'] ?? ''));

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => in_array($group, $groups, true)
		);

		return new PageLayoutLeafProvider(
			objectService: $objectService,
			appConfig: $appConfig,
			deltas: $this->deltas,
			userSession: $session,
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeProvider()

	/**
	 * The schema-wide base every fixture starts from.
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
	 * The type layout for building permits.
	 *
	 * @return array<string, mixed> The layout.
	 */
	private function typeLayout(): array {
		return array_merge(
			$this->schemaWide(),
			[
				'id' => 'pl-bouw',
				'name' => 'Bouwvergunning',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'tabs' => [
					['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => ['a']],
					['id' => 'documenten', 'kind' => 'leaf', 'label' => 'Documenten', 'ref' => 'filinq-documents'],
					['id' => 'bouwwerk', 'kind' => 'fieldGroup', 'label' => 'Bouwwerk', 'fields' => ['oppervlakte']],
				],
			]
		);
	}//end typeLayout()

	/**
	 * What the schema-wide and type layouts compose to, which is the base an
	 * override is cut against.
	 *
	 * @return array<string, mixed> The composed base.
	 */
	private function composedBase(): array {
		$patchable = [];
		foreach (['id', 'name', 'typeProperty', 'typeValue', 'header', 'tabs', 'widgets', 'taskList', 'uploadFields'] as $key) {
			if (array_key_exists($key, $this->typeLayout()) === true) {
				$patchable[$key] = $this->typeLayout()[$key];
			}
		}

		return $this->deltas->merge($this->schemaWide(), $patchable);
	}//end composedBase()

	/**
	 * An override patching the composed base for one audience.
	 *
	 * @param string $kind The audience kind.
	 * @param string $ref Which group, team or user.
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The override.
	 */
	private function override(string $kind, string $ref, array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pl-' . $kind,
				'name' => ucfirst($kind) . ' scherm',
				'status' => 'published',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'audience' => ['kind' => $kind, 'ref' => $ref],
				'layoutDelta' => ['tabs' => ['documenten' => ['$op' => 'remove']]],
				'baseFingerprint' => $this->deltas->fingerprint($this->composedBase()),
				'baseCutAt' => '2026-09-18T10:00:00Z',
				'maintainer' => 'beheerder',
			],
			$overrides
		);
	}//end override()

	/**
	 * The host object, handed in so no second read is needed.
	 *
	 * @return array<string, mixed> The filters carrying it.
	 */
	private function host(): array {
		return ['object' => ['id' => 'zaak-7', 'caseType' => 'bouwvergunning']];
	}//end host()

	/**
	 * The ids of the tabs in the served layout.
	 *
	 * @param array<string, mixed> $answer The provider's answer.
	 *
	 * @return array<int, string> The tab ids.
	 */
	private function tabIds(array $answer): array {
		return array_map(static fn (array $tab): string => (string)$tab['id'], $answer['items'][0]['tabs']);
	}//end tabIds()

	/**
	 * The narrow screen composes over the wide one: schema-wide, then the type
	 * layout, then the team override, in that order (REQ-OBSO-005).
	 *
	 * @return void
	 */
	public function testTheNarrowScreenComposesOverTheWideOne(): void {
		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('team', 'behandelaars')],
			'handler',
			['behandelaars']
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['gegevens', 'bouwwerk'], $this->tabIds($answer));
		self::assertSame(['pl-schema', 'pl-bouw', 'pl-team'], array_column($answer['appliedLayers'], 'id'));
	}//end testTheNarrowScreenComposesOverTheWideOne()

	/**
	 * A caller who is NOT in the team gets the layers without that override.
	 *
	 * @return void
	 */
	public function testACallerOutsideTheTeamDoesNotGetItsOverride(): void {
		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('team', 'behandelaars')],
			'someone-else',
			[]
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['gegevens', 'documenten', 'bouwwerk'], $this->tabIds($answer));
	}//end testACallerOutsideTheTeamDoesNotGetItsOverride()

	/**
	 * A visitor with no account never gets a group-bound override, whatever the
	 * consumer asks for, and does get the portal one (REQ-OBSO-004).
	 *
	 * @return void
	 */
	public function testAPortalVisitorNeverGetsAnInternalOverride(): void {
		$provider = $this->makeProvider(
			[
				$this->schemaWide(),
				$this->typeLayout(),
				$this->override('group', 'frontoffice'),
				$this->override(
					'portal',
					'',
					['id' => 'pl-portal', 'layoutDelta' => ['tabs' => ['gegevens' => ['label' => 'Uw aanvraag']]]]
				),
			],
			null
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['pl-schema', 'pl-bouw', 'pl-portal'], array_column($answer['appliedLayers'], 'id'));

		// The group override's removal of the documents tab did NOT happen.
		self::assertContains('documenten', $this->tabIds($answer));
		self::assertSame('Uw aanvraag', $answer['items'][0]['tabs'][0]['label']);
	}//end testAPortalVisitorNeverGetsAnInternalOverride()

	/**
	 * A draft override changes nothing at all.
	 *
	 * @return void
	 */
	public function testADraftOverrideChangesNothing(): void {
		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('team', 'behandelaars', ['status' => 'draft'])],
			'handler',
			['behandelaars']
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['pl-schema', 'pl-bouw'], array_column($answer['appliedLayers'], 'id'));
		self::assertSame([], $answer['withheld']);
	}//end testADraftOverrideChangesNothing()

	/**
	 * A drifted override is WITHHELD, neither applied nor dropped: the base is
	 * served, the answer names it with the reason, and the override moves to
	 * needs-review, which is what fires the notification to its maintainer
	 * (REQ-OBSO-003).
	 *
	 * @return void
	 */
	public function testADriftedOverrideIsWithheldAndAnnounced(): void {
		$drifted = $this->override('team', 'behandelaars', ['baseFingerprint' => 'a-fingerprint-of-an-older-base']);

		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $drifted],
			'handler',
			['behandelaars']
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		// The page still renders, on the base. Loud does not mean an error page.
		self::assertSame(['gegevens', 'documenten', 'bouwwerk'], $this->tabIds($answer));

		// And the answer says so.
		self::assertCount(1, $answer['withheld']);
		self::assertSame('pl-team', $answer['withheld'][0]['id']);
		self::assertSame('drift', $answer['withheld'][0]['reason']);

		// And the maintainer is told, through the lifecycle state the
		// declarative notification watches.
		self::assertCount(1, $this->saved);
		self::assertSame('needs-review', $this->saved[0]['status']);
	}//end testADriftedOverrideIsWithheldAndAnnounced()

	/**
	 * The stored patch SURVIVES the drift, so a maintainer can re-cut it rather
	 * than write it again from nothing (REQ-OBSO-003).
	 *
	 * @return void
	 */
	public function testTheStoredPatchSurvivesTheDrift(): void {
		$drifted = $this->override('team', 'behandelaars', ['baseFingerprint' => 'stale']);

		$provider = $this->makeProvider([$this->schemaWide(), $this->typeLayout(), $drifted], 'handler', ['behandelaars']);
		$provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['tabs' => ['documenten' => ['$op' => 'remove']]], $this->saved[0]['layoutDelta']);
	}//end testTheStoredPatchSurvivesTheDrift()

	/**
	 * An override already in review is not flagged again, so a busy page does not
	 * notify its maintainer once per request.
	 *
	 * @return void
	 */
	public function testAnOverrideAlreadyInReviewIsNotFlaggedAgain(): void {
		$drifted = $this->override('team', 'behandelaars', ['baseFingerprint' => 'stale', 'status' => 'needs-review']);

		$provider = $this->makeProvider([$this->schemaWide(), $this->typeLayout(), $drifted], 'handler', ['behandelaars']);
		$provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame([], $this->saved);
	}//end testAnOverrideAlreadyInReviewIsNotFlaggedAgain()

	/**
	 * An override whose every patch path is orphaned still names itself in the
	 * answer, and the page renders on the base. Nothing is silently dropped
	 * (REQ-OBSO-003).
	 *
	 * @return void
	 */
	public function testAnEntirelyOrphanedOverrideIsStillNamed(): void {
		$orphaned = $this->override(
			'team',
			'behandelaars',
			['baseFingerprint' => 'stale', 'layoutDelta' => ['tabs' => ['besluit' => ['label' => 'Besluit gewijzigd']]]]
		);

		$provider = $this->makeProvider([$this->schemaWide(), $this->typeLayout(), $orphaned], 'handler', ['behandelaars']);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(1, $answer['total']);
		self::assertSame(['tabs.besluit'], $answer['withheld'][0]['orphanedPaths']);
	}//end testAnEntirelyOrphanedOverrideIsStillNamed()

	/**
	 * A layout with neither baseRef nor layoutDelta resolves exactly as it did
	 * before overrides existed, so no stored layout changed meaning
	 * (REQ-OBSO-001).
	 *
	 * @return void
	 */
	public function testALayoutWithNoDeltaKeepsItsOldBehaviour(): void {
		$provider = $this->makeProvider([$this->schemaWide(), $this->typeLayout()], 'handler');

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['gegevens', 'documenten', 'bouwwerk'], $this->tabIds($answer));
		self::assertSame([], $answer['withheld']);
	}//end testALayoutWithNoDeltaKeepsItsOldBehaviour()

	/**
	 * The answer names the layers that composed it, in order and with their
	 * audiences. A handler who sees fewer tabs than a colleague has no other way
	 * to learn why, and neither has the administrator who is asked about it
	 * (REQ-OBSO-006).
	 *
	 * @return void
	 */
	public function testTheAnswerNamesTheLayersThatComposedIt(): void {
		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('group', 'frontoffice')],
			'handler',
			['frontoffice']
		);

		$layers = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['appliedLayers'];

		self::assertCount(3, $layers);
		self::assertSame('Standaard', $layers[0]['name']);
		self::assertSame('everyone', $layers[0]['audience']['kind']);
		self::assertSame('group', $layers[2]['audience']['kind']);
		self::assertSame('frontoffice', $layers[2]['audience']['ref']);
	}//end testTheAnswerNamesTheLayersThatComposedIt()

	/**
	 * A user override is the last word, composing over the audience override.
	 *
	 * @return void
	 */
	public function testAUserOverrideComposesLast(): void {
		$userOverride = $this->override(
			'user',
			'handler',
			['id' => 'pl-user', 'layoutDelta' => ['tabs' => ['gegevens' => ['label' => 'Mijn weergave']]]]
		);

		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('team', 'behandelaars'), $userOverride],
			'handler',
			['behandelaars']
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['pl-schema', 'pl-bouw', 'pl-team', 'pl-user'], array_column($answer['appliedLayers'], 'id'));
	}//end testAUserOverrideComposesLast()

	/**
	 * A user override belonging to SOMEBODY ELSE is never applied, however the
	 * consumer asks.
	 *
	 * @return void
	 */
	public function testAnotherPersonsUserOverrideIsNeverApplied(): void {
		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('user', 'somebody-else', ['id' => 'pl-user'])],
			'handler'
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['pl-schema', 'pl-bouw'], array_column($answer['appliedLayers'], 'id'));
	}//end testAnotherPersonsUserOverrideIsNeverApplied()

	/**
	 * A group-bound override cannot be reached by a caller with no account even
	 * when there is no portal override at all: the fall-back is the base, never
	 * the internal screen.
	 *
	 * @return void
	 */
	public function testAVisitorWithNoAccountFallsBackToTheBaseNotTheInternalScreen(): void {
		$provider = $this->makeProvider(
			[$this->schemaWide(), $this->typeLayout(), $this->override('group', 'frontoffice')],
			null
		);

		$answer = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(['pl-schema', 'pl-bouw'], array_column($answer['appliedLayers'], 'id'));
		self::assertContains('documenten', $this->tabIds($answer));
	}//end testAVisitorWithNoAccountFallsBackToTheBaseNotTheInternalScreen()
}//end class
