<?php

/**
 * Buildiq GitHubShopSearch
 *
 * Shapes the answer of one GitHub shop search: the catalogue's cards and hints,
 * or a fixed unreachable answer when the catalogue threw. Every outcome is
 * reported to integriq's connection registry when a reporter is present.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Service\Connection\ConnectionReporter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The answer and the connection report of ShopController::githubSearch().
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
 */
class GitHubShopSearch {

	/**
	 * Constructor.
	 *
	 * @param GitHubCatalogService    $catalogService     Fixed-host GitHub source, asked whether the broker is installed.
	 * @param LoggerInterface         $logger             PSR logger.
	 * @param ConnectionReporter|null $connectionReporter Tells integriq what a search met, or nothing when absent.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GitHubCatalogService $catalogService,
		private readonly LoggerInterface $logger,
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {
	}//end __construct()

	/**
	 * The response body for a search the catalogue could not answer.
	 *
	 * Logs the failure and reports the search as unreachable.
	 *
	 * @param Throwable $error What the catalogue threw.
	 *
	 * @return array<string,mixed> `{outcome, cards, brokerCredentialAvailable, rateLimited}`.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function failed(Throwable $error): array {
		$this->logger->error('Buildiq shop: GitHub search failed: ' . $error->getMessage());
		$this->connectionReporter?->reportGitHubSearch(
			outcome: GitHubCatalogService::OUTCOME_UNREACHABLE,
			brokerAvailable: $this->catalogService->isBrokerAvailable()
		);
		return [
			'outcome' => GitHubCatalogService::OUTCOME_UNREACHABLE,
			'cards' => [],
			'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
			'rateLimited' => false,
		];
	}//end failed()

	/**
	 * The response body for a search the catalogue answered.
	 *
	 * Reports the search's outcome first; the report never changes the body.
	 *
	 * @param array<string,mixed> $result GitHubCatalogService::search()'s answer.
	 *
	 * @return array<string,mixed> `{outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited}`.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function answered(array $result): array {
		$this->connectionReporter?->reportGitHubSearch(
			outcome: (string)$result['outcome'],
			brokerAvailable: $this->catalogService->isBrokerAvailable()
		);

		return [
			'outcome' => $result['outcome'],
			'cards' => $result['cards'],
			'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => $result['rateLimited'],
		];
	}//end answered()
}//end class
