<?php

/**
 * Buildiq CopilotDocumentReader
 *
 * Reads the requirements document a maker picked in "Generate with AI", as
 * that maker: the file is looked up in their own user folder, so a file they
 * cannot read is not found and nothing else is said about it. Plain text and
 * Markdown are read directly. PDF, Word and OpenDocument text are read through
 * OpenRegister's published file text read
 * (`OCA\OpenRegister\Contract\FileTextReaderInterface`, openregister change
 * a-file-text-read-for-consuming-apps), resolved by name so buildiq stays
 * installable on an OpenRegister without it; there they are refused with a
 * message naming the accepted types. A document longer than
 * {@see self::MAX_CHARACTERS} is refused, never cut
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
use Psr\Container\ContainerInterface;
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
	 * Document types OpenRegister's file text read serves, by mimetype.
	 *
	 * @var string[]
	 */
	private const DOCUMENT_MIMETYPES = [
		'application/pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
		'application/vnd.oasis.opendocument.text',
	];

	/**
	 * Document types OpenRegister's file text read serves, by extension.
	 *
	 * @var string[]
	 */
	private const DOCUMENT_EXTENSIONS = ['pdf', 'docx', 'doc', 'odt'];

	/**
	 * Largest PDF, Word or OpenDocument file handed to the text read (20 MiB).
	 *
	 * @var integer
	 */
	private const MAX_DOCUMENT_BYTES = 20971520;

	/**
	 * OpenRegister's published file text read, by name (ADR-041: no hard dependency).
	 *
	 * @var string
	 */
	public const FILE_TEXT_READER = 'OCA\\OpenRegister\\Contract\\FileTextReaderInterface';

	/**
	 * Constructor.
	 *
	 * @param IRootFolder             $rootFolder Where the requesting user's folder comes from.
	 * @param ContainerInterface|null $container  Where OpenRegister's file text read comes from, when it has one.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ?ContainerInterface $container=null,
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
		if ($this->isDocument(file: $file) === true) {
			$text = $this->checkedText(text: $this->readDocument(file: $file));

			return ['fileId' => $fileId, 'name' => $name, 'text' => $text];
		}

		$this->assertReadable(file: $file);
		$text = $this->readText(file: $file);

		return ['fileId' => $fileId, 'name' => $name, 'text' => $text];
	}//end read()

	/**
	 * Refuse a document this reader cannot take: not plain text, or too big.
	 *
	 * @param File $file The picked file.
	 *
	 * @return void
	 *
	 * @throws CopilotException (422) For an unsupported type or a document that is too long.
	 */
	private function assertReadable(File $file): void {
		if ($this->isPlainText(file: $file) === false) {
			throw $this->unsupported();
		}

		if ($file->getSize() > self::MAX_BYTES) {
			throw $this->tooLong();
		}
	}//end assertReadable()

	/**
	 * The document's text: UTF-8, within the character cap, not empty.
	 *
	 * @param File $file The picked file.
	 *
	 * @return string
	 *
	 * @throws CopilotException (404, 422) When the file cannot be read or its text cannot be used.
	 */
	private function readText(File $file): string {
		try {
			$text = (string)$file->getContent();
		} catch (Throwable $e) {
			throw $this->notFound();
		}

		return $this->checkedText(text: $text);
	}//end readText()

	/**
	 * The text of a PDF, Word or OpenDocument file, through OpenRegister's file text read.
	 *
	 * @param File $file The picked file.
	 *
	 * @return string The text; empty when the file holds none.
	 *
	 * @throws CopilotException (422) When this OpenRegister cannot read it, it is too big, or the read fails.
	 *
	 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-copilot/spec.md#requirement-a-document-is-read-whole-in-a-type-the-server-can-read-req-bqch-008
	 */
	private function readDocument(File $file): string {
		$reader = $this->fileTextReader();
		if ($reader === null) {
			throw $this->unsupported();
		}

		if ($file->getSize() > self::MAX_DOCUMENT_BYTES) {
			throw new CopilotException(
				errorCode: 'document_too_long',
				message: 'This document is larger than 20 MB. Shorten it or split it, then pick it again.',
				httpStatus: 422,
				context: ['maxCharacters' => self::MAX_CHARACTERS]
			);
		}

		try {
			return (string)$reader->readFileText(file: $file);
		} catch (Throwable $e) {
			throw new CopilotException(
				errorCode: 'unsupported_document',
				message: 'This document could not be read. Save it again as PDF, Word, OpenDocument, plain text or Markdown and pick it again.',
				httpStatus: 422
			);
		}
	}//end readDocument()

	/**
	 * OpenRegister's file text read, or null when this OpenRegister does not publish it.
	 *
	 * @return object|null
	 */
	private function fileTextReader(): ?object {
		if ($this->container === null || interface_exists(self::FILE_TEXT_READER) === false) {
			return null;
		}

		try {
			$reader = $this->container->get(self::FILE_TEXT_READER);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($reader) === false || method_exists($reader, 'readFileText') === false) {
			return null;
		}

		return $reader;
	}//end fileTextReader()

	/**
	 * Text that the plan prompt can take: UTF-8, within the character cap, not empty.
	 *
	 * @param string $text The document text.
	 *
	 * @return string
	 *
	 * @throws CopilotException (422) When the text cannot be used.
	 */
	private function checkedText(string $text): string {
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

		return $text;
	}//end checkedText()

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
	 * Whether the file is a PDF, Word or OpenDocument text document.
	 *
	 * @param File $file The file.
	 *
	 * @return boolean
	 */
	private function isDocument(File $file): bool {
		if (in_array(strtolower($file->getMimetype()), self::DOCUMENT_MIMETYPES, true) === true) {
			return true;
		}

		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		return in_array($extension, self::DOCUMENT_EXTENSIONS, true);
	}//end isDocument()

	/**
	 * The answer for a type this server cannot read, naming the types it can.
	 *
	 * @return CopilotException
	 */
	private function unsupported(): CopilotException {
		if ($this->fileTextReader() === null) {
			return new CopilotException(
				errorCode: 'unsupported_document',
				message: 'This document type cannot be read yet. Use a plain text (.txt) or Markdown (.md) file.',
				httpStatus: 422,
				context: ['acceptedTypes' => self::TEXT_EXTENSIONS]
			);
		}

		return new CopilotException(
			errorCode: 'unsupported_document',
			message: 'This document type cannot be read. Use a PDF, Word (.docx), OpenDocument (.odt), plain text (.txt) or Markdown (.md) file.',
			httpStatus: 422,
			context: ['acceptedTypes' => array_merge(self::DOCUMENT_EXTENSIONS, self::TEXT_EXTENSIONS)]
		);
	}//end unsupported()

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
