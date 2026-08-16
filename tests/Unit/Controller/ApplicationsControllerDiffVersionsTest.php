<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Wire-contract tests for ApplicationsController::diffVersions().
 *
 * These pin the ONE thing the endpoint could not previously do: answer a miss
 * with a 404.
 *
 * `resolveVersionBlob()` documents "Returns null on miss so the caller can
 * surface 404", and `diffVersions()` duly carries two
 * `if (...Blob === null) return 404` branches. Neither could ever be taken.
 * `ObjectService::find()` THROWS when the object is absent rather than
 * returning null, so the throw sailed past both branches into diffVersions()'s
 * outer `catch (Throwable)` and came back as a 500 `internal_error`:
 *
 *   OpenBuild: diffVersions failed for slug hello-world:
 *   Object not found in magic table
 *
 * This is the eighth instance of the family PR #159 fixed ("seven 404 branches
 * were unreachable — the lookup threw first"). It was not among those seven
 * because gate-49 only flags an untranslated lookup OUTSIDE a try/catch, and
 * being inside one is exactly what turned a precise 404 into an opaque 500 on
 * a `#[NoAdminRequired]` endpoint.
 *
 * Both new arms are covered here: the empty token (never asked of the object
 * store at all) and the unknown token (asked, throws, translated).
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationsController;
use OCA\OpenBuild\Service\AppChannelApplier;
use OCA\OpenBuild\Service\ManifestResolverService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for ApplicationsController::diffVersions().
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */
class ApplicationsControllerDiffVersionsTest extends TestCase
{

    /**
     * Request mock.
     *
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * OpenRegister object service mock.
     *
     * @var ObjectServiceInterface&MockObject
     */
    private $objectService;

    /**
     * Register mapper mock.
     *
     * @var RegisterMapper&MockObject
     */
    private $registerMapper;

    /**
     * Schema mapper mock.
     *
     * @var SchemaMapper&MockObject
     */
    private $schemaMapper;

    /**
     * User session mock.
     *
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * Group manager mock.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * Build the collaborator mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request        = $this->createMock(IRequest::class);
        $this->objectService  = $this->createMock(ObjectServiceInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->groupManager   = $this->createMock(IGroupManager::class);

    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return ApplicationsController
     */
    private function controller(): ApplicationsController
    {
        return new ApplicationsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            manifestResolver: $this->createMock(ManifestResolverService::class),
            permissionResolver: new PermissionResolver(
                $this->groupManager,
                $this->createMock(LoggerInterface::class)
            ),
            channelApplier: $this->createMock(AppChannelApplier::class),
            auditTrailMapper: null
        );

    }//end controller()

    /**
     * Build an ObjectEntity carrying the given payload.
     *
     * @param array<string,mixed> $payload The object's serialised form.
     *
     * @return ObjectEntity
     */
    private function buildEntity(array $payload): ObjectEntity
    {
        $entity = new class () extends ObjectEntity {

            /**
             * The payload this stand-in serialises to.
             *
             * @var array<string,mixed>
             */
            public array $payload = [];

            /**
             * Serialise the payload.
             *
             * @return array<string,mixed>
             */
            public function jsonSerialize(): array
            {
                return $this->payload;
            }
        };

        $entity->payload = $payload;
        return $entity;

    }//end buildEntity()

    /**
     * Put a logged-in owner behind the wheel and make the slug resolvable.
     *
     * `diffVersions` runs the same `resolveApplicationBySlug` + permission gate
     * as `getManifest`, so both have to succeed before the code under test is
     * reached at all. The caller is an OWNER so the permission check passes on
     * its own terms rather than through an admin bypass — a 403 here would make
     * these tests pass for the wrong reason (a refusal is not a 404).
     *
     * @return void
     */
    private function resolvableApplication(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        // `IGroupManager::getUserGroups()` is declared to return an array. An
        // unstubbed mock returns null, and PermissionResolver::resolveUserGroups()
        // then foreach-es over it — a PHP warning that is an artefact of the
        // mock, not a product defect. Stubbed to the empty list the interface
        // guarantees.
        //
        // Empty is also the honest value here: `bob` is authorised as a `user:`
        // principal in the owners list, so the verdict must not depend on group
        // membership at all. If this returned a group that happened to be named
        // in `permissions`, the tests below would pass without proving the
        // owner grant works.
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->method('getId')->willReturn(1);
        $this->registerMapper->method('find')->willReturn($register);

        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('getId')->willReturn(2);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Step 1 of resolveApplicationBySlug: slug -> BuiltAppRoute.
        $this->objectService->method('searchObjects')->willReturn(
            [$this->buildEntity(['slug' => 'hello-world', 'applicationUuid' => 'app-uuid-1'])]
        );

    }//end resolvableApplication()

    /**
     * An EMPTY `from` token is answered 404, not 500.
     *
     * `GET .../versions/diff?from=&to=` is what the Newman versioning
     * collection actually sent once its earlier steps stopped capturing a
     * version uuid. Asking the object store to find the object whose id is the
     * empty string is not a question with an answer — it only throws — so the
     * empty token is now treated as a miss before the lookup happens.
     *
     * @return void
     */
    public function testDiffVersionsReturns404ForAnEmptyToken(): void
    {
        $this->resolvableApplication();

        // Step 2 loads the Application; the version lookup must never run for
        // an empty token, so `find` is answered once and only once.
        $this->objectService->expects(self::once())->method('find')->willReturn(
            $this->buildEntity(
                [
                    'slug'        => 'hello-world',
                    'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => []],
                ]
            )
        );

        $response = $this->controller()->diffVersions(slug: 'hello-world', from: '', to: '');

        self::assertSame(
            Http::STATUS_NOT_FOUND,
            $response->getStatus(),
            'an unresolvable version ref is a 404 about that ref, not a 500 about the server'
        );

    }//end testDiffVersionsReturns404ForAnEmptyToken()

    /**
     * An UNKNOWN version uuid is answered 404, not 500.
     *
     * This is the arm that was dead code: the lookup throws, and before the
     * translation was added the throw reached diffVersions()'s outer
     * `catch (Throwable)` and became `internal_error` / 500.
     *
     * @return void
     */
    public function testDiffVersionsReturns404WhenTheVersionLookupThrows(): void
    {
        $this->resolvableApplication();

        $application = $this->buildEntity(
            [
                'slug'        => 'hello-world',
                'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => []],
            ]
        );

        // First find() resolves the Application; the second is the version
        // lookup and throws exactly as ObjectService::find() does on a miss.
        $calls = 0;
        $this->objectService->method('find')->willReturnCallback(
            static function () use (&$calls, $application) {
                $calls++;
                if ($calls === 1) {
                    return $application;
                }

                throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
            }
        );

        $response = $this->controller()->diffVersions(
            slug: 'hello-world',
            from: '0460d12e-ff63-4aaa-b48e-babba93920ff',
            to: '0460d12e-ff63-4aaa-b48e-babba93920ee'
        );

        self::assertSame(
            Http::STATUS_NOT_FOUND,
            $response->getStatus(),
            'a version that does not exist is a 404 naming it, not a 500 hiding it'
        );

        $data = $response->getData();
        self::assertIsArray($data);
        self::assertSame('not_found', ($data['error'] ?? null));

    }//end testDiffVersionsReturns404WhenTheVersionLookupThrows()
}//end class
