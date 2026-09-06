<?php

/**
 * Unit tests for RenameAgentSchemaSlug.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://buildiq.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\RenameAgentSchemaSlug;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guards the agent schema slug migration.
 *
 * Two things here can go wrong quietly: renaming nothing (so the import forks the
 * schema and orphans its objects), and renaming hermiq's row instead of this app's.
 * The second is the reason the application filter exists, so it is asserted on the
 * SQL rather than assumed.
 */
final class RenameAgentSchemaSlugTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * The step under test.
	 *
	 * @var RenameAgentSchemaSlug
	 */
	private RenameAgentSchemaSlug $step;

	/**
	 * Queries the step issued, as [sql, params].
	 *
	 * @var array<int, array{0:string,1:array}>
	 */
	private array $queries = [];

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->queries = [];
		$this->step = new RenameAgentSchemaSlug($this->db, $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * Answer each slug lookup from a map and record the SQL.
	 *
	 * @param array<string, array<int, mixed>> $bySlug Slug => ids present.
	 *
	 * @return void
	 */
	private function lookups(array $bySlug): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($bySlug): IResult {
				$this->queries[] = [$sql, $params];
				$slug = (string)($params[0] ?? '');
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(($bySlug[$slug] ?? []));
				return $result;
			}
		);

	}//end lookups()

	/**
	 * The slug is renamed in place, keeping the schema id.
	 *
	 * Keeping the id is the whole point: the shard table is named after it, so a
	 * new schema would leave every bound agent behind a slug nothing reads.
	 *
	 * @return void
	 */
	public function testRenamesTheSlugInPlace(): void {
		$this->lookups(['agent' => [418]]);

		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use (&$statements): int {
				$statements[] = [$sql, $params];
				return 1;
			}
		);

		$this->step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $statements, 'exactly one row may be rewritten');
		$this->assertStringContainsString('openregister_schemas', $statements[0][0]);
		$this->assertStringContainsString('SET slug', $statements[0][0]);
		$this->assertSame(['buildAgent', 418], $statements[0][1]);

	}//end testRenamesTheSlugInPlace()

	/**
	 * The lookup is scoped to this app's rows, and to BOTH spellings of its
	 * application id.
	 *
	 * Without the application filter the step would find hermiq's `agent` and
	 * rename it — the exact damage it exists to prevent. Without `openbuild` it
	 * would silently do nothing on an install that has not yet migrated its
	 * application id, which is precisely the install that still needs it.
	 *
	 * @return void
	 */
	public function testTheLookupIsScopedToThisAppsRows(): void {
		$this->lookups(['agent' => [418]]);
		$this->db->method('executeStatement')->willReturn(1);

		$this->step->run($this->createMock(IOutput::class));

		$this->assertNotEmpty($this->queries, 'the step must read before it writes');
		[$sql, $params] = $this->queries[0];
		$this->assertStringContainsString('application IN', $sql);
		$this->assertContains('buildiq', $params);
		$this->assertContains('openbuild', $params, 'the pre-rename application id must still match');

	}//end testTheLookupIsScopedToThisAppsRows()

	/**
	 * An install already on the namespaced slug is left alone.
	 *
	 * @return void
	 */
	public function testIsANoOpWhenTheOldSlugIsAbsent(): void {
		$this->lookups(['buildAgent' => [418]]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->step->run($this->createMock(IOutput::class));

	}//end testIsANoOpWhenTheOldSlugIsAbsent()

	/**
	 * Both slugs present is a refusal, not a merge.
	 *
	 * @return void
	 */
	public function testRefusesWhenBothSlugsExist(): void {
		$this->lookups(['agent' => [418], 'buildAgent' => [520]]);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);

	}//end testRefusesWhenBothSlugsExist()

	/**
	 * Duplicate old slugs are a refusal too — the step must not guess.
	 *
	 * @return void
	 */
	public function testRefusesOnDuplicateOldSlugs(): void {
		$this->lookups(['agent' => [418, 419]]);
		$this->db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step->run($output);

	}//end testRefusesOnDuplicateOldSlugs()

}//end class
