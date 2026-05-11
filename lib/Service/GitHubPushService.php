<?php

/**
 * OpenBuilt GitHub Push Service
 *
 * Pushes the generated app tree to a new GitHub repository and opens a
 * placeholder pull request. The PAT is method-scoped — never persisted on
 * the service instance, never logged, never echoed (Decision 3).
 *
 * Phase-1 implementation: stubbed. The wire-protocol contract is locked in
 * (signatures + PAT handling); the live HTTP calls land in a follow-up PR
 * once `knplabs/github-api` is on the lockfile.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

use Psr\Log\LoggerInterface;

/**
 * GitHub delivery target. PAT-handling contract documented in Decision 3.
 */
class GitHubPushService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Push the generated tree to a new GitHub repo + open a placeholder PR.
     *
     * Phase-1: stub. The contract guarantees PAT is method-scoped, never
     * stored on `$this`, never logged.
     *
     * @param string $jobUuid Export job UUID — used as the correlation key in audit logs.
     * @param string $treeDir Absolute path to the generated tree.
     * @param string $pat     GitHub PAT — method-scoped, never persisted.
     *
     * @return array{repoUrl:string,pullRequestUrl:string} URLs of the created repo + PR.
     */
    public function push(string $jobUuid, string $treeDir, string $pat): array
    {
        // Audit log names only the job + tree — never the PAT.
        $this->logger->info(
            'OpenBuilt GitHub push (stub): would push tree to repo',
            ['jobUuid' => $jobUuid, 'treeDir' => $treeDir]
        );

        // Phase-1 stub: caller treats result as "scheduled" and presents a
        // placeholder URL. Live HTTP calls land in a follow-up.
        unset($pat);

        return [
            'repoUrl'        => '',
            'pullRequestUrl' => '',
        ];
    }//end push()

    /**
     * Resolve the default branch for an org's repos (`development` when the
     * Conduction ruleset applies, else `main`). Stub returns 'main'.
     *
     * @param string $org Target organisation.
     * @param string $pat GitHub PAT — method-scoped.
     *
     * @return string Default branch name.
     */
    public function resolveDefaultBranch(string $org, string $pat): string
    {
        unset($pat);
        // Heuristic: Conduction orgs use `development` as integration branch.
        if (stripos($org, 'conduction') !== false) {
            return 'development';
        }

        return 'main';
    }//end resolveDefaultBranch()
}//end class
