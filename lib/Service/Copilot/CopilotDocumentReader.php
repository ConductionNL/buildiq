<?php

/**
 * Buildiq CopilotDocumentReader
 *
 * Reads the requirements document a maker picked in "Generate with AI", as
 * that maker: the file is looked up in their own user folder, so a file they
 * cannot read is not found and nothing else is said about it. Plain text and
 * Markdown are read directly; other types are refused with a message naming
 * the accepted ones until OpenRegister serves their text. A document longer
 * than {@see self::MAX_CHARACTERS} is refused, never cut
 * (ai-copilot-documents-and-code-help D6).
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
 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-copilot/spec.md#requirement-the-wizard-reads-a-document-from-files-req-bqch-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use OCA\Buildiq\Exception\CopilotException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * Opens a picked document as the requesting user and returns its text.
 *
 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-copilot/spec.md#requirement-the-wizard-reads-a-document-from-files-req-bqch-005
 */
class CopilotDocumentReader {

	/**
	 * Longest document text the plan prompt takes.
	 *
	 * @var integer
	 */
	public const MAX_CHARACTERS = 60000;

	/**
	 * Most bytes a UTF-8 text of MAX_CHARACTERS can take; a larger file is refused unread.
	 *
	 * @var integer
	 */
	private const MAX_BYTES = (self::MAX_CHARACTERS * 4);

	/**
	 * Text types read directly, by mimetype.
	 *
	 * @var string[]
	 */
	private const TEXT_MIMETYPES = ['text/plain', 'text/markdown', 'text/x-markdown'];

	/**
	 * Text types read directly, by extension (a .md file is often served as octet-stream).
	 *
	 * @var string[]
	 */
	private const TEXT_EXTENSIONS = ['txt', 'md', 'markdown'];

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Where the requesting user's folder comes from.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * Read a document the user can read.
	 *
	 * @param integer $fileId The picked file's id.
	 * @param string  $userId The requesting user.
	 *
	 * @return array{fileId: int, name: string, text: string}
	 *
	 * @throws CopilotException (404 not_found, 422 unsupported_document, 422 document_too_long).
	 *
	 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-copilot/spec.md#requirement-the-wizard-reads-a-document-from-files-req-bqch-005
	 */
	public function read(int $fileId, string $userId): array {
		$file = $this->findFile(fileId: $fileId, userId: $userId);
		$name = $file->getName();

		if ($this->isPlainText(file: $file) === false) {
			throw new CopilotException(
				errorCode: 'unsupported_document',
				message: 'This document type cannot be read yet. Use a plain text (.txt) or Markdown (.md) file.',
				httpStatus: 422,
				context: ['acceptedTypes' => self::TEXT_EXTENSIONS]
			);
		}

		if ($file->getSize() > self::MAX_BYTES) {
			throw $this->tooLong();
		}

		try {
			$text = (string)$file->getContent();
		} catch (Throwable $e) {
			throw $this->notFound();
		}

		if (mb_check_encoding($text, 'UTF-8') === false) {
			throw new CopilotException(
				errorCode: 'unsupported_document',
				message: 'This document is not UTF-8 text. Save it as UTF-8 and pick it again.',
				httpStatus: 422
			);
		}

		if (mb_strlen($text) > self::MAX_CHARACTERS) {
			throw $this->tooLong();
		}

		if (trim($text) === '') {
			throw new CopilotException(
				errorCode: 'invalid_arguments',
				message: 'This document is empty.',
				httpStatus: 422
			);
		}

		return ['fileId' => $fileId, 'name' => $name, 'text' => $text];
	}//end read()

	/**
	 * The file with this id in the user's own folder.
	 *
	 * @param integer $fileId The file id.
	 * @param string  $userId The requesting user.
	 *
	 * @return File
	 *
	 * @throws CopilotException (404) When the user cannot read such a file.
	 */
	private function findFile(int $fileId, string $userId): File {
		try {
			$nodes = $this->rootFolder->getUserFolder($userId)->getById($fileId);
		} catch (Throwable $e) {
			throw $this->notFound();
		}

		foreach ($nodes as $node) {
			if ($node instanceof File && $node->isReadable() === true) {
				return $node;
			}
		}

		throw $this->notFound();
	}//end findFile()

	/**
	 * Whether the file is plain text or Markdown.
	 *
	 * @param File $file The file.
	 *
	 * @return boolean
	 */
	private function isPlainText(File $file): bool {
		if (in_array(strtolower($file->getMimetype()), self::TEXT_MIMETYPES, true) === true) {
			return true;
		}

		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		return in_array($extension, self::TEXT_EXTENSIONS, true);
	}//end isPlainText()

	/**
	 * The one answer for a file the user cannot read.
	 *
	 * @return CopilotException
	 */
	private function notFound(): CopilotException {
		return new CopilotException(errorCode: 'not_found', message: 'The document could not be found.', httpStatus: 404);
	}//end notFound()

	/**
	 * The answer for a document over the limit.
	 *
	 * @return CopilotException
	 */
	private function tooLong(): CopilotException {
		return new CopilotException(
			errorCode: 'document_too_long',
			message: 'This document is longer than 60,000 characters. Shorten it or split it, then pick it again.',
			httpStatus: 422,
			context: ['maxCharacters' => self::MAX_CHARACTERS]
		);
	}//end tooLong()
}//end class
