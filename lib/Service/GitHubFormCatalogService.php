<?php

/**
 * Buildiq GitHubFormCatalogService
 *
 * The GitHub source of the form library (reuse-gallery-categories-and-form-library,
 * REQ-BQGL-005): repositories with the topic `buildiq-form` and a `form.json` at
 * their root holding a form export envelope. Every request goes through
 * GitHubCatalogService, so the fixed host, the path validation and the optional
 * broker upgrade are the app search's own. Results are cached short-TTL under
 * their own namespace.
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
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * GitHub search for shared forms, built on the shop's GitHub source.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
class GitHubFormCatalogService {

	/**
	 * The GitHub topic a shared form repository carries (REQ-BQGL-005).
	 *
	 * @var string
	 */
	private const FORM_TOPIC = 'topic:buildiq-form';

	/**
	 * The file at a form repository's root holding the form export envelope.
	 *
	 * @var string
	 */
	private const FORM_FILE = 'form.json';

	/**
	 * The envelope kind of a form export (src/services/formExport.js).
	 *
	 * @var string
	 */
	private const FORM_EXPORT_KIND = 'form-template';

	/**
	 * Cache namespace for form search results.
	 *
	 * @var string
	 */
	private const CACHE_NS = 'buildiq_github_forms';

	/**
	 * Search-result cache TTL (seconds), the app search's.
	 *
	 * @var integer
	 */
	private const SEARCH_TTL = 60;

	/**
	 * Short-TTL distributed cache, or null when no backend is available.
	 *
	 * @var ICache|null
	 */
	private readonly ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param GitHubCatalogService $catalog      The shop's fixed-host GitHub source.
	 * @param ICacheFactory        $cacheFactory Cache factory for search results.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
	 */
	public function __construct(
		private readonly GitHubCatalogService $catalog,
		ICacheFactory $cacheFactory,
	) {
		$cache = null;
		if ($cacheFactory->isAvailable() === true) {
			$cache = $cacheFactory->createDistributed(self::CACHE_NS);
		}

		$this->cache = $cache;
	}//end __construct()

	/**
	 * Search GitHub for shared forms: repositories with the `buildiq-form`
	 * topic and a `form.json` at their root holding a form export envelope
	 * (REQ-BQGL-005). Cached like the app search, under its own key.
	 *
	 * A repository whose form.json is missing or is not a form export is
	 * listed as not installable rather than hidden, so a publisher can see
	 * why their form does not install.
	 *
	 * @param string|null $query Free-text search, added to the topic.
	 * @param string|null $actingUserId The signed-in user.
	 * @param string|null $credentialId An OpenRegister broker credential, if any.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>, brokerUsed: bool, rateLimited: bool}
	 *
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
	 */
	public function searchForms(?string $query, ?string $actingUserId, ?string $credentialId = null): array {
		$term = trim((string)$query);
		$cacheKey = 'forms:' . md5(strtolower($term) . '|' . ((string)$credentialId));

		$cached = $this->cacheGet(key: $cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$page = $this->catalog->fetchTopic(
			topic: self::FORM_TOPIC,
			term: $term,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($page['ok'] === false) {
			$failure = GitHubCatalogService::OUTCOME_UNREACHABLE;
			if ($page['rateLimited'] === true) {
				$failure = GitHubCatalogService::OUTCOME_RATE_LIMITED;
			}

			return ['outcome' => $failure, 'cards' => [], 'brokerUsed' => $page['brokerUsed'], 'rateLimited' => $page['rateLimited']];
		}

		$cards = [];
		foreach ($page['items'] as $item) {
			$card = $this->buildFormCard(item: $item, actingUserId: $actingUserId, credentialId: $credentialId);
			if ($card !== null) {
				$cards[] = $card;
			}
		}

		$payload = [
			'outcome' => GitHubCatalogService::OUTCOME_OK,
			'cards' => $cards,
			'brokerUsed' => $page['brokerUsed'],
			'rateLimited' => $page['rateLimited'],
		];
		$this->cacheSet(key: $cacheKey, value: $payload, ttl: self::SEARCH_TTL);

		return $payload;
	}//end searchForms()

	/**
	 * One form card: the repository, and the form export its form.json holds.
	 *
	 * @param array<string, mixed> $item A search API repository item.
	 * @param string|null $actingUserId The signed-in user.
	 * @param string|null $credentialId An OpenRegister broker credential, if any.
	 *
	 * @return array<string, mixed>|null The card, or null for an item without an owner and name.
	 *
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
	 */
	private function buildFormCard(array $item, ?string $actingUserId, ?string $credentialId): ?array {
		$owner = (string)($item['owner']['login'] ?? '');
		$repo = (string)($item['name'] ?? '');
		if ($this->catalog->validRepo(owner: $owner, repo: $repo, ref: null) === false) {
			return null;
		}

		$ref = (string)($item['default_branch'] ?? '');
		$contents = $this->catalog->fetchFileContents(
			owner: $owner,
			repo: $repo,
			path: self::FORM_FILE,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);

		$export = null;
		if ($contents !== null) {
			$decoded = json_decode($contents, true);
			if (is_array($decoded) === true
				&& ($decoded['kind'] ?? null) === self::FORM_EXPORT_KIND
				&& is_array($decoded['form'] ?? null) === true
			) {
				$export = $decoded;
			}
		}

		$form = [];
		if ($export !== null) {
			$form = $export['form'];
		}

		return [
			'owner' => $owner,
			'repo' => $repo,
			'ref' => $ref,
			'name' => (string)($form['name'] ?? $repo),
			'description' => (string)($form['description'] ?? ($item['description'] ?? '')),
			'category' => (string)($form['category'] ?? ''),
			'publisher' => (string)($form['publisher'] ?? $owner),
			'stars' => (int)($item['stargazers_count'] ?? 0),
			'htmlUrl' => (string)($item['html_url'] ?? ''),
			'installable' => $export !== null,
			'export' => $export,
		];
	}//end buildFormCard()

	/**
	 * Read a value from the cache (null on a miss or without a backend).
	 *
	 * @param string $key The cache key.
	 *
	 * @return mixed
	 */
	private function cacheGet(string $key): mixed {
		if ($this->cache === null) {
			return null;
		}

		return $this->cache->get($key);
	}//end cacheGet()

	/**
	 * Write a value to the cache (no-op without a backend).
	 *
	 * @param string $key   The cache key.
	 * @param mixed  $value The value.
	 * @param int    $ttl   The TTL in seconds.
	 *
	 * @return void
	 */
	private function cacheSet(string $key, mixed $value, int $ttl): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($key, $value, $ttl);
	}//end cacheSet()
}//end class
