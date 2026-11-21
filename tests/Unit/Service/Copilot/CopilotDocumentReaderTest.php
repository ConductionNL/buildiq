<?php

/**
 * CopilotDocumentReader unit test.
 *
 * A picked document is read from the requesting user's own folder: plain text
 * and Markdown by mimetype or extension, anything else refused naming the
 * accepted types, a file the user cannot read answered 404, and a document
 * over 60,000 characters refused without cutting it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use OCA\Buildiq\Exception\CopilotException;
use OCA\Buildiq\Service\Copilot\CopilotDocumentReader;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Service\Copilot\CopilotDocumentReader
 * @uses   \OCA\Buildiq\Exception\CopilotException
 */
final class CopilotDocumentReaderTest extends TestCase {

	/**
	 * A reader over a user folder answering getById() with these nodes.
	 *
	 * @param array<int, mixed> $nodes The nodes for any id.
	 * @param string            $user  The user whose folder is asked for.
	 *
	 * @return CopilotDocumentReader
	 */
	private function readerOver(array $nodes, string $user = 'alice'): CopilotDocumentReader {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(5)->willReturn($nodes);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with($user)->willReturn($folder);

		return new CopilotDocumentReader(rootFolder: $root);
	}//end readerOver()

	/**
	 * A file mock.
	 *
	 * @param string  $name     The file name.
	 * @param string  $mimetype The mimetype.
	 * @param string  $text     The content.
	 * @param boolean $readable Whether the user may read it.
	 *
	 * @return File&MockObject
	 */
	private function file(string $name, string $mimetype, string $text, bool $readable = true): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimetype')->willReturn($mimetype);
		$file->method('getSize')->willReturn(strlen($text));
		$file->method('getContent')->willReturn($text);
		$file->method('isReadable')->willReturn($readable);
		return $file;
	}//end file()

	/**
	 * Assert reading id 5 is refused with this code and status.
	 *
	 * @param CopilotDocumentReader $reader The reader.
	 * @param string                $code   The error code.
	 * @param integer               $status The HTTP status.
	 *
	 * @return CopilotException
	 */
	private function assertRefused(CopilotDocumentReader $reader, string $code, int $status): CopilotException {
		try {
			$reader->read(fileId: 5, userId: 'alice');
		} catch (CopilotException $e) {
			$this->assertSame($code, $e->getErrorCode());
			$this->assertSame($status, $e->getHttpStatus());
			return $e;
		}

		$this->fail('Expected the document to be refused with ' . $code . '.');
	}//end assertRefused()

	/**
	 * Plain text by mimetype comes back with its name and text.
	 *
	 * @return void
	 */
	public function testItReadsPlainText(): void {
		$reader = $this->readerOver([$this->file('Eisen.txt', 'text/plain', 'Aanvragers volgen hun aanvraag.')]);

		$this->assertSame(
			['fileId' => 5, 'name' => 'Eisen.txt', 'text' => 'Aanvragers volgen hun aanvraag.'],
			$reader->read(fileId: 5, userId: 'alice')
		);
	}//end testItReadsPlainText()

	/**
	 * Markdown served as octet-stream is read by its extension, any case.
	 *
	 * @return void
	 */
	public function testItReadsMarkdownByExtension(): void {
		$reader = $this->readerOver([$this->file('Eisen.MD', 'application/octet-stream', '# Eisen')]);

		$this->assertSame('# Eisen', $reader->read(fileId: 5, userId: 'alice')['text']);
	}//end testItReadsMarkdownByExtension()

	/**
	 * A node the user cannot read is skipped; with no other node the answer is 404.
	 *
	 * @return void
	 */
	public function testAFileTheUserCannotReadIsNotFound(): void {
		$e = $this->assertRefused($this->readerOver([$this->file('Geheim.txt', 'text/plain', 'x', readable: false)]), 'not_found', 404);
		$this->assertSame('The document could not be found.', $e->getMessage());
	}//end testAFileTheUserCannotReadIsNotFound()

	/**
	 * A folder with the id is not a document.
	 *
	 * @return void
	 */
	public function testAFolderIsNotFound(): void {
		$this->assertRefused($this->readerOver([$this->createMock(Folder::class)]), 'not_found', 404);
	}//end testAFolderIsNotFound()

	/**
	 * A failing lookup answers the same 404, saying nothing more.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsNotFound(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new RuntimeException('storage gone'));

		$e = $this->assertRefused(new CopilotDocumentReader(rootFolder: $root), 'not_found', 404);
		$this->assertStringNotContainsString('storage', $e->getMessage());
	}//end testAFailingLookupIsNotFound()

	/**
	 * A content read the storage refuses is 404 too.
	 *
	 * @return void
	 */
	public function testARefusedContentReadIsNotFound(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Eisen.txt');
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('getSize')->willReturn(10);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willThrowException(new NotPermittedException('no'));

		$this->assertRefused($this->readerOver([$file]), 'not_found', 404);
	}//end testARefusedContentReadIsNotFound()

	/**
	 * A Word document is refused naming the accepted types.
	 *
	 * @return void
	 */
	public function testAWordDocumentIsRefusedNamingTheAcceptedTypes(): void {
		$e = $this->assertRefused(
			$this->readerOver([$this->file('Eisen.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PK')]),
			'unsupported_document',
			422
		);
		$this->assertSame(['acceptedTypes' => ['txt', 'md', 'markdown']], $e->getContext());
	}//end testAWordDocumentIsRefusedNamingTheAcceptedTypes()

	/**
	 * Exactly 60,000 characters is read; one more is refused, never cut.
	 *
	 * @return void
	 */
	public function testTheLimitIsSixtyThousandCharacters(): void {
		$this->assertSame(60000, mb_strlen($this->readerOver([$this->file('a.txt', 'text/plain', str_repeat('ü', 60000))])->read(fileId: 5, userId: 'alice')['text']));

		$e = $this->assertRefused($this->readerOver([$this->file('a.txt', 'text/plain', str_repeat('a', 60001))]), 'document_too_long', 422);
		$this->assertSame(['maxCharacters' => 60000], $e->getContext());
	}//end testTheLimitIsSixtyThousandCharacters()

	/**
	 * A file too large to hold 60,000 characters is refused without reading it.
	 *
	 * @return void
	 */
	public function testAHugeFileIsRefusedUnread(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('big.txt');
		$file->method('getMimetype')->willReturn('text/plain');
		$file->method('isReadable')->willReturn(true);
		$file->method('getSize')->willReturn(240001);
		$file->expects($this->never())->method('getContent');

		$this->assertRefused($this->readerOver([$file]), 'document_too_long', 422);
	}//end testAHugeFileIsRefusedUnread()

	/**
	 * Text that is not UTF-8, and an empty document, are refused.
	 *
	 * @return void
	 */
	public function testNonUtf8AndEmptyDocumentsAreRefused(): void {
		$e = $this->assertRefused($this->readerOver([$this->file('a.txt', 'text/plain', "\xC3\x28")]), 'unsupported_document', 422);
		$this->assertStringContainsString('UTF-8', $e->getMessage());

		$e = $this->assertRefused($this->readerOver([$this->file('a.txt', 'text/plain', "  \n")]), 'invalid_arguments', 422);
		$this->assertSame('This document is empty.', $e->getMessage());
	}//end testNonUtf8AndEmptyDocumentsAreRefused()
}//end class
