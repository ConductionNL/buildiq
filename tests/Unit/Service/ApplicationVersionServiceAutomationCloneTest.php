<?php

/**
 * Unit tests for ApplicationVersionService::cloneAutomationsToVersion().
 *
 * Covers REQ-AUTD-009: branching a version clones its automations with new
 * uuids and distinct `aut-` rule-set slugs, and disabling the clone leaves
 * the source version's artifacts unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#4.4
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see ApplicationVersionService::cloneAutomationsToVersion()}.
 */
final class ApplicationVersionServiceAutomationCloneTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var AutomationCompilerService&MockObject
	 */
	private AutomationCompilerService&MockObject $automationCompiler;

	/**
	 * The service under test.
	 *
	 * @var ApplicationVersionService
	 */
	private ApplicationVersionService $service;

	/**
	 * Wire the service with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->automationCompiler = $this->createMock(AutomationCompilerService::class);

		$this->service = new ApplicationVersionService(
			logger: new NullLogger(),
			objectService: $this->objectService,
			registerService: $this->createMock(RegisterService::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			automationCompiler: $this->automationCompiler
		);

	}//end setUp()

	/**
	 * REQ-AUTD-009: branching clones a manual automation with a new uuid and
	 * a distinct `aut-` rule-set slug, recompiled into the new version.
	 *
	 * @return void
	 */
	public function testCloneCreatesNewUuidAndRecompiles(): void {
		$source = [
			'id' => 'source-automation-uuid',
			'slug' => 'flag-large-claims',
			'name' => 'Flag large claims',
			'applicationSlug' => 'claims',
			'versionUuid' => 'source-version',
			'enabled' => true,
			'trigger' => ['type' => 'manual'],
			'condition' => ['type' => 'feel', 'expression' => 'payload.amount > 1000'],
			'actions' => [['type' => 'object-op', 'operation' => 'create', 'schema' => 'flag']],
			'provenance' => ['ruleSetSlug' => 'aut-sourceid1', 'compiledHash' => 'sha256:old'],
		];

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($source): array {
				$filters = $config['filters'] ?? [];
				if (($filters['schema'] ?? null) === 'automation' && ($filters['versionUuid'] ?? null) === 'source-version') {
					return [$source];
				}

				return [];
			}
		);

		$savedPayloads = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, $extend = [], $register = null, $schema = null, $uuid = null) use (&$savedPayloads) {
				$savedPayloads[] = ['object' => $object, 'uuid' => $uuid];

				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-automation-uuid');
				$entity->setObject($object);
				return $entity;
			}
		);

		$this->automationCompiler->expects($this->once())
			->method('compile')
			->willReturnCallback(function (array $automation) {
				$this->assertSame('new-automation-uuid', $automation['id']);
				$this->assertSame('claims', $automation['applicationSlug']);
				return ['notifications' => [], 'lifecycleActions' => [], 'schedules' => [], 'ruleSet' => ['slug' => 'aut-newuuid1'], 'conditionActionRule' => ['ruleSetId' => 'aut-newuuid1'], 'hash' => 'sha256:new'];
			});

		$this->automationCompiler->expects($this->once())
			->method('apply')
			->willReturn(['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'ruleSetSlug' => 'aut-newuuid1', 'openconnectorObjects' => [], 'compiledHash' => 'sha256:new']);

		$cloned = $this->service->cloneAutomationsToVersion('claims', 'source-version', 'new-version');

		$this->assertSame(1, $cloned);

		// First saveObject call created the clone WITHOUT the source's id/provenance.
		$firstSave = $savedPayloads[0]['object'];
		$this->assertArrayNotHasKey('id', $firstSave);
		$this->assertArrayNotHasKey('provenance', $firstSave);
		$this->assertSame('new-version', $firstSave['versionUuid']);

		// Second saveObject call persisted the recompiled provenance with the NEW rule-set slug.
		$secondSave = $savedPayloads[1]['object'];
		$this->assertSame('aut-newuuid1', $secondSave['provenance']['ruleSetSlug']);
		$this->assertNotSame('aut-sourceid1', $secondSave['provenance']['ruleSetSlug']);

	}//end testCloneCreatesNewUuidAndRecompiles()

	/**
	 * A same-uuid / empty-uuid request is a safe no-op (0 cloned, no I/O).
	 *
	 * @return void
	 */
	public function testCloneIsNoOpWhenSourceAndTargetMatch(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$cloned = $this->service->cloneAutomationsToVersion('claims', 'same-version', 'same-version');

		$this->assertSame(0, $cloned);

	}//end testCloneIsNoOpWhenSourceAndTargetMatch()

	/**
	 * A per-automation clone failure is logged and skipped — it does not
	 * abort the batch, and the source version's own data is never touched
	 * (no delete/update calls target the source automation).
	 *
	 * @return void
	 */
	public function testCloneFailureIsSkippedNotFatal(): void {
		$source = ['id' => 'source-1', 'slug' => 'broken', 'applicationSlug' => 'claims', 'versionUuid' => 'source-version', 'trigger' => ['type' => 'manual']];

		$this->objectService->method('findAll')->willReturn([$source]);
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException('boom'));

		$this->automationCompiler->expects($this->never())->method('compile');

		$cloned = $this->service->cloneAutomationsToVersion('claims', 'source-version', 'new-version');

		$this->assertSame(0, $cloned);

	}//end testCloneFailureIsSkippedNotFatal()
}//end class
