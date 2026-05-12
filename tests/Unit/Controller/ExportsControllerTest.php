<?php

/**
 * OpenBuilt ExportsController unit tests
 *
 * Covers the HTTP surface — submit() validation, RBAC fallback,
 * 202 queue semantics, GitHub-field validation, and download() expiry +
 * authorization. These tests sit on top of a mocked ExportJobService so
 * the controller is exercised in isolation.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Controller
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

namespace OCA\OpenBuilt\Tests\Unit\Controller;

use OCA\OpenBuilt\Controller\ExportsController;
use OCA\OpenBuilt\Service\ExportJobService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see ExportsController} — HTTP surface + RBAC + lifecycle.
 */
final class ExportsControllerTest extends TestCase
{
    /**
     * IRequest mock — getParams() is the only relevant method.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * ExportJobService mock — queue() / resolveDownload() are stubbed.
     *
     * @var ExportJobService&MockObject
     */
    private ExportJobService&MockObject $exportJobService;

    /**
     * Session mock — drives the RBAC user lookup.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Container mock — drives the fallback authorization path.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Build the dependency mocks shared across every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request          = $this->createMock(IRequest::class);
        $this->exportJobService = $this->createMock(ExportJobService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->container        = $this->createMock(ContainerInterface::class);
    }//end setUp()

    /**
     * Build a controller with the shared mocks, optionally adjusting the
     * authenticated user for the test.
     *
     * @param bool $authenticated Whether session returns a user.
     *
     * @return ExportsController
     */
    private function buildController(bool $authenticated=true): ExportsController
    {
        if ($authenticated === true) {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn('alice');
            $this->userSession->method('getUser')->willReturn($user);
        } else {
            $this->userSession->method('getUser')->willReturn(null);
        }

        return new ExportsController(
            $this->request,
            $this->exportJobService,
            $this->userSession,
            $this->container,
            new NullLogger()
        );
    }//end buildController()

    /**
     * Stub the container so the fallback authorization path returns
     * "authorised" — i.e. ObjectService::find() yields a non-null record.
     *
     * @return void
     */
    private function stubAuthorisedFallback(): void
    {
        $objectService = new class () {
            public function find(string $id)
            {
                return ['uuid' => $id];
            }
        };

        $this->container->method('has')->willReturnCallback(
            static function (string $class): bool {
                return $class === 'OCA\\OpenRegister\\Service\\ObjectService';
            }
        );
        $this->container->method('get')->willReturn($objectService);
    }//end stubAuthorisedFallback()

    /**
     * Test 1: submit() with an invalid `target` returns 422
     * (UNPROCESSABLE_ENTITY) — the body-validation guard short-circuits
     * before the ExportJob is queued.
     *
     * @return void
     */
    public function testSubmitReturns422ForInvalidTarget(): void
    {
        $this->stubAuthorisedFallback();
        $this->request->method('getParams')->willReturn([
            'target'             => 'ftp',
            'applicationVersion' => '1.0.0',
        ]);

        $this->exportJobService->expects(self::never())->method('queue');

        $response = $this->buildController()->submit('hello-world');
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testSubmitReturns422ForInvalidTarget()

    /**
     * Test 2: submit() requires per-object Application access — when the
     * RBAC fallback denies (user not authenticated → IUserSession::getUser
     * returns null), the controller returns 403 Forbidden and the
     * ExportJob is NOT queued.
     *
     * This pins the ADR-005 Rule 3 IDOR guard.
     *
     * @return void
     */
    public function testSubmitReturns403WhenRbacDenies(): void
    {
        $this->container->method('has')->willReturn(false);
        $this->request->method('getParams')->willReturn([
            'target'             => 'zip',
            'applicationVersion' => '1.0.0',
        ]);

        $this->exportJobService->expects(self::never())->method('queue');

        // Unauthenticated → user null → RBAC denies.
        $response = $this->buildController(authenticated: false)->submit('hello-world');
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testSubmitReturns403WhenRbacDenies()

    /**
     * Test 3: submit() happy path — queues the ExportJob via
     * ExportJobService::queue() and returns 202 Accepted with the UUID.
     *
     * @return void
     */
    public function testSubmitQueuesJobAndReturns202(): void
    {
        $this->stubAuthorisedFallback();
        $this->request->method('getParams')->willReturn([
            'target'             => 'zip',
            'applicationVersion' => '1.0.0',
        ]);

        $this->exportJobService
            ->expects(self::once())
            ->method('queue')
            ->willReturn('new-job-uuid-123');

        $response = $this->buildController()->submit('hello-world');
        self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $data = $response->getData();
        self::assertSame('new-job-uuid-123', $data['uuid']);
    }//end testSubmitQueuesJobAndReturns202()

    /**
     * Test 4: submit() with target=github validates that both
     * `githubOrg` and `githubRepo` are present — otherwise 422.
     *
     * @return void
     */
    public function testSubmitValidatesGithubOrgAndRepo(): void
    {
        $this->stubAuthorisedFallback();
        $this->request->method('getParams')->willReturn([
            'target'             => 'github',
            'applicationVersion' => '1.0.0',
            // Missing githubOrg + githubRepo.
        ]);

        $this->exportJobService->expects(self::never())->method('queue');

        $response = $this->buildController()->submit('hello-world');
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

        $data = $response->getData();
        self::assertStringContainsString('github', strtolower((string) ($data['error'] ?? '')));
    }//end testSubmitValidatesGithubOrgAndRepo()

    /**
     * Test 5: download() returns 410 Gone when the ExportJob has expired.
     * The controller honours the `expired` flag from
     * ExportJobService::resolveDownload().
     *
     * @return void
     */
    public function testDownloadReturns410ForExpiredJob(): void
    {
        $this->stubAuthorisedFallback();

        $this->exportJobService
            ->method('resolveDownload')
            ->willReturn(['path' => '/tmp/some.zip', 'expired' => true]);

        $response = $this->buildController()->download('expired-uuid');
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_GONE, $response->getStatus());
    }//end testDownloadReturns410ForExpiredJob()

    /**
     * Test 6: download() returns 404 for unauthorized callers — masked as
     * "Unknown export job" to avoid revealing the UUID space (defence in
     * depth on the IDOR vector documented in the controller).
     *
     * @return void
     */
    public function testDownloadReturns404ForUnauthorizedCaller(): void
    {
        // Container has NO ObjectService → the authz fallback returns false.
        $this->container->method('has')->willReturn(false);

        $this->exportJobService->expects(self::never())->method('resolveDownload');

        $response = $this->buildController()->download('some-uuid');
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testDownloadReturns404ForUnauthorizedCaller()

    /**
     * Test 7: download() returns the ZIP for the owner — content-type
     * `application/zip` and a DataDownloadResponse with the file body.
     *
     * @return void
     */
    public function testDownloadReturnsZipForOwner(): void
    {
        $this->stubAuthorisedFallback();

        $tmpZip = sys_get_temp_dir().'/openbuilt-controller-test-'.uniqid().'.zip';
        file_put_contents($tmpZip, 'PK fake zip bytes');

        try {
            $this->exportJobService
                ->method('resolveDownload')
                ->willReturn(['path' => $tmpZip, 'expired' => false]);

            $response = $this->buildController()->download('owned-uuid');
            self::assertInstanceOf(DataDownloadResponse::class, $response);
            self::assertSame(Http::STATUS_OK, $response->getStatus());
        } finally {
            @unlink($tmpZip);
        }
    }//end testDownloadReturnsZipForOwner()

    /**
     * Test 8: download() preserves the original filename via
     * Content-Disposition (DataDownloadResponse derives it from the
     * second constructor arg; we assert the basename of the resolved
     * path appears in the headers).
     *
     * @return void
     */
    public function testDownloadPreservesContentDispositionFilename(): void
    {
        $this->stubAuthorisedFallback();

        $tmpZip = sys_get_temp_dir().'/openbuilt-filename-test.zip';
        file_put_contents($tmpZip, 'PK');

        try {
            $this->exportJobService
                ->method('resolveDownload')
                ->willReturn(['path' => $tmpZip, 'expired' => false]);

            $response = $this->buildController()->download('owned-uuid');
            self::assertInstanceOf(DataDownloadResponse::class, $response);

            // Read $headers directly via Reflection — getHeaders() requires
            // the full OC::$server stack which isn't booted in unit tests.
            $headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
            $headersProp->setAccessible(true);
            $headers     = $headersProp->getValue($response);
            $disposition = $headers['Content-Disposition'] ?? '';
            self::assertStringContainsString(
                'openbuilt-filename-test.zip',
                (string) $disposition,
                'Content-Disposition must include the original filename'
            );
        } finally {
            @unlink($tmpZip);
        }
    }//end testDownloadPreservesContentDispositionFilename()
}//end class
