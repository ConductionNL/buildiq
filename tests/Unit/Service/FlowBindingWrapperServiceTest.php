<?php

/**
 * FlowBindingWrapperServiceTest
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\FlowBindingWrapperService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the wrapper flows of an application's `runOn` entries.
 *
 * @covers \OCA\Buildiq\Service\FlowBindingWrapperService
 */
class FlowBindingWrapperServiceTest extends TestCase {

	/**
	 * The bound flow "Archiveer dossier".
	 *
	 * @var string
	 */
	private const BOUND = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc';

	/**
	 * The application's uuid.
	 *
	 * @var string
	 */
	private const APP = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * OpenRegister's flow service.
	 *
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flows;

	/**
	 * The object service the Application is saved through.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * OpenRegister's flow versioning.
	 *
	 * @var FlowVersionServiceInterface&MockObject
	 */
	private FlowVersionServiceInterface&MockObject $versions;

	/**
	 * The container the versioning is resolved from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Every flow definition saved, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The flows the flow service holds, by uuid.
	 *
	 * @var array<string,Flow>
	 */
	private array $stored = [];

	/**
	 * The Application payloads saved, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $applications = [];

	/**
	 * Wire a flow service that mints `wrapper-<n>` uuids and a container that publishes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->flows = $this->createMock(FlowService::class);
		$this->flows->method('save')->willReturnCallback(
			function (array $data): Flow {
				$this->saved[] = $data;
				$flow = new Flow();
				$flow->setUuid('wrapper-' . count($this->saved));
				$flow->setApp($data['app'] ?? null);
				$flow->setApplicationSlug($data['applicationSlug'] ?? null);
				$this->stored[$flow->getUuid()] = $flow;
				return $flow;
			}
		);
		$this->flows->method('find')->willReturnCallback(
			function (string $uuid): Flow {
				if (isset($this->stored[$uuid]) === false) {
					throw new RuntimeException('No such flow');
				}

				return $this->stored[$uuid];
			}
		);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->applications[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$this->versions = $this->createMock(FlowVersionServiceInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('has')->willReturnCallback(static fn (string $id): bool => $id === FlowVersionServiceInterface::class);
		$this->container->method('get')->willReturnCallback(fn (): object => $this->versions);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return FlowBindingWrapperService The service.
	 */
	private function service(): FlowBindingWrapperService {
		return new FlowBindingWrapperService(
			flowService: $this->flows,
			objectService: $this->objects,
			container: $this->container,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * App `permits` with "Archiveer dossier" bound and these `runOn` entries.
	 *
	 * @param list<array<string,string>>    $runOn The entries.
	 * @param list<array<string,mixed>>     $extra Further bindings.
	 *
	 * @return array<string,mixed> The Application.
	 */
	private static function app(array $runOn, array $extra = []): array {
		return [
			'slug' => 'permits',
			'name' => 'Permits',
			'flows' => array_merge(
				[['flow' => self::BOUND, 'label' => 'Archiveer dossier', 'runOn' => $runOn]],
				$extra
			),
		];

	}//end app()

	/**
	 * A "Run on vergunning, updated" entry gets a wrapper: an object trigger on the
	 * app's register and schema, then a sub-flow that starts the bound flow without
	 * waiting. The wrapper is published and bound with the bound flow as its source.
	 *
	 * @return void
	 */
	public function testAnEventEntryGetsAPublishedBoundWrapper(): void {
		$this->versions->expects($this->once())->method('publish')
			->with($this->callback(static fn (Flow $flow): bool => $flow->getUuid() === 'wrapper-1'));

		$result = $this->service()->sync(
			self::APP,
			self::app([['schema' => 'vergunning', 'event' => 'object.updated']]),
			'permits-register'
		);

		$this->assertCount(expectedCount: 1, haystack: $this->saved);
		$definition = $this->saved[0];
		$name = FlowBindingWrapperService::wrapperName(
			appSlug: 'permits',
			flow: self::BOUND,
			entry: ['schema' => 'vergunning', 'event' => 'object.updated']
		);
		$this->assertSame(expected: $name, actual: $definition['name']);
		$this->assertStringStartsWith(prefix: 'aut-attach-permits-', string: $name);
		$this->assertSame(
			expected: [
				['id' => 'trigger', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'object.updated', 'register' => 'permits-register', 'schema' => 'vergunning']],
				['id' => 'run', 'type' => 'openregister.sub-flow', 'config' => ['flowId' => self::BOUND, 'wait' => false]],
			],
			actual: $definition['nodes']
		);
		$this->assertSame(expected: [['id' => 'trigger-run', 'from' => 'trigger', 'to' => 'run']], actual: $definition['edges']);
		$this->assertSame(expected: 'buildiq', actual: $definition['app']);
		$this->assertSame(expected: 'permits', actual: $definition['applicationSlug']);

		$this->assertSame(expected: 1, actual: $result['created']);
		$this->assertSame(expected: [], actual: $result['failed']);
		$this->assertSame(
			expected: ['flow' => 'wrapper-1', 'label' => $name, 'sourceUuid' => self::BOUND],
			actual: $result['flows'][1]
		);
		$this->assertCount(expectedCount: 1, haystack: $this->applications);
		$this->assertSame(expected: $result['flows'], actual: $this->applications[0]['flows']);

	}//end testAnEventEntryGetsAPublishedBoundWrapper()

	/**
	 * The bound flow itself is never saved or deleted, and its binding is passed on as it was.
	 *
	 * @return void
	 */
	public function testTheBoundFlowIsUntouched(): void {
		$this->flows->expects($this->never())->method('delete');
		$application = self::app([['schema' => 'vergunning', 'event' => 'object.created']]);

		$result = $this->service()->sync(self::APP, $application, 'r');

		$this->assertSame(expected: $application['flows'][0], actual: $result['flows'][0]);
		foreach ($this->saved as $definition) {
			$this->assertNotSame(expected: 'Archiveer dossier', actual: $definition['name']);
		}

	}//end testTheBoundFlowIsUntouched()

	/**
	 * A re-save with the same entries keeps every wrapper and writes nothing.
	 *
	 * @return void
	 */
	public function testAReSaveWithTheSameEntriesIsIdempotent(): void {
		$first = $this->service()->sync(self::APP, self::app([['schema' => 'vergunning', 'event' => 'object.updated']]), 'r');
		$this->saved = [];
		$this->applications = [];
		$this->flows->expects($this->never())->method('delete');

		$again = $this->service()->sync(self::APP, ['slug' => 'permits', 'flows' => $first['flows']], 'r');

		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertSame(expected: [], actual: $this->applications, message: 'Nothing changed, so the Application is not saved again.');
		$this->assertSame(expected: $first['flows'], actual: $again['flows']);
		$this->assertSame(expected: [0, 0], actual: [$again['created'], $again['removed']]);

	}//end testAReSaveWithTheSameEntriesIsIdempotent()

	/**
	 * Removing an entry deletes and unbinds its wrapper; removing the binding removes every wrapper it had.
	 *
	 * @return void
	 */
	public function testARemovedEntryOrBindingRemovesItsWrappers(): void {
		$both = $this->service()->sync(
			self::APP,
			self::app([['schema' => 'vergunning', 'event' => 'object.updated'], ['schema' => 'zaak', 'mode' => 'from-record']]),
			'r'
		);
		$this->assertCount(expectedCount: 3, haystack: $both['flows']);
		$byLabel = array_column($both['flows'], 'flow', 'label');
		$updated = $byLabel[FlowBindingWrapperService::wrapperName('permits', self::BOUND, ['schema' => 'vergunning', 'event' => 'object.updated'])];
		$fromRecord = $byLabel[FlowBindingWrapperService::wrapperName('permits', self::BOUND, ['schema' => 'zaak', 'mode' => 'from-record'])];
		$deleted = [];
		$this->flows->method('delete')->willReturnCallback(
			static function (string $uuid) use (&$deleted): void {
				$deleted[] = $uuid;
			}
		);

		$flows = $both['flows'];
		$flows[0]['runOn'] = [['schema' => 'vergunning', 'event' => 'object.updated']];
		$one = $this->service()->sync(self::APP, ['slug' => 'permits', 'flows' => $flows], 'r');

		$this->assertSame(expected: [$fromRecord], actual: $deleted);
		$this->assertSame(expected: 1, actual: $one['removed']);
		$this->assertSame(expected: [self::BOUND, $updated], actual: array_column($one['flows'], 'flow'));

		$none = $this->service()->sync(self::APP, ['slug' => 'permits', 'flows' => [$one['flows'][1]]], 'r');

		$this->assertSame(expected: [$fromRecord, $updated], actual: $deleted);
		$this->assertSame(expected: [], actual: $none['flows']);

	}//end testARemovedEntryOrBindingRemovesItsWrappers()

	/**
	 * A from-record entry gets a manual trigger, and needs no register.
	 *
	 * @return void
	 */
	public function testAFromRecordEntryGetsAManualTrigger(): void {
		$result = $this->service()->sync(self::APP, self::app([['schema' => 'zaak', 'mode' => 'from-record']]), '');

		$this->assertSame(expected: [], actual: $result['failed']);
		$this->assertSame(
			expected: ['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => []],
			actual: $this->saved[0]['nodes'][0]
		);
		$this->assertStringContainsString(needle: 'from a record of zaak', haystack: $this->saved[0]['description']);

	}//end testAFromRecordEntryGetsAManualTrigger()

	/**
	 * Without a register an event entry is reported and nothing is saved for it.
	 *
	 * @return void
	 */
	public function testAnEventEntryWithoutARegisterIsReported(): void {
		$result = $this->service()->sync(self::APP, self::app([['schema' => 'vergunning', 'event' => 'object.deleted']]), '');

		$this->assertSame(expected: [], actual: $this->saved);
		$this->assertSame(expected: 'no_register', actual: $result['failed'][0]['reason']);
		$this->assertSame(expected: 0, actual: $result['created']);

	}//end testAnEventEntryWithoutARegisterIsReported()

	/**
	 * A wrapper that cannot be published, saved or removed is reported; one that cannot be removed stays bound.
	 *
	 * @return void
	 */
	public function testFailuresAreReportedAndAnUndeletableWrapperStaysBound(): void {
		$this->versions->method('publish')->willThrowException(new RuntimeException('no draft'));
		$first = $this->service()->sync(self::APP, self::app([['schema' => 'vergunning', 'event' => 'object.updated']]), 'r');
		$this->assertSame(expected: 'not_published', actual: $first['failed'][0]['reason']);
		$this->assertSame(expected: 1, actual: $first['created'], message: 'Saved and bound, so it stays visible.');

		$this->flows->method('delete')->willThrowException(new RuntimeException('locked'));
		$flows = $first['flows'];
		$flows[0]['runOn'] = [];
		$result = $this->service()->sync(self::APP, ['slug' => 'permits', 'flows' => $flows], 'r');

		$this->assertSame(expected: 'remove_failed', actual: $result['failed'][0]['reason']);
		$this->assertSame(expected: 0, actual: $result['removed']);
		$this->assertSame(expected: $flows, actual: $result['flows']);

	}//end testFailuresAreReportedAndAnUndeletableWrapperStaysBound()

	/**
	 * A binding with a wrapper's label that names a flow buildiq does not keep for
	 * this application is never deleted: it stays bound and is reported.
	 *
	 * @return void
	 */
	public function testOnlyThisAppsOwnWrapperFlowIsDeleted(): void {
		$foreign = new Flow();
		$foreign->setUuid('foreign-flow');
		$foreign->setApp('hermiq');
		$foreign->setApplicationSlug('permits');
		$this->stored['foreign-flow'] = $foreign;
		$otherApp = new Flow();
		$otherApp->setUuid('other-application-wrapper');
		$otherApp->setApp('buildiq');
		$otherApp->setApplicationSlug('licences');
		$this->stored['other-application-wrapper'] = $otherApp;
		$this->flows->expects($this->never())->method('delete');

		$forged = [
			['flow' => 'foreign-flow', 'label' => FlowBindingWrapperService::NAME_PREFIX . 'permits-aaaaaaaaaaaa', 'sourceUuid' => self::BOUND],
			['flow' => 'other-application-wrapper', 'label' => FlowBindingWrapperService::NAME_PREFIX . 'permits-bbbbbbbbbbbb', 'sourceUuid' => self::BOUND],
		];
		$result = $this->service()->sync(self::APP, self::app([], $forged), 'r');

		$this->assertSame(expected: 0, actual: $result['removed']);
		$this->assertSame(expected: ['not_a_wrapper', 'not_a_wrapper'], actual: array_column($result['failed'], 'reason'));
		$this->assertSame(expected: [self::BOUND, 'foreign-flow', 'other-application-wrapper'], actual: array_column($result['flows'], 'flow'));

	}//end testOnlyThisAppsOwnWrapperFlowIsDeleted()

	/**
	 * A flow service that refuses the save is reported, and nothing is bound for it.
	 *
	 * @return void
	 */
	public function testARefusedSaveBindsNothing(): void {
		$flows = $this->createMock(FlowService::class);
		$flows->method('save')->willThrowException(new RuntimeException('trigger invalid'));
		$service = new FlowBindingWrapperService($flows, $this->objects, $this->container, $this->createMock(LoggerInterface::class));

		$result = $service->sync(self::APP, self::app([['schema' => 'vergunning', 'event' => 'object.updated']]), 'r');

		$this->assertSame(expected: [['name' => FlowBindingWrapperService::wrapperName('permits', self::BOUND, ['schema' => 'vergunning', 'event' => 'object.updated']), 'reason' => 'save_failed']], actual: $result['failed']);
		$this->assertCount(expectedCount: 1, haystack: $result['flows']);
		$this->assertSame(expected: [], actual: $this->applications);

	}//end testARefusedSaveBindsNothing()

	/**
	 * Malformed entries plan nothing, a wrapper binding is never planned from, and the name depends on every part of the entry.
	 *
	 * @return void
	 */
	public function testThePlanSkipsMalformedEntriesAndWrappers(): void {
		$plan = FlowBindingWrapperService::plan(
			'permits',
			[
				['flow' => self::BOUND, 'runOn' => [
					['schema' => '', 'event' => 'object.updated'],
					['schema' => 'vergunning', 'event' => 'object.archived'],
					['schema' => 'vergunning', 'mode' => 'sometimes'],
					'not-an-entry',
					['schema' => ' vergunning ', 'event' => 'object.created'],
				]],
				['flow' => '', 'runOn' => [['schema' => 'vergunning', 'event' => 'object.created']]],
				['flow' => 'w', 'label' => 'aut-attach-permits-x', 'sourceUuid' => self::BOUND, 'runOn' => [['schema' => 'zaak', 'event' => 'object.created']]],
			]
		);

		$this->assertSame(expected: [['flow' => self::BOUND, 'entry' => ['schema' => 'vergunning', 'event' => 'object.created']]], actual: array_values($plan));

		$names = [
			FlowBindingWrapperService::wrapperName('permits', self::BOUND, ['schema' => 'vergunning', 'event' => 'object.created']),
			FlowBindingWrapperService::wrapperName('permits', self::BOUND, ['schema' => 'vergunning', 'event' => 'object.updated']),
			FlowBindingWrapperService::wrapperName('permits', self::BOUND, ['schema' => 'zaak', 'event' => 'object.created']),
			FlowBindingWrapperService::wrapperName('permits', 'other', ['schema' => 'vergunning', 'event' => 'object.created']),
			FlowBindingWrapperService::wrapperName('subsidies', self::BOUND, ['schema' => 'vergunning', 'event' => 'object.created']),
		];
		$this->assertCount(expectedCount: 5, haystack: array_unique($names));

	}//end testThePlanSkipsMalformedEntriesAndWrappers()

	/**
	 * Only a labelled binding with a source is a wrapper.
	 *
	 * @return void
	 */
	public function testWhatCountsAsAWrapper(): void {
		$this->assertTrue(FlowBindingWrapperService::isWrapper(['flow' => 'w', 'label' => 'aut-attach-permits-1', 'sourceUuid' => 'b']));
		$this->assertFalse(FlowBindingWrapperService::isWrapper(['flow' => 'w', 'label' => 'aut-attach-permits-1']));
		$this->assertFalse(FlowBindingWrapperService::isWrapper(['flow' => 'w', 'label' => 'Archiveer', 'sourceUuid' => 'published-uuid']));
		$this->assertFalse(FlowBindingWrapperService::isWrapper('aut-attach-permits-1'));

	}//end testWhatCountsAsAWrapper()
}//end class
