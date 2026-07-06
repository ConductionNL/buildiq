<?php

/**
 * OpenBuild ExportJobService unit tests
 *
 * Covers the PAT-handling surface (ICredentialsManager wiring), queue
 * semantics (ZIP vs. GitHub targets), and the credential-key format.
 * These tests are security-critical: a failure here means the PAT could
 * either leak into the OR record or fail to clear on terminal state.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
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

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\ExportJobService;
use OCA\OpenBuild\Service\JobOwnerImpersonator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICredentialsManager;
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
interface FakeTransitionEngineForTest
{
    /**
     * @param string $objectId Object id/uuid/slug.
     * @param string $action   Transition action name.
     *
     * @return mixed
     */
    public function transition(string $objectId, string $action): mixed;
}

/**
 * Tests for {@see ExportJobService} — PAT handling + queue semantics.
 */
final class ExportJobServiceTest extends TestCase
{
    /**
     * Container stub (no OR service registered by default → keeps tests pure).
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Credentials manager mock.
     *
     * @var ICredentialsManager&MockObject
     */
    private ICredentialsManager&MockObject $credentialsManager;

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
     * {@see \OCA\OpenBuild\Tests\Unit\Service\JobOwnerImpersonatorTest} —
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
    protected function setUp(): void
    {
        parent::setUp();
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->credentialsManager  = $this->createMock(ICredentialsManager::class);
        $this->jobList             = $this->createMock(IJobList::class);
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
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );
    }//end setUp()

    /**
     * queue() with target=github + PAT stores the credential under the
     * deterministic key and never persists the PAT in the in-memory job.
     *
     * Security-critical: a regression here would either leak the PAT into
     * the OR audit trail or fail to associate it with the job UUID.
     *
     * @return void
     */
    public function testQueueStoresPatOnlyForGithubTarget(): void
    {
        $payload = [
            'target'             => 'github',
            'applicationVersion' => '1.0.0',
            'githubOrg'          => 'acme-co',
            'githubRepo'         => 'hello-world',
            'githubVisibility'   => 'private',
        ];

        // Assert the credentials manager is called exactly once with the
        // expected APP_ID + key suffix + PAT.
        $this->credentialsManager
            ->expects(self::once())
            ->method('store')
            ->with(
                self::equalTo(Application::APP_ID),
                self::matchesRegularExpression('/^openbuild\.export\.[0-9a-f-]+\.pat$/'),
                self::equalTo('ghp_super_secret_pat')
            );

        $this->jobList->expects(self::once())->method('add');

        $jobUuid = $this->service->queue(
            applicationSlug: 'hello-world',
            payload: $payload,
            githubPat: 'ghp_super_secret_pat'
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
        self::assertNotEmpty($jobUuid, 'queue() must return a non-empty UUID');
    }//end testQueueStoresPatOnlyForGithubTarget()

    /**
     * queue() with target=zip MUST NOT call ICredentialsManager::store —
     * ZIP-only jobs never see a PAT, and storing one would be a leak.
     *
     * @return void
     */
    public function testQueueDoesNotStorePatForZipTarget(): void
    {
        $payload = [
            'target'             => 'zip',
            'applicationVersion' => '1.0.0',
        ];

        $this->credentialsManager
            ->expects(self::never())
            ->method('store');

        $this->jobList->expects(self::once())->method('add');

        $this->service->queue(
            applicationSlug: 'hello-world',
            payload: $payload,
            githubPat: null
        );
    }//end testQueueDoesNotStorePatForZipTarget()

    /**
     * fetchPat() returns null when no credential is stored for the job —
     * the canonical state for ZIP-only jobs.
     *
     * @return void
     */
    public function testFetchPatReturnsNullForZipOnlyJob(): void
    {
        $this->credentialsManager
            ->expects(self::once())
            ->method('retrieve')
            ->willReturn(null);

        $result = $this->service->fetchPat('some-job-uuid');
        self::assertNull($result, 'fetchPat() must return null when no credential is stored');
    }//end testFetchPatReturnsNullForZipOnlyJob()

    /**
     * clearPat() is idempotent — calling it twice (e.g. once on success
     * in the finally block and again during a manual cleanup) must not
     * throw. Even when the credentials manager throws, the service must
     * swallow the error rather than block a terminal transition.
     *
     * Security-critical: a failure to clear the PAT on terminal state
     * would leave it lingering in the credentials store indefinitely.
     *
     * @return void
     */
    public function testClearPatIsIdempotent(): void
    {
        // First call succeeds; second call simulates an underlying
        // "credential not found" — both must complete without throwing.
        $this->credentialsManager
            ->expects(self::exactly(2))
            ->method('delete')
            ->willReturnOnConsecutiveCalls(
                null,
                self::throwException(new \RuntimeException('Not found'))
            );

        $this->service->clearPat('some-job-uuid');
        $this->service->clearPat('some-job-uuid');

        // Reaching this line proves no exception escaped.
        self::assertTrue(true);
    }//end testClearPatIsIdempotent()

    /**
     * credentialKey() yields the documented deterministic format —
     * `openbuild.export.<uuid>.pat`. Tests both the prefix and the
     * suffix so a regression in either is caught.
     *
     * The format is a security boundary: a change here would orphan
     * existing stored credentials and could lead to PAT reuse across
     * jobs.
     *
     * @return void
     */
    public function testCredentialKeyFormatIsDeterministic(): void
    {
        $key = $this->service->credentialKey('abc-123-def-456');
        self::assertSame('openbuild.export.abc-123-def-456.pat', $key);

        // Empty UUID still produces a stable shape (no string concat bugs).
        $emptyKey = $this->service->credentialKey('');
        self::assertSame('openbuild.export..pat', $emptyKey);
    }//end testCredentialKeyFormatIsDeterministic()

    /**
     * queue() persists a normalised `dataRegisters` array — mirrors the
     * existing `includeSeedData` (bool) cast pattern (data-registers-runtime
     * task 4.3). Malformed entries (non-array, or missing/empty `register`)
     * are dropped rather than rejected.
     *
     * @return void
     */
    public function testQueuePersistsSanitisedDataRegisters(): void
    {
        $container     = $this->createMock(ContainerInterface::class);
        $objectService = $this->createMock(ObjectService::class);
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
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );

        $service->queue(
            applicationSlug: 'hello-world',
            payload: [
                'target'             => 'zip',
                'applicationVersion' => '1.0.0',
                'dataRegisters'      => [
                    ['register' => 'spectr', 'includeData' => true],
                    ['register' => 'bag-adressen'],
                    ['register' => '', 'includeData' => true],
                    ['includeData' => true],
                    'not-an-array',
                ],
            ],
            githubPat: null
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
    public function testQueueDefaultsDataRegistersToEmptyArrayWhenOmitted(): void
    {
        $container     = $this->createMock(ContainerInterface::class);
        $objectService = $this->createMock(ObjectService::class);
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
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );

        $service->queue(
            applicationSlug: 'hello-world',
            payload: ['target' => 'zip', 'applicationVersion' => '1.0.0'],
            githubPat: null
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
    public function testUuid4EmitsCanonicalRfc4122V4Uuid(): void
    {
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
    public function testPersistJobPassesExplicitRegisterSchemaAndUuidToSaveObject(): void
    {
        $container     = $this->createMock(ContainerInterface::class);
        $objectService = $this->createMock(ObjectService::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($objectService);

        $capturedArgs = null;
        $objectService
            ->expects(self::once())
            ->method('saveObject')
            ->willReturnCallback(function ($job, $extend=[], $register=null, $schema=null, $uuid=null) use (&$capturedArgs): ObjectEntity {
                $capturedArgs = ['job' => $job, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
                return new ObjectEntity();
            });

        $service = new ExportJobService(
            $container,
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );

        $service->persistJob([
            'uuid'               => 'job-uuid-123',
            'applicationSlug'    => 'hello-world',
            'applicationUuid'    => 'app-uuid-1',
            'applicationVersion' => '1.0.0',
            'target'             => 'zip',
            'status'             => 'queued',
        ]);

        self::assertSame('openbuild', $capturedArgs['register'], 'persistJob() must target the openbuild register');
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
    public function testMergeJobFieldsPassesExplicitRegisterSchemaAndUuidToSaveObject(): void
    {
        $container     = $this->createMock(ContainerInterface::class);
        $objectService = $this->createMock(ObjectService::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn($objectService);

        $existing = new ObjectEntity();
        $existing->setUuid('job-uuid-456');
        $existing->setRegister('openbuild');
        $existing->setSchema('export-job');
        $existing->setObject([
            'applicationUuid'    => 'app-uuid-1',
            'applicationVersion' => '1.0.0',
            'target'             => 'zip',
            'status'             => 'running',
        ]);

        $objectService->method('find')->willReturn($existing);

        $capturedArgs = null;
        $objectService
            ->expects(self::once())
            ->method('saveObject')
            ->willReturnCallback(function ($job, $extend=[], $register=null, $schema=null, $uuid=null) use (&$capturedArgs): ObjectEntity {
                $capturedArgs = ['job' => $job, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
                return new ObjectEntity();
            });

        $service = new ExportJobService(
            $container,
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );
        $service->mergeJobFields('job-uuid-456', ['downloadUrl' => '/index.php/apps/openbuild/api/exports/job-uuid-456/download']);

        self::assertSame('openbuild', $capturedArgs['register'], 'mergeJobFields() must target the openbuild register');
        self::assertSame('export-job', $capturedArgs['schema'], 'mergeJobFields() must target the export-job schema SLUG');
        self::assertSame('job-uuid-456', $capturedArgs['uuid'], 'mergeJobFields() must update the SAME existing record by uuid');
        self::assertSame('/index.php/apps/openbuild/api/exports/job-uuid-456/download', $capturedArgs['job']['downloadUrl']);
    }//end testMergeJobFieldsPassesExplicitRegisterSchemaAndUuidToSaveObject()

    /**
     * Build a container mock that resolves ONLY the TransitionEngine
     * class-string. Owner impersonation is delegated to the (mocked)
     * {@see JobOwnerImpersonator} collaborator — its own ObjectService/
     * IUserSession/IUserManager wiring is covered by
     * {@see \OCA\OpenBuild\Tests\Unit\Service\JobOwnerImpersonatorTest} —
     * so transitionJob()'s own tests only need the engine resolvable.
     *
     * @param FakeTransitionEngineForTest&MockObject $engine Engine double.
     *
     * @return ContainerInterface&MockObject
     */
    private function containerResolvingEngine(FakeTransitionEngineForTest&MockObject $engine): ContainerInterface&MockObject
    {
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
    public function testTransitionJobDelegatesThroughJobOwnerImpersonator(): void
    {
        $engine = $this->createMock(FakeTransitionEngineForTest::class);
        $engine->expects(self::once())->method('transition')->with('job-uuid-owner', 'start');

        $this->jobOwnerImpersonator
            ->expects(self::once())
            ->method('runAsOwner')
            ->with('job-uuid-owner', self::isType('callable'))
            ->willReturnCallback(fn (string $objectId, callable $work) => $work());

        $service = new ExportJobService(
            $this->containerResolvingEngine($engine),
            $this->credentialsManager,
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
    public function testTransitionJobMergesExtraFieldsInsideTheImpersonatedWork(): void
    {
        $engine = $this->createMock(FakeTransitionEngineForTest::class);
        $engine->method('transition');

        $existing = new ObjectEntity();
        $existing->setUuid('job-uuid-extra');
        $existing->setRegister('openbuild');
        $existing->setSchema('export-job');
        $existing->setObject(['status' => 'running']);

        $objectService = $this->createMock(ObjectService::class);
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
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );

        $result = $service->transitionJob(
            jobUuid: 'job-uuid-extra',
            action: 'succeed',
            extraFields: ['downloadUrl' => '/index.php/apps/openbuild/api/exports/job-uuid-extra/download']
        );

        self::assertTrue($result);
        self::assertSame(
            '/index.php/apps/openbuild/api/exports/job-uuid-extra/download',
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
    public function testTransitionJobReturnsFalseWhenTheImpersonatedWorkThrows(): void
    {
        $engine = $this->createMock(FakeTransitionEngineForTest::class);
        $engine->method('transition')->willThrowException(new \RuntimeException('OR rejected the transition'));

        $this->jobOwnerImpersonator
            ->method('runAsOwner')
            ->willReturnCallback(fn (string $objectId, callable $work) => $work());

        $service = new ExportJobService(
            $this->containerResolvingEngine($engine),
            $this->credentialsManager,
            $this->jobList,
            new NullLogger(),
            $this->jobOwnerImpersonator
        );

        $result = $service->transitionJob(jobUuid: 'job-uuid-throws', action: 'start');

        self::assertFalse($result, 'transitionJob() must return false when the impersonated work throws');
    }//end testTransitionJobReturnsFalseWhenTheImpersonatedWorkThrows()
}//end class
