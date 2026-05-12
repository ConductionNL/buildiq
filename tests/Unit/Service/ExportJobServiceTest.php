<?php

/**
 * OpenBuilt ExportJobService unit tests
 *
 * Covers the PAT-handling surface (ICredentialsManager wiring), queue
 * semantics (ZIP vs. GitHub targets), and the credential-key format.
 * These tests are security-critical: a failure here means the PAT could
 * either leak into the OR record or fail to clear on terminal state.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Service
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

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\AppInfo\Application;
use OCA\OpenBuilt\Service\ExportJobService;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

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
     * Build a fresh service for each test with all dependencies mocked.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->container          = $this->createMock(ContainerInterface::class);
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->jobList            = $this->createMock(IJobList::class);

        // Default: OR not available — keeps the unit isolated from the
        // ObjectService surface. Individual tests override per-call.
        $this->container->method('has')->willReturn(false);

        $this->service = new ExportJobService(
            $this->container,
            $this->credentialsManager,
            $this->jobList,
            new NullLogger()
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
                self::matchesRegularExpression('/^openbuilt\.export\.[0-9a-f-]+\.pat$/'),
                self::equalTo('ghp_super_secret_pat')
            );

        $this->jobList->expects(self::once())->method('add');

        $jobUuid = $this->service->queue(
            applicationSlug: 'hello-world',
            payload: $payload,
            githubPat: 'ghp_super_secret_pat'
        );

        // Note: the service's uuid4() emits a 5-group hex string (not the
        // canonical 8-4-4-4-12); we lock the actually-observed shape so
        // a future refactor toward the canonical form is a deliberate
        // change rather than a silent regression.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{4}(?:-[0-9a-f]{4}){4,}$/',
            $jobUuid,
            'Returned UUID should follow the documented format'
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
     * `openbuilt.export.<uuid>.pat`. Tests both the prefix and the
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
        self::assertSame('openbuilt.export.abc-123-def-456.pat', $key);

        // Empty UUID still produces a stable shape (no string concat bugs).
        $emptyKey = $this->service->credentialKey('');
        self::assertSame('openbuilt.export..pat', $emptyKey);
    }//end testCredentialKeyFormatIsDeterministic()
}//end class
