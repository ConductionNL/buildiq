<?php

/**
 * Unit tests for ApplicationCreationController.
 *
 * Covers spec `openbuilt-app-creation-wizard` REQ-OBWIZ-001, REQ-OBWIZ-007:
 *   - 201 on success with applicationUuid in body
 *   - 422 on validation failure (failedAtStep=validate)
 *   - 500 on rollback-complete failure
 *   - 500 on rollback-partial failure (orphanedResources in body)
 *   - 401 when caller is unauthenticated
 *   - NoAdminRequired: non-admin authenticated users succeed when payload is valid
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Tests\Unit\Controller;

use OCA\OpenBuilt\Controller\ApplicationCreationController;
use OCA\OpenBuilt\Exception\WizardCreationException;
use OCA\OpenBuilt\Service\ApplicationCreationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for ApplicationCreationController.
 */
class ApplicationCreationControllerTest extends TestCase
{
    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var ApplicationCreationService&MockObject
     */
    private ApplicationCreationService&MockObject $creationService;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Controller under test.
     */
    private ApplicationCreationController $controller;

    /**
     * Set up shared mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->creationService = $this->createMock(ApplicationCreationService::class);
        $this->userSession     = $this->createMock(IUserSession::class);

        $this->controller = new ApplicationCreationController(
            request: $this->request,
            logger: $this->logger,
            creationService: $this->creationService,
            userSession: $this->userSession,
        );

        // Default: authenticated as 'admin'.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        // Default: request returns basic params.
        $this->request->method('getParams')->willReturn([
            'name'   => 'Test App',
            'slug'   => 'test-app',
            'preset' => 'single',
        ]);
    }//end setUp()

    // -------------------------------------------------------------------------
    // NoAdminRequired attribute
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardMethodCarriesNoAdminRequiredAttribute(): void
    {
        $reflection = new ReflectionMethod(ApplicationCreationController::class, 'wizard');
        $attrs      = $reflection->getAttributes(NoAdminRequired::class);
        self::assertNotEmpty($attrs, 'wizard() must carry #[NoAdminRequired]');
    }//end wizardMethodCarriesNoAdminRequiredAttribute()

    // -------------------------------------------------------------------------
    // 401 Unauthenticated
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardReturns401WhenNoUserSession(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->wizard();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        self::assertSame('unauthenticated', $response->getData()['error']);
    }//end wizardReturns401WhenNoUserSession()

    // -------------------------------------------------------------------------
    // 201 Success
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardReturns201WithApplicationUuidOnSuccess(): void
    {
        $this->creationService->method('createApplication')
            ->willReturn('app-uuid-001');

        $response = $this->controller->wizard();

        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertSame('app-uuid-001', $response->getData()['applicationUuid']);
    }//end wizardReturns201WithApplicationUuidOnSuccess()

    // -------------------------------------------------------------------------
    // 422 Validation failure
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardReturns422OnValidationFailure(): void
    {
        $this->creationService->method('createApplication')
            ->willThrowException(new WizardCreationException(
                errorCode: 'validation_error',
                failedAtStep: 'validate',
                message: 'Invalid slug.',
                rollbackStatus: 'none',
            ));

        $response = $this->controller->wizard();

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $data = $response->getData();
        self::assertSame('validation_error', $data['code']);
        self::assertSame('validate', $data['failedAtStep']);
        self::assertSame('none', $data['rollbackStatus']);
    }//end wizardReturns422OnValidationFailure()

    // -------------------------------------------------------------------------
    // 500 Rollback complete
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardReturns500OnRollbackComplete(): void
    {
        $this->creationService->method('createApplication')
            ->willThrowException(new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'register-provision-production',
                message: 'Register creation failed.',
                rollbackStatus: 'complete',
            ));

        $response = $this->controller->wizard();

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $data = $response->getData();
        self::assertSame('wizard_rollback', $data['code']);
        self::assertSame('complete', $data['rollbackStatus']);
        self::assertArrayNotHasKey('orphanedResources', $data);
    }//end wizardReturns500OnRollbackComplete()

    // -------------------------------------------------------------------------
    // 500 Rollback partial
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardReturns500WithOrphanedResourcesOnRollbackPartial(): void
    {
        $this->creationService->method('createApplication')
            ->willThrowException(new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'register-provision-staging',
                message: 'Register creation failed.',
                rollbackStatus: 'partial',
                orphanedResources: ['openbuilt-test-app-development'],
            ));

        $response = $this->controller->wizard();

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $data = $response->getData();
        self::assertSame('partial', $data['rollbackStatus']);
        self::assertSame(['openbuilt-test-app-development'], $data['orphanedResources']);
    }//end wizardReturns500WithOrphanedResourcesOnRollbackPartial()

    // -------------------------------------------------------------------------
    // NoAdminRequired: non-admin succeeds
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function wizardAllowsNonAdminAuthenticatedUserOnValidPayload(): void
    {
        $nonAdminUser = $this->createMock(IUser::class);
        $nonAdminUser->method('getUID')->willReturn('regular-user');
        $this->userSession->method('getUser')->willReturn($nonAdminUser);

        $this->creationService->method('createApplication')
            ->willReturn('app-uuid-002');

        $response = $this->controller->wizard();

        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertSame('app-uuid-002', $response->getData()['applicationUuid']);
    }//end wizardAllowsNonAdminAuthenticatedUserOnValidPayload()
}//end class
