<?php

/**
 * Failure-path tests for the RenameAgentSchemaSlug repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
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

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\RenameAgentSchemaSlug;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Repair\RenameAgentSchemaSlug
 */
class RenameAgentSchemaSlugFailuresTest extends TestCase {
	/**
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);

	}//end setUp()

	/**
	 * Answer the schema lookups per slug.
	 *
	 * @param array<string, array<int, int>> $ids Slug => schema ids.
	 *
	 * @return void
	 */
	private function schemas(array $ids): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($ids): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($ids[(string)$params[0]] ?? []);
				return $result;
			}
		);

	}//end schemas()

	/**
	 * Build the step.
	 *
	 * @return RenameAgentSchemaSlug
	 */
	private function step(): RenameAgentSchemaSlug {
		return new RenameAgentSchemaSlug($this->db, $this->logger);

	}//end step()

	/**
	 * The step says why it renames.
	 *
	 * @return void
	 */
	public function testTheNameSaysWhyItRenames(): void {
		$this->assertSame(
			'Namespace the buildiq agent schema slug so it stops colliding with hermiq',
			$this->step()->getName()
		);

	}//end testTheNameSaysWhyItRenames()

	/**
	 * An unreadable schema table leaves the slug alone.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaTableLeavesTheSlugAlone(): void {
		$this->db->method('executeQuery')->willThrowException(new DbException('no table'));
		$this->db->expects($this->never())->method('executeStatement');

		$warned = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warned): void {
				$warned[] = [$message, $context];
			}
		);
		$this->output->expects($this->once())->method('info')
			->with('RenameAgentSchemaSlug: schema table unreadable; leaving the slug alone.');

		$this->step()->run($this->output);

		$this->assertSame(
			[
				['RenameAgentSchemaSlug: could not read the schema table; skipping.', ['slug' => 'agent', 'exception' => 'no table']],
				['RenameAgentSchemaSlug: could not read the schema table; skipping.', ['slug' => 'buildAgent', 'exception' => 'no table']],
			],
			$warned
		);

	}//end testAnUnreadableSchemaTableLeavesTheSlugAlone()

	/**
	 * Duplicate old slugs are logged with their ids and nothing is renamed.
	 *
	 * @return void
	 */
	public function testDuplicateOldSlugsAreLoggedWithTheirIds(): void {
		$this->schemas(ids: ['agent' => [11, 12]]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->logger->expects($this->once())->method('warning')
			->with('RenameAgentSchemaSlug: duplicate slugs; refusing to guess.', ['ids' => [11, 12]]);
		$this->output->expects($this->once())->method('warning')
			->with('RenameAgentSchemaSlug: duplicate `agent` schemas; refusing to guess.');

		$this->step()->run($this->output);

	}//end testDuplicateOldSlugsAreLoggedWithTheirIds()

	/**
	 * A failing rename is logged as an error and reported.
	 *
	 * @return void
	 */
	public function testAFailingRenameIsLoggedAndReported(): void {
		$this->schemas(ids: ['agent' => [11]]);
		$this->db->expects($this->once())->method('executeStatement')
			->with('UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?', ['buildAgent', 11])
			->willThrowException(new DbException('read only'));

		$this->logger->expects($this->once())->method('error')
			->with(
				'RenameAgentSchemaSlug: slug rename failed; the import will create a second schema.',
				['id' => 11, 'exception' => 'read only']
			);
		$this->output->expects($this->once())->method('warning')
			->with('RenameAgentSchemaSlug: slug rename failed; see the log.');
		$this->output->expects($this->never())->method('info');

		$this->step()->run($this->output);

	}//end testAFailingRenameIsLoggedAndReported()

	/**
	 * When both slugs exist the ids of each are logged.
	 *
	 * @return void
	 */
	public function testBothSlugsLogTheirIds(): void {
		$this->schemas(ids: ['agent' => [11], 'buildAgent' => [20]]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->logger->expects($this->once())->method('warning')
			->with('RenameAgentSchemaSlug: both slugs exist; refusing to merge them.', ['old' => [11], 'new' => [20]]);
		$this->output->expects($this->once())->method('warning')
			->with('RenameAgentSchemaSlug: both `agent` and `buildAgent` exist; refusing to merge them. Resolve by hand.');

		$this->step()->run($this->output);

	}//end testBothSlugsLogTheirIds()
}//end class
