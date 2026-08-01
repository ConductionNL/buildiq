<?php

/**
 * OpenBuild AppChannelApplier tests
 *
 * These cover the properties that make applying safe rather than merely working:
 * a colliding connector is never overwritten, an absent optional app degrades
 * with a reason instead of vanishing, one failing item does not abort the rest,
 * and a missing credential is surfaced.
 *
 * The collision test asserts `failIfExists: true` reaches OpenRegister, because
 * that argument IS the never-overwrite guarantee. Remove it and this test fails —
 * which is the mutation check the change's tasks require.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\AppChannelApplier;
use OCA\OpenBuild\Service\ChannelApplyReport;
use OCA\OpenBuild\Service\ContainerLocator;
use OCA\OpenBuild\Service\DataRegisterProvisioner;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the v2 channel applier.
 */
class AppChannelApplierTest extends TestCase
{

    /**
     * The nil UUID — an obvious placeholder, never mistakable for a real id.
     *
     * @var string
     */
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * OR object read/write double.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Register mapper double.
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * Schema mapper double.
     *
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * App manager double (optional-dependency detection).
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Optional cross-app service locator double.
     *
     * @var ContainerLocator&MockObject
     */
    private ContainerLocator&MockObject $locator;

    /**
     * Build the collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService  = $this->createMock(ObjectService::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->appManager     = $this->createMock(IAppManager::class);
        $this->locator        = $this->createMock(ContainerLocator::class);

    }//end setUp()

    /**
     * Build the applier under test.
     *
     * @return AppChannelApplier
     */
    private function applier(): AppChannelApplier
    {
        return new AppChannelApplier(
            $this->objectService,
            // A REAL provisioner over mocked mappers: its declareChannel call is
            // what keeps the dataRegisters channel present in every report, so a
            // mock here would quietly remove an assertion this file depends on.
            new DataRegisterProvisioner(
                $this->registerMapper,
                $this->schemaMapper,
                $this->createMock(LoggerInterface::class)
            ),
            $this->appManager,
            $this->locator,
            $this->createMock(LoggerInterface::class)
        );

    }//end applier()

    /**
     * A template declaring one connector of the given kind.
     *
     * @param string $kind The connector kind.
     * @param string $uuid The connector uuid.
     *
     * @return array<string,mixed>
     */
    private function templateWithConnector(string $kind, string $uuid): array
    {
        return [
            'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
            'connectors'     => [$kind => ['example' => ['id' => $uuid, 'name' => 'Example']]],
        ];
    }//end templateWithConnector()

    /**
     * A v1 template installs unchanged and reports zero declared everywhere.
     *
     * @return void
     */
    public function testV1TemplateAppliesNothingAndBalances(): void
    {
        $this->objectService->expects(self::never())->method('saveObject');

        $report = $this->applier()->apply(template: ['manifest' => []]);

        foreach (['dataRegisters', 'connectors', 'automations', 'skills'] as $channel) {
            self::assertSame(0, $report['channels'][$channel]['declared']);
        }

    }//end testV1TemplateAppliesNothingAndBalances()

    /**
     * A connector is written at its PUBLISHED uuid, and with failIfExists set.
     *
     * The `failIfExists: true` assertion is deliberate: that argument is the
     * never-overwrite guarantee, so removing it must turn this test red.
     *
     * @return void
     */
    public function testConnectorIsWrittenAtItsPublishedUuidAndNeverOverwrites(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $this->objectService->expects(self::once())
            ->method('saveObject')
            ->with(
                self::anything(),
                self::anything(),
                'openconnector',
                'source',
                self::NIL_UUID,
                false,
                false,
                false,
                null,
                null,
                true
            );

        $report = $this->applier()->apply(
            template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
        );

        self::assertSame(1, $report['channels']['connectors']['created']);

    }//end testConnectorIsWrittenAtItsPublishedUuidAndNeverOverwrites()

    /**
     * A colliding uuid is reported as skipped, and the run still succeeds.
     *
     * @return void
     */
    public function testCollidingConnectorIsSkippedNotOverwritten(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        // The TYPED exception OpenRegister actually raises for an insert-only
        // conflict. Asserting on this type is what stops an unrelated error from
        // being misread as a benign collision.
        $this->objectService->method('saveObject')
            ->willThrowException(new ObjectExistsException('taken'));

        $report = $this->applier()->apply(
            template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
        );

        self::assertSame(0, $report['channels']['connectors']['created']);
        self::assertSame(1, $report['channels']['connectors']['skipped']);
        self::assertSame(
            ChannelApplyReport::REASON_EXISTS,
            $report['channels']['connectors']['items'][0]['reason']
        );

    }//end testCollidingConnectorIsSkippedNotOverwritten()

    /**
     * A genuine failure is recorded as failed, not silently swallowed and not
     * mistaken for a collision.
     *
     * @return void
     */
    public function testGenuineFailureIsRecordedAsFailed(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->objectService->method('saveObject')
            ->willThrowException(new RuntimeException('database is on fire'));

        $report = $this->applier()->apply(
            template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
        );

        self::assertSame(1, $report['channels']['connectors']['failed']);
        self::assertSame('failed', $report['channels']['connectors']['items'][0]['outcome']);

    }//end testGenuineFailureIsRecordedAsFailed()

    /**
     * Connectors degrade when openconnector is absent — declared count preserved.
     *
     * @return void
     */
    public function testConnectorsDegradeWhenOpenConnectorIsAbsent(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->objectService->expects(self::never())->method('saveObject');

        $report = $this->applier()->apply(
            template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
        );

        self::assertSame(1, $report['channels']['connectors']['declared']);
        self::assertSame(1, $report['channels']['connectors']['skipped']);
        self::assertSame('openconnector-unavailable', $report['channels']['connectors']['reason']);

    }//end testConnectorsDegradeWhenOpenConnectorIsAbsent()

    /**
     * Skills degrade when hermiq is absent, keeping the declared count so the
     * caller can see that 2 skills were declared and none installed.
     *
     * @return void
     */
    public function testSkillsDegradeWhenHermiqIsAbsent(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->locator->method('get')->willReturn(null);

        $report = $this->applier()->apply(
            template: [
                'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
                'skills'         => [
                    'alpha' => ['SKILL.md' => '# alpha'],
                    'beta'  => ['SKILL.md' => '# beta'],
                ],
            ]
        );

        self::assertSame(2, $report['channels']['skills']['declared']);
        self::assertSame(2, $report['channels']['skills']['skipped']);
        self::assertSame('hermiq-unavailable', $report['channels']['skills']['reason']);

    }//end testSkillsDegradeWhenHermiqIsAbsent()

    /**
     * An unresolvable credentialRef is surfaced, so "installed" is not confused
     * with "runnable".
     *
     * @return void
     */
    public function testUnresolvableCredentialIsReported(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->objectService->method('findAll')->willReturn([]);

        $report = $this->applier()->apply(
            template: [
                'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
                'connectors'     => [
                    'source' => [
                        'example' => [
                            'id'            => self::NIL_UUID,
                            'configuration' => [
                                'authentication' => [
                                    'credentialRef' => ['credentialName' => 'PLACEHOLDER_CREDENTIAL'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        self::assertArrayHasKey('PLACEHOLDER_CREDENTIAL', $report['needsCredentials']);
        self::assertSame(['source/example'], $report['needsCredentials']['PLACEHOLDER_CREDENTIAL']);

    }//end testUnresolvableCredentialIsReported()

    /**
     * An inconclusive credential lookup must NOT be reported as "missing" — an
     * absence claim manufactured by a failing lookup is worse than no claim.
     *
     * @return void
     */
    public function testInconclusiveCredentialLookupIsNotReportedAsMissing(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->objectService->method('findAll')
            ->willThrowException(new RuntimeException('broker unavailable'));

        $report = $this->applier()->apply(
            template: [
                'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
                'connectors'     => [
                    'source' => [
                        'example' => [
                            'id'            => self::NIL_UUID,
                            'credentialRef' => ['credentialName' => 'PLACEHOLDER_CREDENTIAL'],
                        ],
                    ],
                ],
            ]
        );

        self::assertSame([], $report['needsCredentials']);

    }//end testInconclusiveCredentialLookupIsNotReportedAsMissing()
}//end class
