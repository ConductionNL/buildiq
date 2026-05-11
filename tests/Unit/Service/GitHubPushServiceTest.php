<?php

/**
 * OpenBuilt GitHubPushService unit tests
 *
 * Locks the PAT-handling contract: the PAT MUST be a method-scoped
 * parameter, MUST NOT be stored on $this, and MUST NOT appear in any
 * log line. The current implementation is stubbed; these tests assert
 * the contract that a future live implementation MUST honour.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Service
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

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Service\GitHubPushService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Tests for {@see GitHubPushService} — PAT contract + return shape.
 */
final class GitHubPushServiceTest extends TestCase
{
    /**
     * push() accepts the PAT as a method-scoped argument. The signature
     * itself (verified via Reflection) is the contract; passing a PAT
     * MUST NOT throw, MUST NOT mutate $this, and MUST return the
     * documented shape.
     *
     * @return void
     */
    public function testPushAcceptsPatAsParameter(): void
    {
        $service = new GitHubPushService(new \Psr\Log\NullLogger());

        $reflection = new \ReflectionMethod($service, 'push');
        $parameters = $reflection->getParameters();
        $names      = array_map(static fn ($p) => $p->getName(), $parameters);

        self::assertContains('pat', $names, 'push() must declare a $pat parameter');

        // Calling push() with a PAT must complete without throwing.
        $result = $service->push(
            jobUuid: 'job-123',
            treeDir: '/tmp/some-tree',
            pat: 'ghp_test_token'
        );
        self::assertIsArray($result);
    }//end testPushAcceptsPatAsParameter()

    /**
     * The service MUST NOT store the PAT on $this — a Reflection scan
     * across all instance properties (before AND after a push() call)
     * must find zero matches for the PAT string.
     *
     * Security-critical: a regression here would mean a long-lived
     * service instance retains the PAT in memory between requests.
     *
     * @return void
     */
    public function testPushNeverStoresPatOnInstance(): void
    {
        $service = new GitHubPushService(new \Psr\Log\NullLogger());
        $pat     = 'ghp_super_secret_pat_dont_leak';

        $service->push(jobUuid: 'job-456', treeDir: '/tmp/tree', pat: $pat);

        $reflection = new \ReflectionObject($service);
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($service);
            self::assertNotSame(
                $pat,
                $value,
                'Property '.$property->getName().' must NOT hold the PAT'
            );
            if (is_string($value) === true) {
                self::assertStringNotContainsString(
                    $pat,
                    $value,
                    'Property '.$property->getName().' must NOT contain the PAT'
                );
            }
        }
    }//end testPushNeverStoresPatOnInstance()

    /**
     * The Phase-1 stub returns the documented array shape with
     * `repoUrl` and `pullRequestUrl` keys. When a live implementation
     * lands, the same shape MUST be preserved (this test pins the
     * contract).
     *
     * Also: the PAT MUST NOT leak into ANY log line emitted during the
     * call.
     *
     * @return void
     */
    public function testPushReturnsRepoAndPullRequestUrlsAndDoesNotLogPat(): void
    {
        $captured = [];
        $logger   = new class ($captured) extends AbstractLogger {
            /**
             * @var list<string>
             */
            private array $sink;

            public function __construct(array &$captured)
            {
                $this->sink = &$captured;
            }

            public function log($level, \Stringable|string $message, array $context=[]): void
            {
                $this->sink[] = (string) $message.' '.json_encode($context);
            }
        };

        $service = new GitHubPushService($logger);
        $pat     = 'ghp_unique_marker_xyz_42';

        $result = $service->push(
            jobUuid: 'job-789',
            treeDir: '/tmp/some-tree',
            pat: $pat
        );

        self::assertArrayHasKey('repoUrl', $result);
        self::assertArrayHasKey('pullRequestUrl', $result);

        foreach ($captured as $line) {
            self::assertStringNotContainsString(
                $pat,
                $line,
                'PAT must NEVER appear in a log line — found in: '.$line
            );
        }
    }//end testPushReturnsRepoAndPullRequestUrlsAndDoesNotLogPat()

    /**
     * resolveDefaultBranch() returns `development` for Conduction-style
     * orgs (per OQ-2 in design.md) and `main` for everything else.
     * The PAT parameter is method-scoped — same contract as push().
     *
     * @return void
     */
    public function testResolveDefaultBranchHonoursConductionHeuristic(): void
    {
        $service = new GitHubPushService(new \Psr\Log\NullLogger());

        self::assertSame(
            'development',
            $service->resolveDefaultBranch('ConductionNL', 'ghp_token'),
            'Conduction-style orgs must default to the `development` integration branch'
        );

        self::assertSame(
            'main',
            $service->resolveDefaultBranch('acme-co', 'ghp_token'),
            'Non-Conduction orgs must default to `main`'
        );

        // PAT parameter is method-scoped — assert it's still in the
        // signature (catches an over-zealous refactor that strips it).
        $reflection = new \ReflectionMethod($service, 'resolveDefaultBranch');
        $names      = array_map(static fn ($p) => $p->getName(), $reflection->getParameters());
        self::assertContains('pat', $names);
    }//end testResolveDefaultBranchHonoursConductionHeuristic()
}//end class
