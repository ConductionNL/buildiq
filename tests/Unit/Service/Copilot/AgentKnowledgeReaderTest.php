<?php

/**
 * Buildiq AgentKnowledgeReaderTest
 *
 * Reading an agent's knowledge files for its plan prompt.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-an-agents-plan-reads-its-knowledge-req-bqag-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use OCA\Buildiq\Service\Copilot\AgentKnowledgeReader;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers AgentKnowledgeReader.
 */
final class AgentKnowledgeReaderTest extends TestCase {

	/**
	 * OpenRegister's file service.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * The container the reader resolves the file service from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fileService = $this->createMock(FileService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->with(FileService::class)->willReturn($this->fileService);
	}//end setUp()

	/**
	 * Build the reader.
	 *
	 * @return AgentKnowledgeReader
	 */
	private function reader(): AgentKnowledgeReader {
		return new AgentKnowledgeReader(container: $this->container, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * Serve attached files by name from a map; a name not in it is not found.
	 *
	 * @param array<string, string> $files File name to content.
	 * @param string                $objectId The object id the reader must ask for.
	 *
	 * @return void
	 */
	private function serve(array $files, string $objectId = 'agent-uuid'): void {
		$this->fileService->method('getFile')->willReturnCallback(
			function (mixed $object = null, string|int $file = '') use ($files, $objectId): ?File {
				self::assertSame($objectId, $object);
				if (array_key_exists((string)$file, $files) === false) {
					return null;
				}

				$node = $this->createMock(File::class);
				$node->method('getContent')->willReturn($files[(string)$file]);
				return $node;
			}
		);
	}//end serve()

	/**
	 * An agent record with these knowledge file names.
	 *
	 * @param array<int, string> $names The file names, in order.
	 *
	 * @return array<string, mixed>
	 */
	private function agent(array $names): array {
		return [
			'@self' => ['id' => 'agent-uuid'],
			'id' => 'agent-uuid',
			'knowledge' => array_map(static fn (string $name): array => ['fileName' => $name, 'addedBy' => 'alice'], $names),
		];
	}//end agent()

	/**
	 * Files come back in the order of knowledge[], from the agent object.
	 *
	 * @return void
	 */
	public function testReadsTheFilesInKnowledgeOrder(): void {
		$this->serve(['b.md' => 'Labels are in sentence case.', 'a.txt' => 'Use Dutch.']);

		$files = $this->reader()->read(agent: $this->agent(['b.md', 'a.txt']));

		self::assertSame(
			[
				['fileName' => 'b.md', 'text' => 'Labels are in sentence case.'],
				['fileName' => 'a.txt', 'text' => 'Use Dutch.'],
			],
			$files
		);
	}//end testReadsTheFilesInKnowledgeOrder()

	/**
	 * An agent without knowledge never touches the file service.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutKnowledgeReadsNothing(): void {
		$this->fileService->expects(self::never())->method('getFile');

		self::assertSame([], $this->reader()->read(agent: ['id' => 'agent-uuid']));
		self::assertSame([], $this->reader()->read(agent: ['id' => 'agent-uuid', 'knowledge' => 'nope']));
	}//end testAnAgentWithoutKnowledgeReadsNothing()

	/**
	 * A file that would pass the budget is left out; the rest still fit.
	 *
	 * @return void
	 */
	public function testAFileOverTheBudgetIsLeftOut(): void {
		$this->serve(
			[
				'big.md' => str_repeat('a', 38000),
				'too-much.md' => str_repeat('b', 5000),
				'small.md' => str_repeat('c', 2000),
			]
		);

		$files = $this->reader()->read(agent: $this->agent(['big.md', 'too-much.md', 'small.md']));

		self::assertSame(['big.md', 'small.md'], array_column($files, 'fileName'));
	}//end testAFileOverTheBudgetIsLeftOut()

	/**
	 * Only text and Markdown are read; other types are not asked for.
	 *
	 * @return void
	 */
	public function testOnlyTextAndMarkdownAreRead(): void {
		$this->serve(['notes.MD' => 'x', 'handbook.pdf' => '%PDF-1.7']);

		$files = $this->reader()->read(agent: $this->agent(['handbook.pdf', 'notes.MD']));

		self::assertSame(['notes.MD'], array_column($files, 'fileName'));
		self::assertTrue(AgentKnowledgeReader::isReadable(fileName: 'a.markdown'));
		self::assertFalse(AgentKnowledgeReader::isReadable(fileName: 'a.docx'));
		self::assertFalse(AgentKnowledgeReader::isReadable(fileName: 'README'));
	}//end testOnlyTextAndMarkdownAreRead()

	/**
	 * A missing file, an unreadable one, an empty one and one not in UTF-8 are skipped.
	 *
	 * @return void
	 */
	public function testUnreadableFilesAreSkipped(): void {
		$this->fileService->method('getFile')->willReturnCallback(
			function (mixed $object = null, string|int $file = ''): ?File {
				if ($file === 'gone.md') {
					return null;
				}

				if ($file === 'locked.md') {
					throw new RuntimeException('not permitted');
				}

				$node = $this->createMock(File::class);
				$contents = ['empty.md' => "  \n", 'latin.txt' => "caf\xE9", 'ok.md' => 'kept'];
				$node->method('getContent')->willReturn($contents[$file]);
				return $node;
			}
		);

		$files = $this->reader()->read(agent: $this->agent(['gone.md', 'locked.md', 'empty.md', 'latin.txt', 'ok.md']));

		self::assertSame([['fileName' => 'ok.md', 'text' => 'kept']], $files);
	}//end testUnreadableFilesAreSkipped()

	/**
	 * Blank and repeated names are read once, and the id falls back to `id`.
	 *
	 * @return void
	 */
	public function testBlankAndRepeatedNamesAreReadOnce(): void {
		$this->serve(['a.md' => 'A'], objectId: '42');

		$files = $this->reader()->read(
			agent: [
				'id' => 42,
				'knowledge' => [['fileName' => ' '], ['fileName' => 'a.md'], ['fileName' => 'a.md'], 'loose', ['addedBy' => 'x']],
			]
		);

		self::assertSame([['fileName' => 'a.md', 'text' => 'A']], $files);
	}//end testBlankAndRepeatedNamesAreReadOnce()

	/**
	 * An agent record without any id reads nothing.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutAnIdReadsNothing(): void {
		$this->fileService->expects(self::never())->method('getFile');

		self::assertSame([], $this->reader()->read(agent: ['knowledge' => [['fileName' => 'a.md']]]));
	}//end testAnAgentWithoutAnIdReadsNothing()
}//end class
