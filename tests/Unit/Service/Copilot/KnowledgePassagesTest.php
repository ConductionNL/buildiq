<?php

/**
 * Unit tests for KnowledgePassages: when ranked retrieval answers, and when it falls back.
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

use OCA\Buildiq\Service\Copilot\KnowledgePassages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The facade answers, is absent, fails, or finds nothing.
 */
#[CoversClass(KnowledgePassages::class)]
final class KnowledgePassagesTest extends TestCase {

	/**
	 * Passages over a container serving this facade (or throwing it).
	 *
	 * @param mixed $facade The facade, or a Throwable the container throws.
	 *
	 * @return KnowledgePassages
	 */
	private function passages(mixed $facade): KnowledgePassages {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($facade): mixed {
				self::assertSame(KnowledgePassages::FACADE, $id);
				if ($facade instanceof \Throwable) {
					throw $facade;
				}

				return $facade;
			}
		);

		return new KnowledgePassages(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end passages()

	/**
	 * A facade that answers these rows, or throws on search.
	 *
	 * @param array<int, array<string, mixed>>|null $rows Rows, or null to throw.
	 *
	 * @return object
	 */
	private static function facade(?array $rows): object {
		return new class ($rows) {
			/**
			 * @param array<int, array<string, mixed>>|null $rows Rows.
			 */
			public function __construct(private ?array $rows) {
			}

			/**
			 * @return bool
			 */
			public function isAvailable(): bool {
				return true;
			}

			/**
			 * @param string $query The query.
			 * @param int    $limit The limit.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchHybrid(string $query, int $limit = 10): array {
				if ($this->rows === null) {
					throw new RuntimeException('index down');
				}

				return $this->rows;
			}
		};
	}//end facade()

	/**
	 * Rows of the named files come back per file, within the budget.
	 *
	 * @return void
	 */
	public function testRanksPassagesOfTheNamedFilesWithinTheBudget(): void {
		$passages = $this->passages(
			facade: self::facade(
				rows: [
					['entity_type' => 'file', 'entity_id' => '5', 'chunk_text' => 'abcdef'],
					['entity_type' => 'file', 'entity_id' => '5', 'chunk_text' => 'ghijklmnop'],
					['entity_type' => 'file', 'entity_id' => '5', 'chunk_text' => 'qr'],
				]
			)
		);

		self::assertTrue($passages->available());
		self::assertSame(
			[['fileName' => 'a.md', 'text' => "abcdef\n\n[...]\n\nqr"]],
			$passages->rank(query: 'q', files: [['fileName' => 'a.md', 'fileId' => '5']], budget: 9)
		);
	}//end testRanksPassagesOfTheNamedFilesWithinTheBudget()

	/**
	 * No facade, a failing search, no match, no request or no files all answer null.
	 *
	 * @return void
	 */
	public function testFallsBackWhenRetrievalCannotAnswer(): void {
		$files = [['fileName' => 'a.md', 'fileId' => '5']];

		$absent = $this->passages(facade: new RuntimeException('no service'));
		self::assertFalse($absent->available());
		self::assertNull($absent->rank(query: 'q', files: $files, budget: 100));

		self::assertFalse($this->passages(facade: new \stdClass())->available());
		self::assertNull($this->passages(facade: self::facade(rows: null))->rank(query: 'q', files: $files, budget: 100));
		self::assertNull($this->passages(facade: self::facade(rows: [['entity_type' => 'file', 'entity_id' => '6', 'chunk_text' => 'x']]))->rank(query: 'q', files: $files, budget: 100));
		self::assertNull($this->passages(facade: self::facade(rows: []))->rank(query: ' ', files: $files, budget: 100));
		self::assertNull($this->passages(facade: self::facade(rows: []))->rank(query: 'q', files: [], budget: 100));
	}//end testFallsBackWhenRetrievalCannotAnswer()
}//end class
