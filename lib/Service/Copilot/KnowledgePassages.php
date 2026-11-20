<?php

/**
 * Buildiq KnowledgePassages
 *
 * Ranked passages from an agent's knowledge files, through OpenRegister's
 * public vector search facade (ai-agents-knowledge-and-run-trace D2, T08).
 * The facade searches as the calling user; only rows of the agent's own
 * knowledge files are kept, best first, until the knowledge budget is used.
 * When the facade is absent, not configured, fails or finds nothing in those
 * files, the caller reads the whole files instead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks the vector facade for the passages of an agent's knowledge that match a request.
 *
 * @spec openspec/changes/archive/2026-11-20-ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
 */
final class KnowledgePassages {

	/**
	 * OpenRegister's public vector search facade, resolved by name so buildiq
	 * loads on an OpenRegister without it.
	 *
	 * @var string
	 */
	public const FACADE = 'OCA\\OpenRegister\\Service\\Search\\VectorSearchFacade';

	/**
	 * The most rows one search asks for (the facade's own cap).
	 *
	 * @var integer
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * What separates two passages of one file in the prompt.
	 *
	 * @var string
	 */
	public const SEPARATOR = "\n\n[...]\n\n";

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Where the facade comes from, resolved on use.
	 * @param LoggerInterface    $logger    Logs why retrieval fell back.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether ranked retrieval can answer: the facade is there and reports itself available.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-20-ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
	 */
	public function available(): bool {
		return $this->facade() !== null;
	}//end available()

	/**
	 * The passages of these files that best match the request, within the budget.
	 *
	 * @param string                                          $query  The request the agent plans for.
	 * @param array<int, array{fileName: string, fileId: string}> $files  The agent's knowledge files.
	 * @param int                                             $budget The most characters to return.
	 *
	 * @return array<int, array{fileName: string, text: string}>|null The passages per file, best file first;
	 *                                                                null when retrieval cannot answer.
	 *
	 * @spec openspec/changes/archive/2026-11-20-ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
	 */
	public function rank(string $query, array $files, int $budget): ?array {
		$query = trim($query);
		if ($query === '' || $files === []) {
			return null;
		}

		$facade = $this->facade();
		if ($facade === null) {
			return null;
		}

		$names = [];
		foreach ($files as $file) {
			$names[(string)$file['fileId']] = $file['fileName'];
		}

		try {
			$rows = $facade->searchHybrid(query: $query, limit: self::SEARCH_LIMIT);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq agent knowledge: vector search failed, reading whole files: ' . $e->getMessage());
			return null;
		}

		$passages = self::passages(rows: (array)$rows, names: $names, budget: $budget);
		if ($passages === []) {
			return null;
		}

		$out = [];
		foreach ($passages as $fileName => $chunks) {
			$out[] = ['fileName' => $fileName, 'text' => implode(self::SEPARATOR, $chunks)];
		}

		return $out;
	}//end rank()

	/**
	 * The chunks of the knowledge files among the rows, in rank order, per file, within the budget.
	 *
	 * A chunk that does not fit the room left is skipped, so a shorter one
	 * further down can still be used.
	 *
	 * @param array<int|string, mixed> $rows   The facade rows, best first.
	 * @param array<string, string>    $names  File id to file name.
	 * @param int                      $budget The most characters.
	 *
	 * @return array<string, list<string>>
	 */
	private static function passages(array $rows, array $names, int $budget): array {
		$room = $budget;
		$passages = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['entity_type'] ?? '') !== 'file') {
				continue;
			}

			$fileName = ($names[(string)($row['entity_id'] ?? '')] ?? null);
			$chunk = trim((string)($row['chunk_text'] ?? ''));
			$length = mb_strlen($chunk);
			if ($fileName === null || $chunk === '' || $length > $room) {
				continue;
			}

			$room -= $length;
			$passages[$fileName][] = $chunk;
		}

		return $passages;
	}//end passages()

	/**
	 * The facade when it is registered and available, else null.
	 *
	 * @return object|null
	 */
	private function facade(): ?object {
		try {
			$facade = $this->container->get(self::FACADE);
			if (is_object($facade) === false
				|| method_exists($facade, 'isAvailable') === false
				|| method_exists($facade, 'searchHybrid') === false
			) {
				return null;
			}

			if ($facade->isAvailable() !== true) {
				return null;
			}

			return $facade;
		} catch (Throwable $e) {
			$this->logger->debug('Buildiq agent knowledge: no vector search facade: ' . $e->getMessage());
			return null;
		}
	}//end facade()
}//end class
