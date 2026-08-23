<?php

/**
 * Buildiq ExportJobService unit tests
 *
 * Covers queue semantics (ZIP vs. GitHub targets) and the no-secret contract.
 *
 * These tests used to cover "the PAT-handling surface (ICredentialsManager wiring)
 * and the credential-key format" — careful handling of a secret Buildiq should
 * never have held. It no longer holds one: a GitHub export names a broker credential
 * by UUID and the token is injected server-side. What is security-critical now is the
 * ABSENCE of that surface, which `testPatSurfaceDoesNotExist()` pins.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\ExportJobService;
use OCA\Buildiq\Service\JobOwnerImpersonator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Local double contract for OR's `TransitionEngine`. Production code
 * (`ExportJobService::transitionJob()`) resolves the engine by string class
 * name through the PSR container and never type-hints it directly, so a
 * duck-typed double implementing just `transition()` is sufficient — no
 * dependency on OR's real `Lifecycle\TransitionEngine` class is needed.
 */
interface FakeTransitionEngineForTest {
	/**
	 * @param string $objectId Object id/uuid/slug.
	 * @param string $action Transition action name.
	 *
	 * @return mixed
	 */
	public function transition(string $objectId, string $action): mixed;
}

/**
 * Tests for {@see ExportJobService} — the no-secret contract + queue semantics.
 */
final class ExportJobServiceTest extends TestCase {
	/**
	 * Container stub (no OR service registered by default → keeps tests pure).
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Job list mock — used to verify the background job is scheduled.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList&MockObject $jobList;

	/**
	 * Service under test.
	 *
	 * @var ExportJobService
	 */
	private ExportJobService $service;

	/**
	 * Owner-impersonation collaborator mock (#105). Its OWN impersonation
	 * contract (resolve owner, swap session, always restore) is covered by
	 * {@see \OCA\Buildiq\Tests\Unit\Service\JobOwnerImpersonatorTest} —
	 * here we only need to verify ExportJobService::transitionJob() wires
	 * into it correctly (delegates the work through `runAsOwner()`).
	 *
	 * @var JobOwnerImpersonator&MockObject
	 */
	private JobOwnerImpersonator&MockObject $jobOwnerImpersonator;

	/**
	 * Build a fresh service for each test with all dependencies mocked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobOwnerImpersonator = $this->createMock(JobOwnerImpersonator::class);

		// Default: OR not available — keeps the unit isolated from the
		// ObjectService surface. Individual tests override per-call.
		$this->container->method('has')->willReturn(false);

		// NOTE: deliberately NOT pre-configuring `runAsOwner()` here.
		// PHPUnit applies EVERY matching stub configured on a mock method to
		// an invocation (not just the most specific one) — a blanket
		// passthrough default here plus a test-specific `->with(...)`
		// stub would both fire for the same call, invoking `$work` twice.
		// Each transitionJob() test that needs `runAsOwner()` to actually
		// run its callback configures that behaviour itself.
		$this->service = new ExportJobService(
			$this->container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);
	}//end setUp()

	/**
	 * queue() with target=github records a broker credential REFERENCE and the
	 * queueing user's UID — and no secret anywhere on the record.
	 *
	 * This inverts what this file used to assert. The old tests pinned that the PAT
	 * was stored under `buildiq.export.<uuid>.pat` and cleared on every terminal
	 * state — careful handling of a secret the app should never have held. It no
	 * longer holds one: `githubCredentialId` is a UUID whose token lives in the vault
	 * and is injected by the broker server-side.
	 *
	 * @return void
	 */
	public function testQueueRecordsCredentialReferenceForGithubTarget(): void {
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objectService);

		$captured = null;
		$objectService
			->method('saveObject')
			->willReturnCallback(function ($job) use (&$captured): ObjectEntity {
				$captured = $job;
				return new ObjectEntity();
			});

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$this->jobList->expects(self::once())->method('add');

		$jobUuid = $service->queue(
			applicationSlug: 'hello-world',
			payload: [
				'target' => 'github',
				'applicationVersion' => '1.0.0',
				'githubOrg' => 'acme-co',
				'githubRepo' => 'hello-world',
				'githubVisibility' => 'private',
				'githubCredentialId' => 'cred-uuid-1234',
			],
			requestedBy: 'alice'
		);

		self::assertIsArray($captured);
		self::assertSame('cred-uuid-1234', $captured['githubCredentialId']);
		self::assertSame('alice', $captured['requestedBy']);

		// Nothing token-shaped may reach the record — it lands in OR's audit trail.
		$serialised = json_encode($captured);
		self::assertDoesNotMatchRegularExpression(
			'/gh[pousr]_[A-Za-z0-9]{10,}/',
			(string)$serialised,
			'No GitHub token may ever appear on the ExportJob record'
		);

		// #104 fix: uuid4() previously discarded the last 3 of 8 hex groups
		// (vsprintf only consumes 5 of a str_split(..., 4)'s 8 elements),
		// emitting a malformed 5-group string. Lock the CANONICAL RFC 4122
		// v4 shape (8-4-4-4-12, version nibble 4, variant nibble 8/9/a/b) so
		// a future regression back to the malformed form is caught here.
		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$jobUuid,
			'Returned UUID should be a canonical RFC 4122 v4 UUID'
		);
	}//end testQueueRecordsCredentialReferenceForGithubTarget()

	/**
	 * A job that cannot be recorded must NOT be reported as queued.
	 *
	 * persistJob() used to warn-and-return on failure, so queue() carried on: it
	 * scheduled the background job and returned a UUID, and the controller answered
	 * 202 Accepted — for a record that did not exist. The background job then could not
	 * load it and died, and the user saw an export that had simply vanished. Fail loudly
	 * instead, and do not schedule anything.
	 *
	 * @return void
	 */
	public function testQueueThrowsAndSchedulesNothingWhenTheRecordCannotBePersisted(): void {
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objectService);

		$objectService
			->method('saveObject')
			->willThrowException(
				new \RuntimeException("Property 'applicationUuid' should match format 'uuid'")
			);

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		// The whole point: no phantom background job for a record that was never written.
		$this->jobList->expects(self::never())->method('add');

		$this->expectException(\RuntimeException::class);

		$service->queue(
			applicationSlug: 'hello-world',
			payload: ['target' => 'zip', 'applicationVersion' => '1.0.0'],
			requestedBy: 'alice'
		);
	}//end testQueueThrowsAndSchedulesNothingWhenTheRecordCannotBePersisted()

	/**
	 * The PAT surface is GONE, not deprecated.
	 *
	 * A `fetchPat()`/`clearPat()`/`credentialKey()` reappearing — or an
	 * `ICredentialsManager` back in the constructor — means the app has taken custody
	 * of the user's token again, which is the whole thing this change removes.
	 *
	 * @return void
	 */
	public function testPatSurfaceDoesNotExist(): void {
		$reflection = new \ReflectionClass(ExportJobService::class);

		foreach (['fetchPat', 'clearPat', 'credentialKey'] as $method) {
			self::assertFalse(
				$reflection->hasMethod($method),
				$method . '() must not exist — Buildiq holds no GitHub token'
			);
		}

		foreach ($reflection->getConstructor()->getParameters() as $parameter) {
			self::assertStringNotContainsString(
				'ICredentialsManager',
				(string)$parameter->getType(),
				'ExportJobService must not depend on ICredentialsManager — it stores no secrets'
			);
		}

		$names = array_map(
			static fn ($p) => $p->getName(),
			(new \ReflectionMethod(ExportJobService::class, 'queue'))->getParameters()
		);
		self::assertNotContains('githubPat', $names, 'queue() must not accept a PAT');
		self::assertContains('requestedBy', $names, 'queue() must carry the queueing UID for the broker');
	}//end testPatSurfaceDoesNotExist()

	/**
	 * queue() persists a normalised `dataRegisters` array — mirrors the
	 * existing `includeSeedData` (bool) cast pattern (data-registers-runtime
	 * task 4.3). Malformed entries (non-array, or missing/empty `register`)
	 * are dropped rather than rejected.
	 *
	 * @return void
	 */
	public function testQueuePersistsSanitisedDataRegisters(): void {
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objectService);

		$captured = null;
		$objectService
			->method('saveObject')
			->willReturnCallback(function ($job) use (&$captured): ObjectEntity {
				$captured = $job;
				return new ObjectEntity();
			});

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$service->queue(
			applicationSlug: 'hello-world',
			payload: [
				'target' => 'zip',
				'applicationVersion' => '1.0.0',
				'dataRegisters' => [
					['register' => 'spectr', 'includeData' => true],
					['register' => 'bag-adressen'],
					['register' => '', 'includeData' => true],
					['includeData' => true],
					'not-an-array',
				],
			],
			requestedBy: null
		);

		self::assertIsArray($captured);
		self::assertSame(
			[
				['register' => 'spectr', 'includeData' => true],
				['register' => 'bag-adressen', 'includeData' => false],
			],
			$captured['dataRegisters']
		);
	}//end testQueuePersistsSanitisedDataRegisters()

	/**
	 * queue() defaults `dataRegisters` to `[]` when the request payload
	 * omits it entirely — every existing ExportJob-submit call predating
	 * this property continues to round-trip unchanged.
	 *
	 * @return void
	 */
	public function testQueueDefaultsDataRegistersToEmptyArrayWhenOmitted(): void {
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objectService);

		$captured = null;
		$objectService
			->method('saveObject')
			->willReturnCallback(function ($job) use (&$captured): ObjectEntity {
				$captured = $job;
				return new ObjectEntity();
			});

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$service->queue(
			applicationSlug: 'hello-world',
			payload: ['target' => 'zip', 'applicationVersion' => '1.0.0'],
			requestedBy: null
		);

		self::assertSame([], $captured['dataRegisters']);
	}//end testQueueDefaultsDataRegistersToEmptyArrayWhenOmitted()

	/**
	 * uuid4() emits a canonical RFC 4122 v4 UUID for every call — regression
	 * guard for #104's malformed 5x4-char grouping bug (vsprintf silently
	 * dropping 3 of 8 hex groups). Runs several iterations since the value
	 * is random; the version/variant nibbles must ALWAYS be correct too.
	 *
	 * @return void
	 */
	public function testUuid4EmitsCanonicalRfc4122V4Uuid(): void {
		$seen = [];
		for ($i = 0; $i < 25; $i++) {
			$uuid = $this->service->uuid4();

			self::assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				$uuid,
				'uuid4() must emit a canonical 8-4-4-4-12 RFC 4122 v4 UUID'
			);
			self::assertSame(36, strlen($uuid), 'canonical UUID string is always 36 characters');
			self::assertArrayNotHasKey($uuid, $seen, 'uuid4() must not repeat within a small sample');
			$seen[$uuid] = true;
		}
	}//end testUuid4EmitsCanonicalRfc4122V4Uuid()

	/**
	 * persistJob() MUST pass explicit register/schema/uuid to
	 * ObjectService::saveObject() (#104). Omitting them let saveObject()
	 * fall back to whatever register/schema an EARLIER call in the same
	 * request left as ambient state (e.g. ExportsController's
	 * searchObjectsBySlug('openbuild', 'application', ...) re-anchors it to
	 * schema=application) and let OR auto-generate its own identity instead
	 * of the job's own UUID — so a later loadJob($jobUuid) could never find
	 * the record it just "persisted".
	 *
	 * @return void
	 */
	public function testPersistJobPassesExplicitRegisterSchemaAndUuidToSaveObject(): void {
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objectService);

		$capturedArgs = null;
		$objectService
			->expects(self::once())
			->method('saveObject')
			->willReturnCallback(function ($job, $extend = [], $register = null, $schema = null, $uuid = null) use (&$capturedArgs): ObjectEntity {
				$capturedArgs = ['job' => $job, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			});

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$service->persistJob([
			'uuid' => 'job-uuid-123',
			'applicationSlug' => 'hello-world',
			'applicationUuid' => 'app-uuid-1',
			'applicationVersion' => '1.0.0',
			'target' => 'zip',
			'status' => 'queued',
		]);

		self::assertSame('openbuild', $capturedArgs['register'], 'persistJob() must target the buildiq register');
		self::assertSame('export-job', $capturedArgs['schema'], 'persistJob() must target the export-job schema SLUG (not the exportJob JSON key)');
		self::assertSame('job-uuid-123', $capturedArgs['uuid'], 'persistJob() must persist under the job\'s OWN uuid, not an OR-auto-generated identity');
	}//end testPersistJobPassesExplicitRegisterSchemaAndUuidToSaveObject()

	/**
	 * mergeJobFields() MUST likewise pass explicit register/schema/uuid to
	 * saveObject() when re-saving the merged record (#104) — otherwise the
	 * side-field merge (errorMessage, downloadUrl, …) would target the wrong
	 * schema / a fresh OR-generated identity instead of updating the SAME
	 * existing ExportJob row `find()` just resolved.
	 *
	 * @return void
	 */
	public function testMergeJobFieldsPassesExplicitRegisterSchemaAndUuidToSaveObject(): void {
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($objectService);

		$existing = new ObjectEntity();
		$existing->setUuid('job-uuid-456');
		$existing->setRegister('openbuild');
		$existing->setSchema('export-job');
		$existing->setObject([
			'applicationUuid' => 'app-uuid-1',
			'applicationVersion' => '1.0.0',
			'target' => 'zip',
			'status' => 'running',
		]);

		$objectService->method('find')->willReturn($existing);

		$capturedArgs = null;
		$objectService
			->expects(self::once())
			->method('saveObject')
			->willReturnCallback(function ($job, $extend = [], $register = null, $schema = null, $uuid = null) use (&$capturedArgs): ObjectEntity {
				$capturedArgs = ['job' => $job, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			});

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);
		$service->mergeJobFields('job-uuid-456', ['downloadUrl' => '/index.php/apps/buildiq/api/exports/job-uuid-456/download']);

		self::assertSame('openbuild', $capturedArgs['register'], 'mergeJobFields() must target the buildiq register');
		self::assertSame('export-job', $capturedArgs['schema'], 'mergeJobFields() must target the export-job schema SLUG');
		self::assertSame('job-uuid-456', $capturedArgs['uuid'], 'mergeJobFields() must update the SAME existing record by uuid');
		self::assertSame('/index.php/apps/buildiq/api/exports/job-uuid-456/download', $capturedArgs['job']['downloadUrl']);
	}//end testMergeJobFieldsPassesExplicitRegisterSchemaAndUuidToSaveObject()

	/**
	 * Build a container mock that resolves ONLY the TransitionEngine
	 * class-string. Owner impersonation is delegated to the (mocked)
	 * {@see JobOwnerImpersonator} collaborator — its own ObjectService/
	 * IUserSession/IUserManager wiring is covered by
	 * {@see \OCA\Buildiq\Tests\Unit\Service\JobOwnerImpersonatorTest} —
	 * so transitionJob()'s own tests only need the engine resolvable.
	 *
	 * @param FakeTransitionEngineForTest&MockObject $engine Engine double.
	 *
	 * @return ContainerInterface&MockObject
	 */
	private function containerResolvingEngine(FakeTransitionEngineForTest&MockObject $engine): ContainerInterface&MockObject {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			fn (string $id): bool => $id === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine'
		);
		$container->method('get')->willReturn($engine);

		return $container;
	}//end containerResolvingEngine()

	/**
	 * transitionJob() MUST run the TransitionEngine call through
	 * {@see JobOwnerImpersonator::runAsOwner()} — passing the ExportJob's
	 * UUID as the object whose owner should be impersonated — rather than
	 * calling the engine directly. Background jobs run with no HTTP
	 * session, and OR's TransitionEngine + PermissionHandler fail-closed
	 * for an anonymous caller against the export-job schema's admin-only
	 * `authorization.update` (#105); the impersonation mechanics
	 * themselves (resolve owner, swap session, always restore) are
	 * JobOwnerImpersonator's own contract, tested in isolation.
	 *
	 * @return void
	 */
	public function testTransitionJobDelegatesThroughJobOwnerImpersonator(): void {
		$engine = $this->createMock(FakeTransitionEngineForTest::class);
		$engine->expects(self::once())->method('transition')->with('job-uuid-owner', 'start');

		$this->jobOwnerImpersonator
			->expects(self::once())
			->method('runAsOwner')
			->with('job-uuid-owner', self::isType('callable'))
			->willReturnCallback(fn (string $objectId, callable $work) => $work());

		$service = new ExportJobService(
			$this->containerResolvingEngine($engine),
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$result = $service->transitionJob(jobUuid: 'job-uuid-owner', action: 'start');

		self::assertTrue($result, 'transitionJob() must return true when the impersonated work succeeds');
	}//end testTransitionJobDelegatesThroughJobOwnerImpersonator()

	/**
	 * The extraFields merge (errorMessage, downloadUrl, …) MUST happen
	 * INSIDE the impersonated work — not after `runAsOwner()` returns —
	 * so the merge write is authorised under the same impersonated
	 * identity as the transition itself.
	 *
	 * @return void
	 */
	public function testTransitionJobMergesExtraFieldsInsideTheImpersonatedWork(): void {
		$engine = $this->createMock(FakeTransitionEngineForTest::class);
		$engine->method('transition');

		$existing = new ObjectEntity();
		$existing->setUuid('job-uuid-extra');
		$existing->setRegister('openbuild');
		$existing->setSchema('export-job');
		$existing->setObject(['status' => 'running']);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($existing);

		$captured = null;
		$objectService
			->expects(self::once())
			->method('saveObject')
			->willReturnCallback(function ($job) use (&$captured): ObjectEntity {
				$captured = $job;
				return new ObjectEntity();
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($engine, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine') {
					return $engine;
				}

				return $objectService;
			}
		);

		$this->jobOwnerImpersonator
			->method('runAsOwner')
			->willReturnCallback(fn (string $objectId, callable $work) => $work());

		$service = new ExportJobService(
			$container,
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$result = $service->transitionJob(
			jobUuid: 'job-uuid-extra',
			action: 'succeed',
			extraFields: ['downloadUrl' => '/index.php/apps/buildiq/api/exports/job-uuid-extra/download']
		);

		self::assertTrue($result);
		self::assertSame(
			'/index.php/apps/buildiq/api/exports/job-uuid-extra/download',
			$captured['downloadUrl']
		);
	}//end testTransitionJobMergesExtraFieldsInsideTheImpersonatedWork()

	/**
	 * When the impersonated work throws (e.g. the TransitionEngine itself
	 * rejects the transition), transitionJob() must catch it, log, and
	 * return false rather than let the exception escape — RunExportJob has
	 * no recovery path for an exception escaping transitionJob().
	 *
	 * @return void
	 */
	public function testTransitionJobReturnsFalseWhenTheImpersonatedWorkThrows(): void {
		$engine = $this->createMock(FakeTransitionEngineForTest::class);
		$engine->method('transition')->willThrowException(new \RuntimeException('OR rejected the transition'));

		$this->jobOwnerImpersonator
			->method('runAsOwner')
			->willReturnCallback(fn (string $objectId, callable $work) => $work());

		$service = new ExportJobService(
			$this->containerResolvingEngine($engine),
			$this->jobList,
			new NullLogger(),
			$this->jobOwnerImpersonator
		);

		$result = $service->transitionJob(jobUuid: 'job-uuid-throws', action: 'start');

		self::assertFalse($result, 'transitionJob() must return false when the impersonated work throws');
	}//end testTransitionJobReturnsFalseWhenTheImpersonatedWorkThrows()
}//end class
