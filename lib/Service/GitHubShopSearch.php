<?php

/**
 * Buildiq GitHubShopSearch
 *
 * Runs one GitHub shop search and shapes its answer: the catalogue's cards and
 * hints, or a fixed unreachable answer when the catalogue throws. Every outcome
 * is reported to integriq's connection registry when a reporter is present.
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
 * The GitHub shop search behind ShopController::githubSearch().
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
 */
class GitHubShopSearch {

	/**
	 * Constructor.
	 *
	 * @param GitHubCatalogService    $catalogService     Fixed-host GitHub source.
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
	 * Search GitHub for `topic:buildiq-app` repos and shape the response body.
	 *
	 * The outcome is reported to integriq's connection registry, at most once an
	 * hour while it stays the same. The report never changes the body.
	 *
	 * @param string|null $query        The search text, or null for every app.
	 * @param string      $actingUserId The signed-in user.
	 * @param string|null $credentialId Optional broker credential UUID.
	 *
	 * @return array<string,mixed> `{outcome, cards, brokerCredentialAvailable, rateLimited}`, plus `brokerUsed` on success.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function search(?string $query, string $actingUserId, ?string $credentialId): array {
		try {
			$result = $this->catalogService->search(
				query: $query,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq shop: GitHub search failed: ' . $e->getMessage());
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
		}

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
	}//end search()
}//end class
