<?php

/**
 * Unit tests for AppChangeHistory: the saves of an app and its versions from
 * the audit trail, newest first, with the user, the time and the version.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use DateTime;
use OCA\Buildiq\Service\AppChangeHistory;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AppChangeHistory.
 *
 * @covers \OCA\Buildiq\Service\AppChangeHistory
 */
final class AppChangeHistoryTest extends TestCase {

	/**
	 * An audit row.
	 *
	 * @param string      $action   The action.
	 * @param string      $at       The time.
	 * @param string      $user     The user id.
	 * @param string|null $userName The display name.
	 *
	 * @return AuditTrail The row.
	 */
	private function row(string $action, string $at, string $user, ?string $userName=null): AuditTrail {
		$row = new AuditTrail();
		$row->setAction($action);
		$row->setCreated(new DateTime($at));
		$row->setUser($user);
		$row->setUserName($userName);
		return $row;
	}//end row()

	/**
	 * The service over this trail, with an object service answering these records.
	 *
	 * @param AuditTrailMapper|null          $trails   The trail, or null when OpenRegister is not loaded.
	 * @param array<int,array<string,mixed>> $records  What a search answers.
	 *
	 * @return AppChangeHistory The service.
	 */
	private function history(?AuditTrailMapper $trails, array $records=[]): AppChangeHistory {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturn($records);
		$register = new Register();
		$register->setId(1);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		return new AppChangeHistory(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas, trails: $trails);
	}//end history()

	/**
	 * A trail answering these rows per object uuid, recording the filters asked.
	 *
	 * @param array<string,array<int,AuditTrail>> $byUuid The rows per uuid.
	 * @param array<int,array<string,mixed>>      $asked  The filters asked, filled in.
	 *
	 * @return AuditTrailMapper The trail double.
	 */
	private function trails(array $byUuid, array &$asked=[]): AuditTrailMapper {
		$trails = $this->createMock(AuditTrailMapper::class);
		$trails->method('findAll')->willReturnCallback(
			static function (?int $limit=null, ?int $offset=null, ?array $filters=[]) use ($byUuid, &$asked): array {
				$asked[] = ['limit' => $limit, 'filters' => $filters];
				return $byUuid[(string)($filters['object_uuid'] ?? '')] ?? [];
			}
		);
		return $trails;
	}//end trails()

	/**
	 * An owner reads the design history: sanne's save of the development
	 * version at 10:15 is listed with the version, newest first, beside the
	 * app record's own save.
	 *
	 * @return void
	 */
	public function testTheSavesOfTheAppAndItsVersionsAreListedNewestFirst(): void {
		$asked  = [];
		$trails = $this->trails(
			byUuid: [
				'app-uuid' => [$this->row(action: 'update', at: '2026-11-24T09:00:00+00:00', user: 'ruben', userName: 'Ruben')],
				'v-dev'    => [$this->row(action: 'update', at: '2026-11-24T10:15:00+00:00', user: 'sanne', userName: 'Sanne')],
				'v-prod'   => [$this->row(action: 'create', at: '2026-11-20T08:00:00+00:00', user: 'ruben', userName: 'Ruben')],
			],
			asked: $asked
		);

		$entries = $this->history(trails: $trails)->entries(
			application: ['id' => 'app-uuid', 'slug' => 'permits'],
			versions: [
				['id' => 'v-dev', 'slug' => 'development', 'name' => 'Development'],
				['id' => 'v-prod', 'slug' => 'production'],
			]
		);

		self::assertSame(
			expected: [
				'at'          => '2026-11-24T10:15:00+00:00',
				'user'        => 'sanne',
				'userName'    => 'Sanne',
				'action'      => 'update',
				'object'      => 'version',
				'version'     => 'development',
				'versionName' => 'Development',
			],
			actual: $entries[0]
		);
		self::assertSame(expected: ['application', null], actual: [$entries[1]['object'], $entries[1]['version']]);
		self::assertSame(expected: ['production', 'production'], actual: [$entries[2]['version'], $entries[2]['versionName']]);
		self::assertSame(
			expected: ['app-uuid', 'v-dev', 'v-prod'],
			actual: array_map(static fn (array $call): string => $call['filters']['object_uuid'], $asked)
		);
		self::assertSame(expected: AppChangeHistory::LIMIT, actual: $asked[0]['limit']);
	}//end testTheSavesOfTheAppAndItsVersionsAreListedNewestFirst()

	/**
	 * Reads and named events are not saves; a missing display name falls
	 * back to the user id.
	 *
	 * @return void
	 */
	public function testReadsAndNamedEventsAreLeftOut(): void {
		$trails = $this->trails(
			byUuid: [
				'app-uuid' => [
					$this->row(action: 'read', at: '2026-11-24T11:00:00+00:00', user: 'vera'),
					$this->row(action: 'rbac.admin_bypass', at: '2026-11-24T11:01:00+00:00', user: 'admin'),
					$this->row(action: 'delete', at: '2026-11-24T11:02:00+00:00', user: 'ruben'),
				],
			]
		);

		$entries = $this->history(trails: $trails)->entries(application: ['id' => 'app-uuid'], versions: []);

		self::assertCount(expectedCount: 1, haystack: $entries);
		self::assertSame(expected: ['delete', 'ruben', 'ruben'], actual: [$entries[0]['action'], $entries[0]['user'], $entries[0]['userName']]);
	}//end testReadsAndNamedEventsAreLeftOut()

	/**
	 * A version without a uuid is not read, and a record without one reads nothing.
	 *
	 * @return void
	 */
	public function testRecordsWithoutAUuidAreNotRead(): void {
		$asked  = [];
		$trails = $this->trails(byUuid: [], asked: $asked);

		$entries = $this->history(trails: $trails)->entries(application: [], versions: [['slug' => 'development']]);

		self::assertSame(expected: [[], []], actual: [$entries, $asked]);
	}//end testRecordsWithoutAUuidAreNotRead()

	/**
	 * Without OpenRegister's audit trail the service says so and lists nothing.
	 *
	 * @return void
	 */
	public function testWithoutTheTrailNothingIsListed(): void {
		$history = $this->history(trails: null);

		self::assertFalse(condition: $history->available());
		self::assertSame(expected: [], actual: $history->entries(application: ['id' => 'app-uuid'], versions: []));
	}//end testWithoutTheTrailNothingIsListed()

	/**
	 * The app is found by slug, and only its own versions are kept.
	 *
	 * @return void
	 */
	public function testTheAppAndOnlyItsOwnVersionsAreRead(): void {
		$history = $this->history(
			trails: null,
			records: [
				['id' => 'v-1', 'slug' => 'development', 'application' => 'app-uuid'],
				['id' => 'v-2', 'slug' => 'development', 'application' => 'other-uuid'],
			]
		);

		self::assertSame(expected: 'v-1', actual: $history->application(slug: 'permits')['id']);
		self::assertSame(expected: ['v-1'], actual: array_column($history->versions(application: ['id' => 'app-uuid']), 'id'));
	}//end testTheAppAndOnlyItsOwnVersionsAreRead()
}//end class
