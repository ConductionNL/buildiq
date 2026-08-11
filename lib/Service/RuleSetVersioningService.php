<?php

/**
 * OpenBuild RuleSetVersioningService
 *
 * Owns the semver bump applied when a RuleSet transitions test → active
 * (design.md Decision 5). Patch for a rule-condition/action change, minor for a
 * new input/output column, major for a breaking change. Validation: every
 * TestCase for the RuleSet must pass before activation is allowed; otherwise an
 * exception lists the failing cases (REQ-BRE-004 / REQ-BRE-005).
 *
 * The status transition itself is the declarative OR lifecycle (`activate`);
 * this service computes the new version string and runs the pre-activation test
 * gate, then writes the bumped version + geactiveerdOp back onto the RuleSet.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Semver + activation-gate logic for RuleSets.
 */
class RuleSetVersioningService
{

    /**
     * Bump kinds.
     */
    public const BUMP_PATCH = 'patch';
    public const BUMP_MINOR = 'minor';
    public const BUMP_MAJOR = 'major';

    /**
     * Constructor.
     *
     * @param ObjectService     $objectService     OpenRegister object service.
     * @param RuleEngineService $ruleEngineService Used to evaluate TestCase payloads.
     * @param LoggerInterface   $logger            PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RuleEngineService $ruleEngineService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Compute the next semver from a current version and a bump kind.
     *
     * @param string $current The current semver (e.g. 1.2.3).
     * @param string $bump    One of patch / minor / major.
     *
     * @return string The bumped semver.
     */
    public function bumpVersion(string $current, string $bump): string
    {
        $parts = array_map('intval', array_pad(explode('.', $current), 3, '0'));
        [$major, $minor, $patch] = $parts;

        switch ($bump) {
            case self::BUMP_MAJOR:
                ++$major;
                $minor = 0;
                $patch = 0;
                break;
            case self::BUMP_MINOR:
                ++$minor;
                $patch = 0;
                break;
            case self::BUMP_PATCH:
            default:
                ++$patch;
                break;
        }

        return $major.'.'.$minor.'.'.$patch;

    }//end bumpVersion()

    /**
     * Promote a RuleSet to active: gate on tests, bump version, set timestamp.
     *
     * @param array<string,mixed>            $ruleSet   The RuleSet object data.
     * @param array<int,array<string,mixed>> $testCases The RuleSet's TestCases.
     * @param string                         $bump      The semver bump kind.
     *
     * @return array<string,mixed> The updated RuleSet data.
     *
     * @throws RuntimeException When one or more TestCases fail.
     *
     * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-005-versioning-on-activation-with-semver-auto-increment
     */
    public function promoteToActive(array $ruleSet, array $testCases, string $bump=self::BUMP_PATCH): array
    {
        $failures = $this->runTestGate(slug: (string) ($ruleSet['slug'] ?? ''), testCases: $testCases);
        if ($failures !== []) {
            throw new RuntimeException(
                'Cannot activate RuleSet — failing test cases: '.implode(', ', $failures)
            );
        }

        $ruleSet['version']     = $this->bumpVersion(current: (string) ($ruleSet['version'] ?? '1.0.0'), bump: $bump);
        $ruleSet['status']      = 'active';
        $ruleSet['activatedOn'] = gmdate('Y-m-d\TH:i:s\Z');

        try {
            $this->objectService->saveObject(
                object: $ruleSet,
                register: RuleEngineService::REGISTER_SLUG,
                schema: RuleEngineService::RULE_SET_SCHEMA
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: failed to persist promoted RuleSet',
                ['slug' => ($ruleSet['slug'] ?? ''), 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Failed to persist promoted RuleSet: '.$e->getMessage(), 0, $e);
        }

        return $ruleSet;

    }//end promoteToActive()

    /**
     * Run every TestCase and return the names of those that failed.
     *
     * @param string                         $slug      The RuleSet slug.
     * @param array<int,array<string,mixed>> $testCases The TestCases.
     *
     * @return array<int,string> Names of failing test cases.
     *
     * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-004-test-case-driven-sandbox-validation
     */
    public function runTestGate(string $slug, array $testCases): array
    {
        $failures = [];
        foreach ($testCases as $testCase) {
            $expected = ($testCase['expectedResult'] ?? []);
            $payload  = ($testCase['inputPayload'] ?? []);

            $outcome = $this->ruleEngineService->evaluate(
                ruleSetSlug: $slug,
                payload: $payload,
                version: null,
                dryRun: true
            );

            if ($this->matchesExpected(expected: $expected, actual: $outcome['result']) === false) {
                $failures[] = (string) ($testCase['name'] ?? 'unnamed');
            }
        }

        return $failures;

    }//end runTestGate()

    /**
     * Whether the actual output contains every expected key/value.
     *
     * @param array<string,mixed> $expected Expected output (subset).
     * @param array<string,mixed> $actual   Actual output.
     *
     * @return bool
     */
    private function matchesExpected(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (array_key_exists($key, $actual) === false || ($actual[$key] <=> $value) !== 0) {
                return false;
            }
        }

        return true;

    }//end matchesExpected()
}//end class
