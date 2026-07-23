<?php

/**
 * Unit tests for ApplicationsController.
 *
 * Focused on the createFromTemplate authorization + rate-limit hardening
 * (harden-xss-dos-csrf, openbuild-template-catalogue): the clone endpoint
 * provisions an OpenRegister register (admin-only, issue #157), so it mirrors
 * the creation wizard's admin gate and rate limit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
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

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationsController;
use OCA\OpenBuild\Service\ManifestResolverService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for {@see ApplicationsController} — createFromTemplate hardening.
 */
final class ApplicationsControllerTest extends TestCase
{

    /**
     * Mock HTTP request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Wire the shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

    }//end setUp()

    /**
     * Build the controller with mocked boundaries.
     *
     * @return ApplicationsController
     */
    private function controller(): ApplicationsController
    {
        return new ApplicationsController(
            $this->request,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ObjectService::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->userSession,
            $this->groupManager,
            $this->createMock(ManifestResolverService::class),
            $this->createMock(PermissionResolver::class),
            $this->createMock(AuditTrailMapper::class)
        );

    }//end controller()

    /**
     * An anonymous caller is rejected with 401 before any work.
     *
     * @return void
     */
    public function testCreateFromTemplateRequiresAuth(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller()->createFromTemplate('permit-tracker');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateFromTemplateRequiresAuth()

    /**
     * A non-admin caller is rejected with 403 (parity with the creation wizard),
     * before the clone/provisioning fan-out runs.
     *
     * @return void
     */
    public function testCreateFromTemplateRejectsNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isInGroup')->with('bob', 'admin')->willReturn(false);

        $response = $this->controller()->createFromTemplate('permit-tracker');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCreateFromTemplateRejectsNonAdmin()

    /**
     * The endpoint carries a UserRateLimit attribute (amplification guard).
     *
     * @return void
     */
    public function testCreateFromTemplateHasRateLimit(): void
    {
        $method     = new ReflectionMethod(ApplicationsController::class, 'createFromTemplate');
        $attributes = $method->getAttributes(UserRateLimit::class);
        $this->assertCount(1, $attributes);

    }//end testCreateFromTemplateHasRateLimit()
}//end class
