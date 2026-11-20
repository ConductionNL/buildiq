<?php

/**
 * Unit tests for AppTestSuiteService.
 *
 * The structure checks of a version's manifest, the test cases of the rule
 * sets the app owns, and record tests that are skipped, never passed, until
 * OpenRegister can check a record without saving it.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppTestSuiteService;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\Buildiq\Service\RuleSetVersioningService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the three kinds of check.
 *
 * @covers \OCA\Buildiq\Service\AppTestSuiteService
 * @uses   \OCA\Buildiq\Service\RegistrationFormTargetSchemaReader
 */
final class AppTestSuiteServiceTest extends TestCase {

	/**
	 * The development version of vergunningen: an index page on `permit`
	 * and a detail page, both in the menu.
	 *
	 * @var array<string, mixed>
	 */
	private const VERSION = [
		'manifest' => [
			'pages' => [
				['id' => 'permits', 'type' => 'index', 'config' => ['register' => 'vergunningen', 'schema' => 'permit', 'columns' => ['title', ['key' => 'kvkNumber'], '@self.updated']]],
				['id' => 'permit', 'type' => 'detail', 'config' => ['register' => 'vergunningen', 'schema' => 'permit', 'fields' => ['applicant'], 'fieldWidgets' => [['id' => 'notes', 'component' => 'CnMarkdownEditor']]]],
				['id' => 'about', 'type' => 'custom', 'config' => []],
			],
			'menu' => [
				['id' => 'permits', 'label' => 'Permits', 'route' => 'permits', 'children' => [['label' => 'About', 'route' => 'about']]],
			],
		],
	];

	/**
	 * The rule sets and objects the reader answers, by schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * The suite over a register `vergunningen` whose `permit` schema has the
	 * given properties; a register given as null cannot be found.
	 *
	 * @param array<string, mixed>|null $properties The properties of `permit`, or null for a missing register.
	 * @param array<int, string>        $failing    The rule test cases runTestGate reports failing.
	 *
	 * @return AppTestSuiteService
	 */
	private function suite(?array $properties, array $failing=[]): AppTestSuiteService {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('permit');
		$schema->method('getProperties')->willReturn(($properties ?? []));

		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([7]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		if ($properties === null) {
			$registerMapper->method('find')->willThrowException(new DoesNotExistException('no register'));
		} else {
			$registerMapper->method('find')->willReturn($register);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$reader = new RegistrationFormTargetSchemaReader(registerMapper: $registerMapper, schemaMapper: $schemaMapper, logger: $this->createMock(LoggerInterface::class));

		$objects = $this->createMock(RuleObjectReader::class);
		$objects->method('findScoped')->willReturnCallback(
			fn (string $schema, array $filters): array => array_values(array_filter(
				($this->objects[$schema] ?? []),
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			))
		);

		$versioning = $this->createMock(RuleSetVersioningService::class);
		$versioning->method('runTestGate')->willReturn($failing);

		return new AppTestSuiteService(schemas: $reader, objects: $objects, versioning: $versioning);
	}//end suite()

	/**
	 * A clean manifest passes every page, column, field and menu check.
	 *
	 * @return void
	 */
	public function testACleanManifestPassesEveryStructureCheck(): void {
		$report = $this->suite(properties: ['title' => [], 'kvkNumber' => [], 'applicant' => [], 'notes' => []])->run(['slug' => 'vergunningen', 'id' => 'app-1'], self::VERSION);

		$this->assertSame(['passed' => 8, 'failed' => 0, 'skipped' => 0], array_slice($report, 0, 3));
		$this->assertSame(
			['Page permits', 'Page permits, column title', 'Page permits, column kvkNumber', 'Page permit', 'Page permit, field applicant', 'Page permit, field notes', 'Menu item Permits', 'Menu item About'],
			array_column($report['checks'], 'name')
		);
	}//end testACleanManifestPassesEveryStructureCheck()

	/**
	 * A column whose property was removed from the schema fails, named by page and column.
	 *
	 * @return void
	 */
	public function testARemovedPropertyFailsItsColumn(): void {
		$report = $this->suite(properties: ['title' => [], 'applicant' => [], 'notes' => []])->run(['slug' => 'vergunningen'], self::VERSION);

		$this->assertSame(1, $report['failed']);
		$failed = array_values(array_filter($report['checks'], static fn (array $check): bool => $check['outcome'] === 'failed'));
		$this->assertSame(
			[['kind' => 'structure', 'name' => 'Page permits, column kvkNumber', 'outcome' => 'failed', 'message' => 'No such property on permit.']],
			$failed
		);
	}//end testARemovedPropertyFailsItsColumn()

	/**
	 * A schema the register does not hold fails the page and checks none of its columns.
	 *
	 * @return void
	 */
	public function testAMissingSchemaFailsThePage(): void {
		$version = self::VERSION;
		$version['manifest']['pages'][0]['config']['schema'] = 'permitV2';

		$checks = $this->suite(properties: ['title' => [], 'kvkNumber' => [], 'applicant' => []])->structureChecks(manifest: $version['manifest']);

		$this->assertSame(['kind' => 'structure', 'name' => 'Page permits', 'outcome' => 'failed', 'message' => 'The register vergunningen has no schema permitV2.'], $checks[0]);
		$this->assertSame('Page permit', $checks[1]['name']);
	}//end testAMissingSchemaFailsThePage()

	/**
	 * A register that cannot be read fails its pages instead of passing them.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterFailsItsPages(): void {
		$checks = $this->suite(properties: null)->structureChecks(manifest: self::VERSION['manifest']);

		$this->assertSame('failed', $checks[0]['outcome']);
		$this->assertSame('The register vergunningen or its schema permit could not be read.', $checks[0]['message']);
	}//end testAnUnreadableRegisterFailsItsPages()

	/**
	 * A menu item leading to no page of the version fails.
	 *
	 * @return void
	 */
	public function testAMenuItemWithoutAPageFails(): void {
		$manifest = ['pages' => [], 'menu' => [['label' => 'Reports', 'route' => 'reports']]];

		$this->assertSame(
			[['kind' => 'structure', 'name' => 'Menu item Reports', 'outcome' => 'failed', 'message' => 'Leads to "reports", which is no page of this version.']],
			$this->suite(properties: [])->structureChecks(manifest: $manifest)
		);
	}//end testAMenuItemWithoutAPageFails()

	/**
	 * The app's rule-set cases are reported by rule set and case; a failing case fails.
	 *
	 * @return void
	 */
	public function testAFailingRuleCaseIsReportedByRuleSetAndCase(): void {
		$this->objects = [
			'rule-set' => [['slug' => 'permit-fees', 'ownerApp' => 'vergunningen'], ['slug' => 'parking-fees', 'ownerApp' => 'parkeren']],
			'rule-test-case' => [
				['ruleSetId' => 'permit-fees', 'name' => 'Small build pays 300'],
				['ruleSetId' => 'permit-fees', 'name' => 'Large build pays 1200'],
				['ruleSetId' => 'parking-fees', 'name' => 'Never run'],
			],
		];

		$checks = $this->suite(properties: [], failing: ['Large build pays 1200'])->ruleSetChecks(appSlug: 'vergunningen');

		$this->assertSame(
			[
				['kind' => 'rule-set', 'name' => 'permit-fees: Small build pays 300', 'outcome' => 'passed', 'message' => ''],
				['kind' => 'rule-set', 'name' => 'permit-fees: Large build pays 1200', 'outcome' => 'failed', 'message' => 'The rule set does not give the expected result.'],
			],
			$checks
		);
	}//end testAFailingRuleCaseIsReportedByRuleSetAndCase()

	/**
	 * A rule set of another app is left out even when the search returns it.
	 *
	 * @return void
	 */
	public function testARuleSetOfAnotherAppIsLeftOut(): void {
		$objects = $this->createMock(RuleObjectReader::class);
		$objects->method('findScoped')->willReturnOnConsecutiveCalls([['slug' => 'parking-fees', 'ownerApp' => 'parkeren']], []);
		$versioning = $this->createMock(RuleSetVersioningService::class);
		$versioning->expects($this->never())->method('runTestGate');
		$reader = new RegistrationFormTargetSchemaReader(registerMapper: $this->createMock(RegisterMapper::class), schemaMapper: $this->createMock(SchemaMapper::class), logger: $this->createMock(LoggerInterface::class));

		$suite = new AppTestSuiteService(schemas: $reader, objects: $objects, versioning: $versioning);

		$this->assertSame([], $suite->ruleSetChecks(appSlug: 'vergunningen'));
	}//end testARuleSetOfAnotherAppIsLeftOut()

	/**
	 * Record tests are skipped with the reason, never passed, while OpenRegister cannot validate without saving.
	 *
	 * @return void
	 */
	public function testRecordTestsAreSkippedWithTheReasonNeverPassed(): void {
		$this->objects = [
			'app-record-test' => [
				['application' => 'app-1', 'schema' => 'permit', 'name' => 'Permit needs an applicant', 'expect' => 'refuse', 'expectedFields' => ['applicant']],
				['application' => 'app-2', 'schema' => 'parking', 'name' => 'Not ours'],
			],
		];

		$report = $this->suite(properties: ['title' => [], 'kvkNumber' => [], 'applicant' => [], 'notes' => []])->run(['slug' => 'vergunningen', 'id' => 'app-1'], self::VERSION);

		$this->assertSame(1, $report['skipped']);
		$this->assertSame(
			['kind' => 'record', 'name' => 'permit: Permit needs an applicant', 'outcome' => 'skipped', 'message' => AppTestSuiteService::RECORD_TEST_SKIP_REASON],
			$report['checks'][8]
		);
	}//end testRecordTestsAreSkippedWithTheReasonNeverPassed()
}//end class
