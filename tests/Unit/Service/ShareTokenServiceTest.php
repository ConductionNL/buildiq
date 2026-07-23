<?php

/**
 * Unit tests for ShareTokenService (public-form-access).
 *
 * Covers:
 *  - issue(): rejects invalid mode, rejects a page not marked
 *    `config.public.enabled`, rejects mode:edit without boundObjectId,
 *    auto-defaults expiresAt for mode:edit, mints a token + honeypot field,
 *    hashes an optional password.
 *  - resolve(): not_found for unknown/revoked/expired token, password_required
 *    for a missing/wrong password, ok for a valid token (with passwordHash
 *    stripped from the returned payload).
 *  - revoke(): idempotent, flips `revoked` to true.
 *  - resolveTargetSchema(): parses `/api/objects/{register}/{schema}` from
 *    submitEndpoint, prefers explicit config.register/schema, null on miss.
 *  - findPage(): looks up a page by id.
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
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenBuild\Service\ShareTokenService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ShareTokenService.
 */
class ShareTokenServiceTest extends TestCase
{

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var IHasher&MockObject
     */
    private IHasher&MockObject $hasher;

    /**
     * Service under test.
     */
    private ShareTokenService $service;

    /**
     * A minimal manifest with one public-enabled `form` page.
     *
     * @var array<string, mixed>
     */
    private array $publicManifest;

    /**
     * Set up shared mocks and the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->hasher        = $this->createMock(IHasher::class);

        $register = $this->getMockBuilder(Register::class)->onlyMethods(['getId'])->getMock();
        $register->method('getId')->willReturn(1);
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willReturn($register);

        $schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getId'])->getMock();
        $schema->method('getId')->willReturn(2);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schema);

        $secureRandom = $this->createMock(ISecureRandom::class);
        $secureRandom->method('generate')->willReturn('random-token-value');

        $this->service = new ShareTokenService(
            objectService: $this->objectService,
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper,
            secureRandom: $secureRandom,
            hasher: $this->hasher,
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->publicManifest = [
            'version' => '1.0.0',
            'pages'   => [
                [
                    'id'     => 'intake-form',
                    'route'  => '/intake',
                    'type'   => 'form',
                    'title'  => 'Intake',
                    'config' => [
                        'submitEndpoint' => '/apps/openbuild/api/objects/openbuild-myapp/melding',
                        'public'         => ['enabled' => true],
                    ],
                ],
                [
                    'id'     => 'private-form',
                    'route'  => '/private',
                    'type'   => 'form',
                    'title'  => 'Private',
                    'config' => [],
                ],
            ],
        ];
    }//end setUp()

    /**
     * @return void
     */
    public function testIssueRejectsInvalidMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'intake-form',
            mode: 'bogus'
        );
    }//end testIssueRejectsInvalidMode()

    /**
     * @return void
     */
    public function testIssueRejectsUnknownPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'no-such-page',
            mode: 'submit'
        );
    }//end testIssueRejectsUnknownPage()

    /**
     * REQ: "Public page can only be issued a token when its config declares public.enabled".
     *
     * @return void
     */
    public function testIssueRejectsPageNotMarkedPublic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'private-form',
            mode: 'submit'
        );
    }//end testIssueRejectsPageNotMarkedPublic()

    /**
     * @return void
     */
    public function testIssueRejectsEditModeWithoutBoundObjectId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'intake-form',
            mode: 'edit'
        );
    }//end testIssueRejectsEditModeWithoutBoundObjectId()

    /**
     * @return void
     */
    public function testIssueAutoDefaultsExpiryForEditMode(): void
    {
        $saved = null;
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function (array $object) use (&$saved) {
                    $saved = $object;
                    return $this->mockEntity($object);
                }
            );

        $result = $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'intake-form',
            mode: 'edit',
            boundObjectId: 'bound-object-uuid'
        );

        self::assertNotNull($saved['expiresAt']);
        $expiry = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $saved['expiresAt']);
        self::assertNotFalse($expiry);
        self::assertGreaterThan(new DateTimeImmutable('+29 days'), $expiry);
        self::assertSame('edit', $result['mode']);
    }//end testIssueAutoDefaultsExpiryForEditMode()

    /**
     * @return void
     */
    public function testIssueMintsTokenAndHoneypotField(): void
    {
        $this->objectService->method('saveObject')
            ->willReturnCallback(fn (array $object) => $this->mockEntity($object));

        $result = $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'intake-form',
            mode: 'submit'
        );

        self::assertSame('random-token-value', $result['token']);
        self::assertStringStartsWith('hp_', $result['honeypotField']);
        self::assertFalse($result['revoked']);
        self::assertNull($result['passwordHash']);
    }//end testIssueMintsTokenAndHoneypotField()

    /**
     * @return void
     */
    public function testIssueHashesAnOptionalPassword(): void
    {
        $this->hasher->method('hash')->willReturn('hashed-value');
        $this->objectService->method('saveObject')
            ->willReturnCallback(fn (array $object) => $this->mockEntity($object));

        $result = $this->service->issue(
            applicationUuid: 'app-uuid',
            applicationManifest: $this->publicManifest,
            pageId: 'intake-form',
            mode: 'submit',
            password: 'secret123'
        );

        self::assertSame('hashed-value', $result['passwordHash']);
    }//end testIssueHashesAnOptionalPassword()

    /**
     * @return void
     */
    public function testResolveReturnsNotFoundForUnknownToken(): void
    {
        $this->objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->resolve(token: 'unknown');

        self::assertSame('not_found', $result['status']);
    }//end testResolveReturnsNotFoundForUnknownToken()

    /**
     * @return void
     */
    public function testResolveReturnsNotFoundForRevokedToken(): void
    {
        $this->objectService->method('searchObjects')->willReturn(
            [['token' => 'tok', 'revoked' => true, 'applicationId' => 'app-uuid']]
        );

        $result = $this->service->resolve(token: 'tok');

        self::assertSame('not_found', $result['status']);
    }//end testResolveReturnsNotFoundForRevokedToken()

    /**
     * @return void
     */
    public function testResolveReturnsNotFoundForExpiredToken(): void
    {
        $expired = (new DateTimeImmutable('-1 day'))->format(DateTimeInterface::ATOM);
        $this->objectService->method('searchObjects')->willReturn(
            [['token' => 'tok', 'revoked' => false, 'expiresAt' => $expired, 'applicationId' => 'app-uuid']]
        );

        $result = $this->service->resolve(token: 'tok');

        self::assertSame('not_found', $result['status']);
    }//end testResolveReturnsNotFoundForExpiredToken()

    /**
     * @return void
     */
    public function testResolveReturnsPasswordRequiredWhenPasswordMissing(): void
    {
        $this->objectService->method('searchObjects')->willReturn(
            [['token' => 'tok', 'revoked' => false, 'passwordHash' => 'hash', 'applicationId' => 'app-uuid']]
        );

        $result = $this->service->resolve(token: 'tok');

        self::assertSame('password_required', $result['status']);
    }//end testResolveReturnsPasswordRequiredWhenPasswordMissing()

    /**
     * @return void
     */
    public function testResolveReturnsPasswordRequiredWhenPasswordWrong(): void
    {
        $this->objectService->method('searchObjects')->willReturn(
            [['token' => 'tok', 'revoked' => false, 'passwordHash' => 'hash', 'applicationId' => 'app-uuid']]
        );
        $this->hasher->method('verify')->willReturn(false);

        $result = $this->service->resolve(token: 'tok', password: 'wrong');

        self::assertSame('password_required', $result['status']);
    }//end testResolveReturnsPasswordRequiredWhenPasswordWrong()

    /**
     * @return void
     */
    public function testResolveReturnsOkAndStripsPasswordHash(): void
    {
        $this->objectService->method('searchObjects')->willReturn(
            [['token' => 'tok', 'revoked' => false, 'passwordHash' => 'hash', 'applicationId' => 'app-uuid', 'pageId' => 'intake-form']]
        );
        $this->hasher->method('verify')->willReturn(true);

        $result = $this->service->resolve(token: 'tok', password: 'correct');

        self::assertSame('ok', $result['status']);
        self::assertSame('app-uuid', $result['applicationUuid']);
        self::assertArrayNotHasKey('passwordHash', $result['shareToken']);
    }//end testResolveReturnsOkAndStripsPasswordHash()

    /**
     * @return void
     */
    public function testRevokeReturnsFalseForUnknownToken(): void
    {
        $this->objectService->method('find')->willReturn(null);

        self::assertFalse($this->service->revoke(tokenUuid: 'unknown-uuid'));
    }//end testRevokeReturnsFalseForUnknownToken()

    /**
     * @return void
     */
    public function testRevokeSetsRevokedTrue(): void
    {
        $this->objectService->method('find')->willReturn($this->mockEntity(['id' => 'tok-uuid', 'revoked' => false]));
        $saved = null;
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function (array $object) use (&$saved) {
                    $saved = $object;
                    return $this->mockEntity($object);
                }
            );

        self::assertTrue($this->service->revoke(tokenUuid: 'tok-uuid'));
        self::assertTrue($saved['revoked']);
    }//end testRevokeSetsRevokedTrue()

    /**
     * @return void
     */
    public function testResolveTargetSchemaParsesSubmitEndpoint(): void
    {
        $page = ['config' => ['submitEndpoint' => '/apps/openbuild/api/objects/myregister/myschema']];

        $result = $this->service->resolveTargetSchema(page: $page);

        self::assertSame(['register' => 'myregister', 'schema' => 'myschema'], $result);
    }//end testResolveTargetSchemaParsesSubmitEndpoint()

    /**
     * @return void
     */
    public function testResolveTargetSchemaPrefersExplicitRegisterSchema(): void
    {
        $page = ['config' => ['register' => 'explicit-register', 'schema' => 'explicit-schema']];

        $result = $this->service->resolveTargetSchema(page: $page);

        self::assertSame(['register' => 'explicit-register', 'schema' => 'explicit-schema'], $result);
    }//end testResolveTargetSchemaPrefersExplicitRegisterSchema()

    /**
     * @return void
     */
    public function testResolveTargetSchemaReturnsNullWhenUnresolvable(): void
    {
        self::assertNull($this->service->resolveTargetSchema(page: ['config' => []]));
    }//end testResolveTargetSchemaReturnsNullWhenUnresolvable()

    /**
     * @return void
     */
    public function testFindPageReturnsMatchingPage(): void
    {
        $page = $this->service->findPage(manifest: $this->publicManifest, pageId: 'intake-form');

        self::assertNotNull($page);
        self::assertSame('intake-form', $page['id']);
    }//end testFindPageReturnsMatchingPage()

    /**
     * @return void
     */
    public function testFindPageReturnsNullForUnknownPageId(): void
    {
        self::assertNull($this->service->findPage(manifest: $this->publicManifest, pageId: 'nope'));
    }//end testFindPageReturnsNullForUnknownPageId()

    /**
     * Build a mock ObjectEntity whose jsonSerialize() returns the given
     * array — ObjectService's real methods are typed to return
     * `ObjectEntity`/`?ObjectEntity`, so a bare array cannot stand in for
     * them; the service's own `normaliseObject()` unwraps this via
     * `jsonSerialize()` exactly as it does for a real OR result (mirrors
     * AppOverrideServiceTest::mockEntity()).
     *
     * @param array<string, mixed> $data The array the entity should serialise to.
     *
     * @return ObjectEntity&MockObject
     */
    private function mockEntity(array $data): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($data);
        return $entity;
    }//end mockEntity()
}//end class
