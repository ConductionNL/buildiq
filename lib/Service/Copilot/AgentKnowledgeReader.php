<?php

/**
 * Buildiq AgentKnowledgeReader
 *
 * Reads the knowledge files of a builder agent for its plan prompt: every
 * entry of `Agent.knowledge[]`, in that order, read from the files attached
 * to the agent object in OpenRegister, so the files carry the object's own
 * access. Plain text and Markdown are read; other types wait for
 * OpenRegister's text read. The whole set stays within a character budget,
 * the same budget the agent editor refuses an upload over.
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
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Service\FileService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an agent's knowledge files into prompt-ready text.
 *
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
 */
class AgentKnowledgeReader {

	/**
	 * The most characters of knowledge one agent's prompt carries (design.md D2).
	 *
	 * @var integer
	 */
	public const BUDGET_CHARACTERS = 40000;

	/**
	 * File name extensions read as plain text.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Where OpenRegister's file service comes from, resolved on first read.
	 * @param LoggerInterface    $logger    Logger for files that cannot be read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a file of this name is read as knowledge.
	 *
	 * @param string $fileName The file name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
	 */
	public static function isReadable(string $fileName): bool {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		return in_array($extension, self::TEXT_EXTENSIONS, true);
	}//end isReadable()

	/**
	 * Read the agent's knowledge files, in the order of `knowledge[]`.
	 *
	 * A file that cannot be found or read, is not text, or would take the set
	 * past the budget is left out and not listed as used, so the run never
	 * names knowledge the model did not get.
	 *
	 * @param array<string, mixed> $agent The resolved `Agent` record.
	 *
	 * @return array<int, array{fileName: string, text: string}> The files read, in order.
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
	 */
	public function read(array $agent): array {
		$fileNames = self::fileNames(agent: $agent);
		if ($fileNames === []) {
			return [];
		}

		$objectId = self::objectId(agent: $agent);
		if ($objectId === null) {
			return [];
		}

		$files = [];
		$room = self::BUDGET_CHARACTERS;
		foreach ($fileNames as $fileName) {
			$text = $this->readText(objectId: $objectId, fileName: $fileName);
			if ($text === null) {
				continue;
			}

			$length = mb_strlen($text);
			if ($length > $room) {
				$this->logger->info('Buildiq agent knowledge: "' . $fileName . '" passes the knowledge budget and is left out of the prompt.');
				continue;
			}

			$room -= $length;
			$files[] = ['fileName' => $fileName, 'text' => $text];
		}

		return $files;
	}//end read()

	/**
	 * The knowledge file names of an agent, in order, without blanks or repeats.
	 *
	 * @param array<string, mixed> $agent The `Agent` record.
	 *
	 * @return array<int, string>
	 */
	private static function fileNames(array $agent): array {
		$knowledge = ($agent['knowledge'] ?? []);
		if (is_array($knowledge) === false) {
			return [];
		}

		$names = [];
		foreach ($knowledge as $entry) {
			$name = '';
			if (is_array($entry) === true && is_string($entry['fileName'] ?? null) === true) {
				$name = trim($entry['fileName']);
			}

			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end fileNames()

	/**
	 * The id of the agent object its files hang on.
	 *
	 * @param array<string, mixed> $agent The `Agent` record.
	 *
	 * @return string|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private static function objectId(array $agent): ?string {
		$uuid = ObjectRecord::uuid(record: $agent);
		if ($uuid !== null) {
			return $uuid;
		}

		$id = ($agent['id'] ?? null);
		if ((is_string($id) === true && $id !== '') || is_int($id) === true) {
			return (string)$id;
		}

		return null;
	}//end objectId()

	/**
	 * Read one attached file as text, or null when it is not readable text.
	 *
	 * @param string $objectId The agent object id.
	 * @param string $fileName The attached file name.
	 *
	 * @return string|null
	 */
	private function readText(string $objectId, string $fileName): ?string {
		if (self::isReadable(fileName: $fileName) === false) {
			return null;
		}

		try {
			$file = $this->container->get(FileService::class)->getFile(object: $objectId, file: $fileName);
			if ($file === null) {
				return null;
			}

			$content = $file->getContent();
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq agent knowledge: could not read "' . $fileName . '": ' . $e->getMessage());
			return null;
		}

		if (is_string($content) === false || trim($content) === '' || mb_check_encoding($content, 'UTF-8') === false) {
			return null;
		}

		return $content;
	}//end readText()
}//end class
