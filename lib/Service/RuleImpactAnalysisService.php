<?php

/**
 * OpenBuild RuleImpactAnalysisService
 *
 * Analyses which apps and users have called a RuleSet in the past 30 days
 * (REQ-BRE-010). When a RuleSet is about to be activated, the operator can
 * call `analyzeImpactOnActivation()` to get a report of all consuming apps
 * so they can be notified of the change. This supports AVG art. 22
 * transparency: downstream consumers know when the rules that affect them
 * change.
 *
 * The analysis is purely read-based: it queries the RuleExecutionLog schema
 * (written by RuleEngineService on every evaluation) and groups records by
 * the `userId` and `ruleSetId` fields. No side-effects are produced by the
 * analysis itself — the caller is responsible for dispatching notifications
 * (e.g. via OR's x-openregister-notifications or a Nextcloud notification
 * service) using the returned recipient list.
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
 * @spec openspec/changes/business-rules-engine/tasks.md#7.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Impact-analysis service: report which apps consumed a RuleSet in the last 30 days.
 *
 * Return shape of analyzeImpactOnActivation():
 *   [
 *     'ruleSetId'    => string,
 *     'windowDays'   => int,
 *     'consumerApps' => [
 *       ['appId' => string, 'callCount' => int, 'lastCallAt' => string],
 *       ...
 *     ],
 *     'notification_recipients' => string[],
 *   ]
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#7.1
 */
class RuleImpactAnalysisService {

	/**
	 * How many days of log history to look back.
	 */
	public const WINDOW_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Analyse which apps/users have called a RuleSet in the last 30 days.
	 *
	 * Queries RuleExecutionLog entries for the given `$ruleSetId`, groups them
	 * by `userId` (treated as the app identifier in single-tenant usage; in
	 * multi-tenant setups the consuming app registers its own userId). Returns
	 * an ImpactReport array suitable for displaying to an operator before
	 * activating a new RuleSet version.
	 *
	 * @param string $ruleSetId The slug of the RuleSet to analyse.
	 * @param int $windowDays Number of days to look back (default 30).
	 *
	 * @return array{ruleSetId:string,windowDays:int,consumerApps:array<int,array{appId:string,callCount:int,lastCallAt:string}>,notification_recipients:string[]}
	 *
	 * @spec openspec/changes/business-rules-engine/tasks.md#7.1
	 */
	public function analyzeImpactOnActivation(string $ruleSetId, int $windowDays = self::WINDOW_DAYS): array {
		$logs = $this->fetchLogs(ruleSetId: $ruleSetId, windowDays: $windowDays);
		$grouped = $this->groupByApp(logs: $logs);
		$apps = $this->buildConsumerApps(grouped: $grouped);
		$uniqueIds = array_unique(array_column($apps, 'appId'));
		$recipients = array_values(array_filter($uniqueIds, fn (string $id): bool => $id !== ''));

		return [
			'ruleSetId' => $ruleSetId,
			'windowDays' => $windowDays,
			'consumerApps' => $apps,
			'notification_recipients' => $recipients,
		];

	}//end analyzeImpactOnActivation()

	/**
	 * Fetch RuleExecutionLog records for a RuleSet within the look-back window.
	 *
	 * @param string $ruleSetId The RuleSet slug.
	 * @param int $windowDays Number of days to look back.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchLogs(string $ruleSetId, int $windowDays): array {
		$since = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$windowDays} days"));

		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => RuleEngineService::REGISTER_SLUG,
						'schema' => RuleEngineService::EXECUTION_LOG_SCHEMA,
						'ruleSetId' => $ruleSetId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenBuild: RuleImpactAnalysisService cannot fetch logs',
				['ruleSetId' => $ruleSetId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		$logs = [];
		foreach ($results as $row) {
			$entry = $this->normalise(object: $row);
			if ($entry === []) {
				continue;
			}

			// Filter: only include entries within the window.
			$timestamp = (string)($entry['timestamp'] ?? '');
			if ($timestamp !== '' && $timestamp < $since) {
				continue;
			}

			$logs[] = $entry;
		}

		return $logs;
	}//end fetchLogs()

	/**
	 * Group log entries by the userId / appId field.
	 *
	 * @param array<int,array<string,mixed>> $logs The raw log entries.
	 *
	 * @return array<string,array{callCount:int,lastCallAt:string}>
	 */
	private function groupByApp(array $logs): array {
		$grouped = [];
		foreach ($logs as $entry) {
			$appId = (string)($entry['userId'] ?? '');
			$timestamp = (string)($entry['timestamp'] ?? '');

			if (isset($grouped[$appId]) === false) {
				$grouped[$appId] = ['callCount' => 0, 'lastCallAt' => $timestamp];
			}

			$grouped[$appId]['callCount']++;
			if ($timestamp > $grouped[$appId]['lastCallAt']) {
				$grouped[$appId]['lastCallAt'] = $timestamp;
			}
		}

		// Sort by callCount descending so the most active consumer is first.
		uasort($grouped, fn (array $a, array $b): int => $b['callCount'] <=> $a['callCount']);

		return $grouped;
	}//end groupByApp()

	/**
	 * Convert the grouped map into the consumerApps list format.
	 *
	 * @param array<string,array{callCount:int,lastCallAt:string}> $grouped Grouped entries.
	 *
	 * @return array<int,array{appId:string,callCount:int,lastCallAt:string}>
	 */
	private function buildConsumerApps(array $grouped): array {
		$apps = [];
		foreach ($grouped as $appId => $data) {
			$apps[] = [
				'appId' => $appId,
				'callCount' => $data['callCount'],
				'lastCallAt' => $data['lastCallAt'],
			];
		}

		return $apps;
	}//end buildConsumerApps()

	/**
	 * Coerce an OR result entry to a plain array.
	 *
	 * @param mixed $object The OR object / result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normalise()
}//end class
