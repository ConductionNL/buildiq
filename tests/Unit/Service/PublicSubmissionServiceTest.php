<?php

/**
 * Unit tests for PublicSubmissionService (public-form-access).
 *
 * Covers:
 *  - Honeypot-filled submission is silently dropped (no write).
 *  - mode:submit creates a new object via ObjectService (owner-context, `_rbac: false`).
 *  - mode:edit updates the bound object, merging submitted fields over the existing record.
 *  - mode:edit rejects when the bound object no longer resolves.
 *  - mode:read never reaches a write path (throws).
 *  - requireEmailVerification flags the written object `emailVerified: false`.
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
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-anonymous-submission-writes-via-owner-context-service-never-a-visitor-identity
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\PublicSubmissionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PublicSubmissionService.
 */
class PublicSubmissionServiceTest extends TestCase
{

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Service under test.
     */
    private PublicSubmissionService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->service        = new PublicSubmissionService(
            objectService: $this->objectService,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * @return void
     */
    public function testHoneypotFilledSubmissionIsSilentlyDropped(): void
    {
        $this->objectService->expects(self::never())->method('saveObject');

        $result = $this->service->submit(
            shareToken: ['mode' => 'submit', 'honeypotField' => 'hp_abc123'],
            data: ['name' => 'Alice', 'hp_abc123' => 'a bot filled this in'],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );

        self::assertSame('honeypot_dropped', $result['status']);
    }//end testHoneypotFilledSubmissionIsSilentlyDropped()

    /**
     * @return void
     */
    public function testSubmitModeCreatesNewObject(): void
    {
        $captured = null;
        $this->objectService->expects(self::once())
            ->method('saveObject')
            ->willReturnCallback(
                function (array $object, ...$rest) use (&$captured) {
                    $captured = $object;
                    return $this->mockEntity($object);
                }
            );

        $result = $this->service->submit(
            shareToken: ['mode' => 'submit', 'honeypotField' => 'hp_abc123'],
            data: ['name' => 'Alice', 'hp_abc123' => ''],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );

        self::assertSame('created', $result['status']);
        self::assertSame('Alice', $captured['name']);
        self::assertArrayNotHasKey('hp_abc123', $captured);
    }//end testSubmitModeCreatesNewObject()

    /**
     * @return void
     */
    public function testEditModeUpdatesBoundObjectMergingExistingFields(): void
    {
        $this->objectService->method('find')->willReturn($this->mockEntity(['name' => 'Old Name', 'untouchedField' => 'keep-me']));

        $captured = null;
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function (array $object, ...$rest) use (&$captured) {
                    $captured = $object;
                    return $this->mockEntity($object);
                }
            );

        $result = $this->service->submit(
            shareToken: ['mode' => 'edit', 'boundObjectId' => 'bound-uuid', 'honeypotField' => ''],
            data: ['name' => 'New Name'],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );

        self::assertSame('updated', $result['status']);
        self::assertSame('New Name', $captured['name']);
        // saveObject is PUT-semantic — untouched fields MUST survive the merge.
        self::assertSame('keep-me', $captured['untouchedField']);
    }//end testEditModeUpdatesBoundObjectMergingExistingFields()

    /**
     * @return void
     */
    public function testEditModeRejectsWhenBoundObjectMissing(): void
    {
        $this->objectService->method('find')->willReturn(null);
        $this->objectService->expects(self::never())->method('saveObject');

        $result = $this->service->submit(
            shareToken: ['mode' => 'edit', 'boundObjectId' => 'deleted-uuid', 'honeypotField' => ''],
            data: ['name' => 'New Name'],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );

        self::assertSame('not_found', $result['status']);
    }//end testEditModeRejectsWhenBoundObjectMissing()

    /**
     * @return void
     */
    public function testModeReadThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->submit(
            shareToken: ['mode' => 'read', 'honeypotField' => ''],
            data: [],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );
    }//end testModeReadThrows()

    /**
     * @return void
     */
    public function testRequireEmailVerificationFlagsNewObject(): void
    {
        $captured = null;
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function (array $object, ...$rest) use (&$captured) {
                    $captured = $object;
                    return $this->mockEntity($object);
                }
            );

        $this->service->submit(
            shareToken: ['mode' => 'submit', 'honeypotField' => '', 'requireEmailVerification' => true],
            data: ['email' => 'visitor@example.com'],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );

        self::assertFalse($captured['emailVerified']);
    }//end testRequireEmailVerificationFlagsNewObject()

    /**
     * @return void
     */
    public function testValidationFailureIsCaughtAndReported(): void
    {
        $this->objectService->method('saveObject')->willThrowException(new \RuntimeException('schema validation failed: missing required field'));

        $result = $this->service->submit(
            shareToken: ['mode' => 'submit', 'honeypotField' => ''],
            data: [],
            registerSlug: 'openbuild-myapp',
            schemaSlug: 'melding'
        );

        self::assertSame('validation_failed', $result['status']);
        self::assertStringContainsString('schema validation failed', $result['message']);
    }//end testValidationFailureIsCaughtAndReported()

    /**
     * Build a mock ObjectEntity whose jsonSerialize() returns the given
     * array — ObjectService's real methods are typed to return
     * `ObjectEntity`/`?ObjectEntity`, so a bare array cannot stand in for
     * them (mirrors AppOverrideServiceTest::mockEntity()).
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
