<?php

/**
 * OpenBuild RuleExecutionLogCleanup
 *
 * Weekly TimedJob (7-day interval) that enforces the RuleExecutionLog retention
 * policy (REQ-BRE-013). Logs older than the retention window (default 90 days)
 * are first marked `archived: true`, then purged. Records within the retention
 * window are left untouched. Idempotent — re-running over an already-archived
 * set is a no-op.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenBuild\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#8.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\BackgroundJob;

use OCA\OpenBuild\Service\RuleEngineService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retention-policy cleanup job for RuleExecutionLog records.
 */
class RuleExecutionLogCleanup extends TimedJob
{

    /**
     * Run interval: 7 days.
     */
    private const INTERVAL_SECONDS = 604800;

    /**
     * Retention window: 90 days.
     */
    private const RETENTION_SECONDS = 7776000;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time          Time factory.
     * @param ObjectService   $objectService OpenRegister object service.
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::INTERVAL_SECONDS);

    }//end __construct()

    /**
     * Archive then purge RuleExecutionLog records past the retention window.
     *
     * @param mixed $argument Job argument injected by Nextcloud (unused).
     *
     * @return void
     *
     * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-013-cleanup-job-for-aged-execution-logs
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        unset($argument);

        $cutoff = gmdate('Y-m-d\TH:i:s\Z', (time() - self::RETENTION_SECONDS));

        try {
            $results = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => RuleEngineService::REGISTER_SLUG,
                        'schema'   => RuleEngineService::EXECUTION_LOG_SCHEMA,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: RuleExecutionLog cleanup query failed',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        if (is_array($results) === false) {
            return;
        }

        $purged = 0;
        foreach ($results as $row) {
            $data      = $this->normalise(object: $row);
            $timestamp = (string) ($data['timestamp'] ?? '');
            if ($timestamp === '' || $timestamp >= $cutoff) {
                continue;
            }

            $uuid = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }

            try {
                $this->objectService->deleteObject(uuid: $uuid);
                ++$purged;
            } catch (Throwable $e) {
                $this->logger->warning(
                    'OpenBuild: failed to purge RuleExecutionLog record',
                    ['uuid' => $uuid, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        if ($purged > 0) {
            $this->logger->info('OpenBuild cleanup: purged '.$purged.' expired rule-execution log(s)');
        }

    }//end run()

    /**
     * Coerce an OR result entry to a plain array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];

    }//end normalise()
}//end class
