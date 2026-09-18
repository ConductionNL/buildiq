<?php

/**
 * Unit tests for PageLayoutLeafProvider.
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
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003, REQ-OBPL-006, REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\Buildiq\Service\PageLayoutLayerStack;
use OCA\Buildiq\Service\PageLayoutPresenter;
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
 * Covers the resolution order, the fall-backs, the whole-header replacement and
 * the condition evaluation.
 */
final class PageLayoutLeafProviderTest extends TestCase {
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
		// onlyMethods: the double may not invent a method the real contract lacks.
		$saved = &$this->saved;

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
			deltas: new LayoutDeltaService(),
			layers: new PageLayoutLayerStack(userSession: $session, groupManager: $groupManager),
			presenter: new PageLayoutPresenter(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeProvider()

	/**
	 * Layouts written back during the test, which is how a drifted override
	 * reaching `needs-review` is observed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

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
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => '',
				'typeValue' => '',
				'header' => ['titleField' => 'omschrijving', 'fields' => [['field' => 'a'], ['field' => 'b'], ['field' => 'c'], ['field' => 'd']]],
				'tabs' => [],
			],
			$overrides
		);
	}//end layout()

	/**
	 * The case the provider is asked about.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The host object, handed in so no second read is needed.
	 */
	private function host(array $overrides = []): array {
		return ['object' => array_merge(['id' => 'zaak-7', 'caseType' => 'bouwvergunning'], $overrides)];
	}//end host()

	/**
	 * The type's own layout wins over the schema-wide one (REQ-OBPL-003).
	 *
	 * @return void
	 */
	public function testATypeLayoutWinsOverTheSchemaWideOne(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(),
				$this->layout(['id' => 'pl-bouw', 'typeProperty' => 'caseType', 'typeValue' => 'bouwvergunning']),
			]
		);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(1, $listed['total']);
		self::assertSame('pl-bouw', $listed['items'][0]['id']);
	}//end testATypeLayoutWinsOverTheSchemaWideOne()

	/**
	 * A type with no layout of its own falls back to the schema-wide one.
	 *
	 * @return void
	 */
	public function testATypeWithNoLayoutFallsBackToTheSchemaWideOne(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(),
				$this->layout(['id' => 'pl-melding', 'typeProperty' => 'caseType', 'typeValue' => 'melding']),
			]
		);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame('pl-schema', $listed['items'][0]['id']);
	}//end testATypeWithNoLayoutFallsBackToTheSchemaWideOne()

	/**
	 * With no layout at all the leaf answers NOTHING, and the consuming app
	 * renders its own manifest. That fall-back is what lets buildiq be absent
	 * entirely (REQ-OBPL-003).
	 *
	 * @return void
	 */
	public function testWithNoLayoutTheLeafAnswersNothing(): void {
		$provider = $this->makeProvider([]);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host());

		self::assertSame(0, $listed['total']);
		self::assertSame([], $listed['items']);
	}//end testWithNoLayoutTheLeafAnswersNothing()

	/**
	 * A DRAFT layout never resolves, whatever else exists. That is what lets the
	 * next version be built where everyone can see it.
	 *
	 * @return void
	 */
	public function testADraftLayoutNeverResolves(): void {
		$provider = $this->makeProvider(
			[$this->layout(['id' => 'pl-draft', 'status' => 'draft', 'typeProperty' => 'caseType', 'typeValue' => 'bouwvergunning'])]
		);

		self::assertSame(0, $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['total']);
	}//end testADraftLayoutNeverResolves()

	/**
	 * A layout for another schema is never offered.
	 *
	 * @return void
	 */
	public function testALayoutForAnotherSchemaIsNotOffered(): void {
		$provider = $this->makeProvider([$this->layout(['schema' => 'Taak'])]);

		self::assertSame(0, $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['total']);
	}//end testALayoutForAnotherSchemaIsNotOffered()

	/**
	 * A type header REPLACES the schema-wide header whole. Merging field by field
	 * would leave a page showing four fields an administrator thought they had
	 * removed, with nothing to say where they came from (REQ-OBPL-007).
	 *
	 * @return void
	 */
	public function testATypeHeaderReplacesTheSchemaHeaderWhole(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(),
				$this->layout(
					[
						'id' => 'pl-bouw',
						'typeProperty' => 'caseType',
						'typeValue' => 'bouwvergunning',
						'header' => ['titleField' => 'omschrijving', 'fields' => [['field' => 'location']]],
					]
				),
			]
		);

		$header = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['header'];

		self::assertCount(1, $header['fields']);
		self::assertSame('location', $header['fields'][0]['field']);
	}//end testATypeHeaderReplacesTheSchemaHeaderWhole()

	/**
	 * A type layout that declares NO header at all still gets the schema-wide
	 * one. That is a different thing from declaring a shorter header, and the two
	 * must not be confused.
	 *
	 * @return void
	 */
	public function testATypeLayoutWithNoHeaderStillGetsTheSchemaWideOne(): void {
		$bouw = $this->layout(['id' => 'pl-bouw', 'typeProperty' => 'caseType', 'typeValue' => 'bouwvergunning']);
		unset($bouw['header']);

		$provider = $this->makeProvider([$this->layout(), $bouw]);

		$header = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['header'];

		self::assertCount(4, $header['fields']);
	}//end testATypeLayoutWithNoHeaderStillGetsTheSchemaWideOne()

	/**
	 * A widget whose conditions do not hold is NOT SENT. Sending it with a hidden
	 * flag would put the data on the wire for anybody who opens the network tab
	 * (REQ-OBPL-006).
	 *
	 * @return void
	 */
	public function testAWidgetWhoseConditionsDoNotHoldIsNotSent(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(
					[
						'widgets' => [
							['id' => 'cn-decision', 'conditions' => [['field' => 'resultType', 'operator' => 'isNotEmpty']]],
							['id' => 'cn-timeline'],
						],
					]
				),
			]
		);

		$widgets = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['widgets'];

		self::assertSame(['cn-timeline'], array_map(static fn (array $w): string => (string)$w['id'], $widgets));
	}//end testAWidgetWhoseConditionsDoNotHoldIsNotSent()

	/**
	 * The same widget IS sent once the field it watches has a value.
	 *
	 * @return void
	 */
	public function testTheSameWidgetIsSentOnceItsConditionHolds(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(
					['widgets' => [['id' => 'cn-decision', 'conditions' => [['field' => 'resultType', 'operator' => 'isNotEmpty']]]]]
				),
			]
		);

		$widgets = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host(['resultType' => 'vergund']))['items'][0]['widgets'];

		self::assertCount(1, $widgets);
	}//end testTheSameWidgetIsSentOnceItsConditionHolds()

	/**
	 * An operator nothing evaluates drops the widget rather than passing it. A
	 * widget shown on every case because of a typo is worse than one missing.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorDropsTheWidgetRatherThanPassing(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(
					['widgets' => [['id' => 'cn-decision', 'conditions' => [['field' => 'resultType', 'operator' => 'isnotempty']]]]]
				),
			]
		);

		self::assertSame([], $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host(['resultType' => 'vergund']))['items'][0]['widgets']);
	}//end testAnUnknownOperatorDropsTheWidgetRatherThanPassing()

	/**
	 * A widget with no conditions is always sent, which is what makes conditions
	 * opt-in rather than a thing every widget has to declare.
	 *
	 * @return void
	 */
	public function testAWidgetWithNoConditionsIsAlwaysSent(): void {
		$provider = $this->makeProvider([$this->layout(['widgets' => [['id' => 'cn-timeline']]])]);

		self::assertCount(1, $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['widgets']);
	}//end testAWidgetWithNoConditionsIsAlwaysSent()

	/**
	 * `highContrast` is served as declared and changes nothing about which
	 * widgets come back: a contrast preference must not silently remove one.
	 *
	 * @return void
	 */
	public function testHighContrastIsServedAndChangesNothingElse(): void {
		$provider = $this->makeProvider(
			[$this->layout(['widgets' => [['id' => 'cn-term', 'highContrast' => true]]])]
		);

		$widgets = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['widgets'];

		self::assertCount(1, $widgets);
		self::assertTrue($widgets[0]['highContrast']);
	}//end testHighContrastIsServedAndChangesNothingElse()

	/**
	 * The widgets come back in the order the administrator set, not the stored
	 * order of the array.
	 *
	 * @return void
	 */
	public function testWidgetsComeBackInTheDeclaredOrder(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(
					['widgets' => [['id' => 'third', 'order' => 30], ['id' => 'first', 'order' => 10], ['id' => 'second', 'order' => 20]]]
				),
			]
		);

		$widgets = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['widgets'];

		self::assertSame(['first', 'second', 'third'], array_map(static fn (array $w): string => (string)$w['id'], $widgets));
	}//end testWidgetsComeBackInTheDeclaredOrder()

	/**
	 * A type layout that declares no task list falls back to the schema-wide
	 * column set, by the same resolution order (REQ-OBPL-008).
	 *
	 * @return void
	 */
	public function testATypeWithNoTaskListFallsBackToTheSchemaWideColumns(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(['taskList' => ['columns' => [['field' => 'zaaknummer']]]]),
				$this->layout(['id' => 'pl-bouw', 'typeProperty' => 'caseType', 'typeValue' => 'bouwvergunning']),
			]
		);

		$taskList = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['taskList'];

		self::assertSame('zaaknummer', $taskList['columns'][0]['field']);
	}//end testATypeWithNoTaskListFallsBackToTheSchemaWideColumns()

	/**
	 * A type's own upload fields win over the schema-wide ones (REQ-OBPL-009).
	 *
	 * @return void
	 */
	public function testATypeOwnUploadFieldsWin(): void {
		$provider = $this->makeProvider(
			[
				$this->layout(['uploadFields' => [['field' => 'documentType']]]),
				$this->layout(
					[
						'id' => 'pl-bouw',
						'typeProperty' => 'caseType',
						'typeValue' => 'bouwvergunning',
						'uploadFields' => [['field' => 'confidentiality', 'visibility' => 'readOnly', 'default' => 'intern']],
					]
				),
			]
		);

		$fields = $provider->list('dossiq', 'Zaak', 'zaak-7', $this->host())['items'][0]['uploadFields'];

		self::assertCount(1, $fields);
		self::assertSame('confidentiality', $fields[0]['field']);
		self::assertSame('intern', $fields[0]['default']);
	}//end testATypeOwnUploadFieldsWin()

	/**
	 * The leaf offers no create at all: a layout is authored in buildiq
	 * (REQ-OBPL-003).
	 *
	 * @return void
	 */
	public function testTheLeafOffersNoCreate(): void {
		$provider = $this->makeProvider([]);

		$this->expectException(RuntimeException::class);

		$provider->create('dossiq', 'Zaak', 'zaak-7', ['tabs' => []]);
	}//end testTheLeafOffersNoCreate()
}//end class
