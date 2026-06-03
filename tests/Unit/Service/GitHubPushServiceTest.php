<?php

/**
 * OpenBuild GitHubPushService unit tests
 *
 * Locks the PAT-handling contract: the PAT MUST be a method-scoped
 * parameter, MUST NOT be stored on $this, and MUST NOT appear in any
 * log line. Exercises the live IClientService-backed implementation
 * against a mocked HTTP client (no live GitHub calls in CI).
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
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\GitHubPushService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see GitHubPushService} — PAT contract + GitHub wire surface.
 */
final class GitHubPushServiceTest extends TestCase
{
    /**
     * Build a mocked IClientService whose newClient() returns a client that
     * answers GET (repo-absent: throws 404) and POST (returns the supplied
     * JSON payloads in sequence).
     *
     * @param array<int,array<string,mixed>> $postBodies Decoded bodies returned by successive POSTs.
     * @param bool                           $repoExists When true, GET returns 200 (repo present).
     *
     * @return IClientService Mocked service.
     */
    private function mockClientService(array $postBodies, bool $repoExists=false): IClientService
    {
        $client = $this->createMock(IClient::class);

        if ($repoExists === true) {
            $getResponse = $this->createMock(IResponse::class);
            $getResponse->method('getStatusCode')->willReturn(200);
            $client->method('get')->willReturn($getResponse);
        } else {
            $client->method('get')->willThrowException(new RuntimeException('Client error: 404 Not Found'));
        }

        $responses = [];
        foreach ($postBodies as $body) {
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn(json_encode($body));
            $responses[] = $response;
        }

        if ($responses !== []) {
            $client->method('post')->willReturnOnConsecutiveCalls(...$responses);
        }

        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($client);

        return $service;
    }//end mockClientService()

    /**
     * push() accepts the PAT as a method-scoped argument and drives the full
     * create-repo → push-tree → open-PR sequence against the mocked client.
     *
     * @return void
     */
    public function testPushAcceptsPatAndReturnsUrls(): void
    {
        $treeDir = sys_get_temp_dir().'/openbuild-test-tree-'.uniqid();
        mkdir($treeDir, 0o755, true);
        file_put_contents($treeDir.'/README.md', '# hello');

        // Order: createRepo, then for one file: blob; then tree, commit, ref; then PR.
        $service = new GitHubPushService(
            $this->mockClientService(
                    [
                        ['html_url' => 'https://github.com/acme/app', 'default_branch' => 'main'],
                        ['sha' => 'blobsha'],
                        ['sha' => 'treesha'],
                        ['sha' => 'commitsha'],
                        ['ref' => 'refs/heads/bootstrap'],
                        ['html_url' => 'https://github.com/acme/app/pull/1'],
                    ]
                    ),
            new NullLogger()
        );

        $reflection = new \ReflectionMethod($service, 'push');
        $names      = array_map(static fn ($p) => $p->getName(), $reflection->getParameters());
        self::assertContains('pat', $names, 'push() must declare a $pat parameter');

        $result = $service->push(
            jobUuid: 'job-123',
            treeDir: $treeDir,
            pat: 'ghp_test_token',
            org: 'acme',
            repo: 'app',
            visibility: 'public'
        );

        self::assertSame('https://github.com/acme/app', $result['repoUrl']);
        self::assertSame('https://github.com/acme/app/pull/1', $result['pullRequestUrl']);

        unlink($treeDir.'/README.md');
        rmdir($treeDir);
    }//end testPushAcceptsPatAndReturnsUrls()

    /**
     * push() against an already-existing repo fails fast (REQ-OBEX-007).
     *
     * @return void
     */
    public function testPushFailsFastWhenRepoExists(): void
    {
        $treeDir = sys_get_temp_dir().'/openbuild-test-tree-'.uniqid();
        mkdir($treeDir, 0o755, true);

        $service = new GitHubPushService(
            $this->mockClientService([], repoExists: true),
            new NullLogger()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        try {
            $service->push(
                jobUuid: 'job-x',
                treeDir: $treeDir,
                pat: 'ghp_token',
                org: 'gemeente-rotterdam',
                repo: 'klachten-beheer',
                visibility: 'public'
            );
        } finally {
            rmdir($treeDir);
        }
    }//end testPushFailsFastWhenRepoExists()

    /**
     * The service MUST NOT store the PAT on $this and MUST NOT log it.
     *
     * @return void
     */
    public function testPushNeverStoresOrLogsPat(): void
    {
        $treeDir = sys_get_temp_dir().'/openbuild-test-tree-'.uniqid();
        mkdir($treeDir, 0o755, true);
        file_put_contents($treeDir.'/x.txt', 'data');

        $captured = [];
        $logger   = new class ($captured) extends AbstractLogger {

            /**
             * @var list<string>
             */
            private array $sink;

            public function __construct(array &$captured)
            {
                $this->sink = &$captured;
            }//end __construct()

            public function log($level, \Stringable|string $message, array $context=[]): void
            {
                $this->sink[] = (string) $message.' '.json_encode($context);
            }//end log()
        };

        $pat     = 'ghp_super_secret_pat_dont_leak';
        $service = new GitHubPushService(
            $this->mockClientService(
                    [
                        ['html_url' => 'https://github.com/acme/app', 'default_branch' => 'main'],
                        ['sha' => 'b'],
                        ['sha' => 't'],
                        ['sha' => 'c'],
                        ['ref' => 'r'],
                        ['html_url' => 'https://github.com/acme/app/pull/2'],
                    ]
                    ),
            $logger
        );

        $service->push(jobUuid: 'job-456', treeDir: $treeDir, pat: $pat, org: 'acme', repo: 'app');

        $reflection = new \ReflectionObject($service);
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($service);
            if (is_string($value) === true) {
                self::assertStringNotContainsString($pat, $value, 'Property '.$property->getName().' must NOT contain the PAT');
            }
        }

        foreach ($captured as $line) {
            self::assertStringNotContainsString($pat, $line, 'PAT must NEVER appear in a log line — found in: '.$line);
        }

        unlink($treeDir.'/x.txt');
        rmdir($treeDir);
    }//end testPushNeverStoresOrLogsPat()

    /**
     * resolveDefaultBranch() returns `development` for Conduction-style orgs
     * (OQ-2) and `main` for everything else; PAT stays method-scoped.
     *
     * @return void
     */
    public function testResolveDefaultBranchHonoursConductionHeuristic(): void
    {
        $service = new GitHubPushService($this->mockClientService([]), new NullLogger());

        self::assertSame('development', $service->resolveDefaultBranch('ConductionNL', 'ghp_token'));
        self::assertSame('main', $service->resolveDefaultBranch('acme-co', 'ghp_token'));

        $reflection = new \ReflectionMethod($service, 'resolveDefaultBranch');
        $names      = array_map(static fn ($p) => $p->getName(), $reflection->getParameters());
        self::assertContains('pat', $names);
    }//end testResolveDefaultBranchHonoursConductionHeuristic()
}//end class
