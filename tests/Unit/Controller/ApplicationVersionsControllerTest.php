<?php

/**
 * Unit tests for ApplicationVersionsController::release (spec
 * version-lifecycle-and-switcher / application-versions REQ-OBV-110).
 *
 * Covers the owner-only-no-admin-bypass authorisation on the release endpoint:
 *  - unauthenticated caller → 401
 *  - authenticated non-owner → 403 (admin power does NOT auto-grant release)
 *  - owner → 200, delegating to ApplicationVersionService::releaseVersion
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

use OCA\OpenBuild\Controller\ApplicationVersionsController;
use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationVersionsController::release.
 */
class ApplicationVersionsControllerTest extends TestCase
{
    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * @var ApplicationVersionService&MockObject
     */
    private ApplicationVersionService&MockObject $versionService;

    /**
     * Controller under test.
     */
    private ApplicationVersionsController $controller;

    /**
     * Set up shared mocks + the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request        = $this->createMock(IRequest::class);
        $this->objectService  = $this->createMock(ObjectService::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->groupManager   = $this->createMock(IGroupManager::class);
        $this->versionService = $this->createMock(ApplicationVersionService::class);

        // Real entities — NC Db entities expose getId() via magic __call, which
        // PHPUnit cannot mock; construct them and set the id instead.
        $register = new Register();
        $register->setId(1);
        $this->registerMapper->method('find')->willReturn($register);

        $schema = new Schema();
        $schema->setId(2);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->controller = new ApplicationVersionsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            versionService: $this->versionService,
            auditTrailMapper: null,
        );
    }//end setUp()

    /**
     * Build an IUser mock with the given UID.
     *
     * @param string $uid The user id.
     *
     * @return IUser&MockObject
     */
    private function mockUser(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end mockUser()

    /**
     * Unauthenticated caller → 401.
     *
     * @return void
     */
    public function testReleaseUnauthenticatedReturns401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->release('test23', 'draft-1');
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testReleaseUnauthenticatedReturns401()

    /**
     * Authenticated non-owner → 403; releaseVersion is never invoked.
     *
     * @return void
     */
    public function testReleaseNonOwnerReturns403(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('bob'));
        $this->groupManager->method('getUserGroups')->willReturn([]);

        // loadApplication → the app, owned by admin (not bob).
        $this->objectService->method('searchObjects')->willReturn(
            [['id' => 'app-uuid', 'slug' => 'test23', 'permissions' => ['owners' => ['user:admin']]]]
        );

        $this->versionService->expects(self::never())->method('releaseVersion');

        $response = $this->controller->release('test23', 'draft-1');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReleaseNonOwnerReturns403()

    /**
     * Owner → 200, delegating to ApplicationVersionService::releaseVersion.
     *
     * @return void
     */
    public function testReleaseOwnerReturns200(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $app     = ['id' => 'app-uuid', 'slug' => 'test23', 'permissions' => ['owners' => ['user:admin']]];
        $version = ['id' => 'v-uuid', 'slug' => 'draft-1', 'application' => 'app-uuid', 'status' => 'draft'];

        // loadApplication queries with a `slug` filter; findVersionRowBySlug does not.
        $this->objectService->method('searchObjects')->willReturnCallback(
            static function (array $query) use ($app, $version): array {
                return isset($query['slug']) ? [$app] : [$version];
            }
        );

        $this->versionService->expects(self::once())
            ->method('releaseVersion')
            ->with('app-uuid', 'v-uuid')
            ->willReturn(['productionVersion' => 'v-uuid', 'published' => 'v-uuid', 'archived' => null]);

        $response = $this->controller->release('test23', 'draft-1');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('v-uuid', $response->getData()['productionVersion']);
    }//end testReleaseOwnerReturns200()

    /**
     * An absent OpenRegister register must be TRANSLATED, not propagated.
     *
     * `RegisterMapper::find()` throws DoesNotExistException when the register
     * is missing — it does not return null. `release()` calls
     * `loadApplication()` OUTSIDE any try/catch, so before the translation was
     * added the exception escaped the controller entirely and Nextcloud's
     * dispatcher turned it into a framework 500 with a stack trace on a
     * `#[NoAdminRequired]` endpoint.
     *
     * This test fails (by raising) against that older behaviour, which is what
     * makes it a regression test rather than a restatement.
     *
     * @return void
     */
    public function testReleaseTranslatesMissingRegisterInsteadOfThrowing(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willThrowException(
            new DoesNotExistException('register openbuild not found')
        );

        $controller = new ApplicationVersionsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            registerMapper: $registerMapper,
            schemaMapper: $this->schemaMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            versionService: $this->versionService,
            auditTrailMapper: null,
        );

        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));

        $response = $controller->release('test23', 'draft-1');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        self::assertSame('not_found', $response->getData()['error']);
    }//end testReleaseTranslatesMissingRegisterInsteadOfThrowing()

    /**
     * The same translation on the read path: `show()` also calls its lookup
     * helper outside a try/catch, so an absent SCHEMA must come back as a 404
     * rather than escaping.
     *
     * @return void
     */
    public function testShowTranslatesMissingSchemaInsteadOfThrowing(): void
    {
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willThrowException(
            new DoesNotExistException('schema application-version not found')
        );

        $controller = new ApplicationVersionsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $schemaMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            versionService: $this->versionService,
            auditTrailMapper: null,
        );

        $this->userSession->method('getUser')->willReturn($this->mockUser('admin'));
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->objectService->method('searchObjects')->willReturn(
            [['id' => 'app-uuid', 'slug' => 'test23', 'permissions' => ['owners' => ['user:admin']]]]
        );

        $response = $controller->show('test23', 'draft-1');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testShowTranslatesMissingSchemaInsteadOfThrowing()
}//end class
