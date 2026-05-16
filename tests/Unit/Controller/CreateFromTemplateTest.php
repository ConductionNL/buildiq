<?php

/**
 * Unit tests for ApplicationsController::createFromTemplate.
 *
 * Covers the six branch-coverage cases mandated by tasks.md 1.3 / 4.1:
 *   - 404 unknown templateSlug
 *   - 4xx slug-collision within the same owner
 *   - success → 201 + Application + per-app register + companion schemas
 *   - manifest schema-refs rewritten with new-slug prefix
 *   - owner field tagged with authenticated UID
 *   - cross-user collision allowed (different owners can use same slug)
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

use OCA\OpenBuilt\Controller\ApplicationsController;
use OCA\OpenBuilt\Service\ManifestResolverService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationsController::createFromTemplate.
 */
class CreateFromTemplateTest extends TestCase
{
    /**
     * Controller under test.
     *
     * @var ApplicationsController
     */
    private ApplicationsController $controller;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock RegisterMapper.
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * Mock SchemaMapper.
     *
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IGroupManager (unused by createFromTemplate but required by the ctor).
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock ManifestResolverService (unused by createFromTemplate but required by the ctor).
     *
     * @var ManifestResolverService&MockObject
     */
    private ManifestResolverService&MockObject $manifestResolver;

    /**
     * Per-app Register entity stub.
     *
     * @var Register&MockObject
     */
    private Register&MockObject $perAppRegister;

    /**
     * The slug of the template under test in fixtures.
     *
     * @var string
     */
    private const TEMPLATE_SLUG = 'permit-tracker';

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->manifestResolver = $this->createMock(ManifestResolverService::class);

        $this->objectService = $this->createMock(ObjectService::class);

        // RegisterMapper mock chain: find()->getId(), create + update.
        $registerEntity = $this->getMockBuilder(Register::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $registerEntity->method('getId')->willReturn(926);

        $this->perAppRegister = $this->getMockBuilder(Register::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSchemas', 'setSchemas'])
            ->addMethods(['getId', 'getSlug'])
            ->getMock();
        $this->perAppRegister->method('getId')->willReturn(2001);
        $this->perAppRegister->method('getSlug')->willReturn('openbuilt-my-permits');
        $this->perAppRegister->method('getSchemas')->willReturn([]);
        $this->perAppRegister->method('setSchemas')->willReturn($this->perAppRegister);

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        // Default: shared register find succeeds, per-app register find throws (not yet provisioned).
        $this->registerMapper->method('find')->willReturnCallback(
            function (...$args) use ($registerEntity): Register {
                $slug = (string) ($args['id'] ?? $args[0]);
                if ($slug === 'openbuilt') {
                    return $registerEntity;
                }
                throw new \RuntimeException('register not found: '.$slug);
            }
        );
        $this->registerMapper->method('createFromArray')->willReturn($this->perAppRegister);
        $this->registerMapper->method('update')->willReturn($this->perAppRegister);

        // SchemaMapper mock chain: find()->getId() for shared schemas; createFromArray for clones.
        $applicationTemplateSchema = $this->getMockBuilder(Schema::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $applicationTemplateSchema->method('getId')->willReturn(1635);
        $applicationSchema = $this->getMockBuilder(Schema::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $applicationSchema->method('getId')->willReturn(1636);

        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->schemaMapper->method('find')->willReturnCallback(
            function (...$args) use ($applicationTemplateSchema, $applicationSchema): Schema {
                $slug = (string) ($args['id'] ?? $args[0]);
                if ($slug === 'application-template') {
                    return $applicationTemplateSchema;
                }
                if ($slug === 'application') {
                    return $applicationSchema;
                }
                throw new \RuntimeException('schema not found: '.$slug);
            }
        );

        $this->controller = new ApplicationsController(
            request: $this->request,
            logger: $this->logger,
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            manifestResolver: $this->manifestResolver,
            auditTrailMapper: null,
        );
    }//end setUp()

    /**
     * Build a Schema test double that reports the given numeric id.
     *
     * SchemaMapper::createFromArray() returns a Schema; the controller only
     * calls getId() on the result.
     *
     * @param int $id The schema id to report.
     *
     * @return Schema&MockObject
     */
    private function schemaWithId(int $id): Schema&MockObject
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $schema->method('getId')->willReturn($id);
        return $schema;
    }//end schemaWithId()

    /**
     * Build an ObjectEntity test double whose jsonSerialize() returns $payload.
     *
     * ObjectService::saveObject() returns an ObjectEntity; the controller
     * normalises it via jsonSerialize().
     *
     * @param array<string, mixed> $payload Serialised object payload.
     *
     * @return ObjectEntity&MockObject
     */
    private function savedEntity(array $payload): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end savedEntity()

    /**
     * Register an authenticated user for the test.
     *
     * @param string $uid The UID to return from getUID.
     *
     * @return void
     */
    private function authenticateAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end authenticateAs()

    /**
     * Set the request body params (name + slug).
     *
     * @param array<string,mixed> $params The body params.
     *
     * @return void
     */
    private function withRequestParams(array $params): void
    {
        $this->request->method('getParams')->willReturn($params);
    }//end withRequestParams()

    /**
     * Build a representative template record.
     *
     * @param string $slug The template slug.
     *
     * @return array<string,mixed>
     */
    private function templateRecord(string $slug): array
    {
        return [
            'slug'     => $slug,
            'version'  => '1.0.0',
            'manifest' => [
                'pages' => [
                    ['name' => 'Index', 'type' => 'index', 'config' => ['schema' => 'permit-application']],
                    ['name' => 'Form', 'type' => 'form', 'config' => ['schema' => 'permit-application']],
                ],
            ],
            'companionSchemas' => [
                [
                    'slug'    => 'permit-application',
                    'title'   => 'Permit application',
                    'type'    => 'object',
                    'version' => '0.1.0',
                ],
            ],
        ];
    }//end templateRecord()

    /**
     * Test 1 — Unknown templateSlug → 404 + template_not_found error envelope.
     *
     * @return void
     */
    public function testReturns404WhenTemplateSlugUnknown(): void
    {
        $this->authenticateAs('alice');
        $this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

        // Template lookup returns no hits (any number of times — controller may also
        // perform a slug-collision lookup after the missing template would normally
        // be detected; both return empty here).
        $this->objectService->method('searchObjects')->willReturn([]);

        $result = $this->controller->createFromTemplate(templateSlug: 'no-such-template');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $body = $result->getData();
        self::assertSame('template_not_found', $body['error']);
    }//end testReturns404WhenTemplateSlugUnknown()

    /**
     * Test 2 — Same-user slug collision → 4xx + slug_collision error envelope.
     *
     * The lookup sequence for createFromTemplate is:
     *   1. lookupOne(templateSchema, slug=templateSlug) — template exists
     *   2. lookupOne(applicationSchema, slug=newSlug, owner=alice) — existing app collides
     *
     * @return void
     */
    public function testReturns4xxOnSlugCollisionForSameOwner(): void
    {
        $this->authenticateAs('alice');
        $this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

        $this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
            // 1) template found
            [$this->templateRecord(self::TEMPLATE_SLUG)],
            // 2) existing application with the same slug owned by alice
            [['slug' => 'my-permits', 'owner' => 'alice']]
        );

        $result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

        self::assertGreaterThanOrEqual(400, $result->getStatus());
        self::assertLessThan(500, $result->getStatus());
        $body = $result->getData();
        self::assertSame('slug_collision', $body['error']);
    }//end testReturns4xxOnSlugCollisionForSameOwner()

    /**
     * Test 3 — Success: 201 + Application + per-app register + companion schema with prefix.
     *
     * @return void
     */
    public function testSuccessCreatesApplicationAndPerAppArtifacts(): void
    {
        $this->authenticateAs('alice');
        $this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

        // Lookup sequence: 1) template found, 2) no slug collision.
        $this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
            [$this->templateRecord(self::TEMPLATE_SLUG)],
            []
        );

        // Expect a schema clone CALL with the prefixed slug `my-permits-permit-application`.
        $this->schemaMapper->expects(self::once())
            ->method('createFromArray')
            ->with(self::callback(
                static function (array $payload): bool {
                    return ($payload['slug'] ?? null) === 'my-permits-permit-application';
                }
            ))
            ->willReturn($this->schemaWithId(7777));

        $this->objectService->expects(self::once())
            ->method('saveObject')
            ->willReturn($this->savedEntity(['uuid' => 'new-uuid-1', 'slug' => 'my-permits']));

        $result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

        self::assertSame(Http::STATUS_CREATED, $result->getStatus());
        $body = $result->getData();
        self::assertSame('new-uuid-1', $body['uuid']);
        self::assertSame('my-permits', $body['slug']);
        self::assertSame('openbuilt-my-permits', $body['register']);
        self::assertSame([7777], $body['companionSchemas']);
    }//end testSuccessCreatesApplicationAndPerAppArtifacts()

    /**
     * Test 4 — Manifest schema-refs are rewritten with the new-slug prefix.
     *
     * @return void
     */
    public function testManifestSchemaRefsRewrittenWithNewSlugPrefix(): void
    {
        $this->authenticateAs('alice');
        $this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

        $this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
            [$this->templateRecord(self::TEMPLATE_SLUG)],
            []
        );

        $this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(7777));

        $savedPayload = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$savedPayload): ObjectEntity {
                $savedPayload = $object;
                return $this->savedEntity(['uuid' => 'new-uuid-2']);
            }
        );

        $result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);
        self::assertSame(Http::STATUS_CREATED, $result->getStatus());

        self::assertIsArray($savedPayload, 'saveObject should have been invoked with the cloned Application payload');
        $manifest = $savedPayload['manifest'] ?? [];
        $pages    = $manifest['pages'] ?? [];
        self::assertCount(2, $pages);
        foreach ($pages as $page) {
            self::assertSame(
                'my-permits-permit-application',
                $page['config']['schema'] ?? null,
                'every manifest page-config schema must be rewritten with the new-slug prefix'
            );
        }
    }//end testManifestSchemaRefsRewrittenWithNewSlugPrefix()

    /**
     * Test 5 — Owner field on the persisted Application matches the authenticated UID.
     *
     * @return void
     */
    public function testOwnerFieldSetToAuthenticatedUid(): void
    {
        $this->authenticateAs('bob');
        $this->withRequestParams(['name' => 'Bob app', 'slug' => 'bob-app']);

        $this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
            [$this->templateRecord(self::TEMPLATE_SLUG)],
            []
        );

        $this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(8888));

        $savedPayload = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$savedPayload): ObjectEntity {
                $savedPayload = $object;
                return $this->savedEntity(['uuid' => 'new-uuid-3']);
            }
        );

        $result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);
        self::assertSame(Http::STATUS_CREATED, $result->getStatus());

        self::assertIsArray($savedPayload);
        self::assertSame('bob', $savedPayload['owner'] ?? null);
    }//end testOwnerFieldSetToAuthenticatedUid()

    /**
     * Test 6 — Cross-user slug usage is allowed: when the slug-collision lookup is
     * scoped to the caller, two different owners can each clone an Application with
     * the same slug. The controller's lookupOne for slug collision is scoped by `owner`.
     *
     * @return void
     */
    public function testDifferentOwnersCanCloneSameSlug(): void
    {
        $this->authenticateAs('carol');
        $this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

        // Sequence: template found, then the owner-scoped collision lookup returns []
        // (carol has no app with this slug — even though bob does, that's filtered
        // out by the owner filter).
        $this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
            [$this->templateRecord(self::TEMPLATE_SLUG)],
            []
        );

        $this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(9999));

        $savedPayload = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$savedPayload): ObjectEntity {
                $savedPayload = $object;
                return $this->savedEntity(['uuid' => 'new-uuid-4']);
            }
        );

        $result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

        self::assertSame(Http::STATUS_CREATED, $result->getStatus());
        self::assertIsArray($savedPayload);
        self::assertSame('carol', $savedPayload['owner'] ?? null);
        self::assertSame('my-permits', $savedPayload['slug'] ?? null);
    }//end testDifferentOwnersCanCloneSameSlug()
}//end class
