<?php

/**
 * OpenBuild ChannelApplyReport tests
 *
 * The report's job is to make a dropped item impossible to hide, so these tests
 * are mostly about the failure direction: an unbalanced report MUST throw, and a
 * wholesale channel skip MUST keep its declared count rather than collapsing to
 * zero. A report that only ever gets asserted on the happy path would not have
 * caught the silent 64-skill cap this programme already shipped once.
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

use OCA\OpenBuild\Service\ChannelApplyReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the channel apply report.
 */
class ChannelApplyReportTest extends TestCase
{
    /**
     * A balanced channel renders.
     *
     * @return void
     */
    public function testBalancedChannelRenders(): void
    {
        $report = new ChannelApplyReport();
        $report->declareChannel(channel: 'connectors', declared: 3);
        $report->recordCreated(channel: 'connectors', item: 'source/a');
        $report->recordSkipped(channel: 'connectors', item: 'source/b', reason: ChannelApplyReport::REASON_EXISTS);
        $report->recordFailed(channel: 'connectors', item: 'source/c', reason: 'boom');

        $out = $report->toArray();

        self::assertSame(3, $out['channels']['connectors']['declared']);
        self::assertSame(1, $out['channels']['connectors']['created']);
        self::assertSame(1, $out['channels']['connectors']['skipped']);
        self::assertSame(1, $out['channels']['connectors']['failed']);

    }//end testBalancedChannelRenders()

    /**
     * An item that was declared but never accounted for MUST throw rather than
     * render a plausible-looking report. This is the whole point of the class.
     *
     * @return void
     */
    public function testDroppedItemThrowsRatherThanRendering(): void
    {
        $report = new ChannelApplyReport();
        $report->declareChannel(channel: 'connectors', declared: 2);
        $report->recordCreated(channel: 'connectors', item: 'source/a');
        // 'source/b' is never recorded — exactly what a silent drop looks like.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not balance/');

        $report->toArray();

    }//end testDroppedItemThrowsRatherThanRendering()

    /**
     * Skipping a whole channel keeps the declared count. Reporting `declared: 0`
     * because the handler was missing is the same lie as dropping the items.
     *
     * @return void
     */
    public function testSkippedChannelKeepsItsDeclaredCount(): void
    {
        $report = new ChannelApplyReport();
        $report->declareChannel(channel: 'skills', declared: 94);
        $report->skipChannel(channel: 'skills', reason: 'hermiq-unavailable');

        $out = $report->toArray();

        self::assertSame(94, $out['channels']['skills']['declared']);
        self::assertSame(94, $out['channels']['skills']['skipped']);
        self::assertSame(0, $out['channels']['skills']['created']);
        self::assertSame('skipped', $out['channels']['skills']['status']);
        self::assertSame('hermiq-unavailable', $out['channels']['skills']['reason']);

    }//end testSkippedChannelKeepsItsDeclaredCount()

    /**
     * Truncated items stay inside the balance identity AND are separately
     * visible, so hitting a bound can never read as "there was nothing more".
     *
     * @return void
     */
    public function testTruncationIsCountedAndStaysBalanced(): void
    {
        $report = new ChannelApplyReport();
        $report->declareChannel(channel: 'automations', declared: 3);
        $report->recordCreated(channel: 'automations', item: 'a');
        $report->recordTruncated(channel: 'automations', item: 'b');
        $report->recordTruncated(channel: 'automations', item: 'c');

        $out = $report->toArray();

        self::assertSame(2, $out['channels']['automations']['truncated']);
        self::assertSame(2, $out['channels']['automations']['skipped']);
        self::assertSame(
            $out['channels']['automations']['declared'],
            ($out['channels']['automations']['created'] + $out['channels']['automations']['skipped'] + $out['channels']['automations']['failed'])
        );

    }//end testTruncationIsCountedAndStaysBalanced()

    /**
     * Credential gaps are collected per credential, de-duplicated per connector.
     *
     * @return void
     */
    public function testNeedsCredentialsAreCollected(): void
    {
        $report = new ChannelApplyReport();
        $report->declareChannel(channel: 'connectors', declared: 0);
        $report->needsCredential(credential: 'doffin', connector: 'source/a');
        $report->needsCredential(credential: 'doffin', connector: 'source/a');
        $report->needsCredential(credential: 'doffin', connector: 'source/b');

        $out = $report->toArray();

        self::assertSame(['source/a', 'source/b'], $out['needsCredentials']['doffin']);

    }//end testNeedsCredentialsAreCollected()
}//end class
