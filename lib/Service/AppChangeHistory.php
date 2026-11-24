<?php

/**
 * Buildiq App Change History
 *
 * The saves of an app and of its versions, read from the OpenRegister audit
 * trail, newest first.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Builds an app's change history from the audit trail of its records.
 *
 * The Application object and each of its ApplicationVersion objects are
 * read by their uuid. A save is a create, an update, a delete or a revert of
 * one of those objects; reads and named events (an id with a dot, such as
 * `rbac.admin_bypass`) are not saves and are left out. Each entry names the
 * user, the time and, for a version, which version changed. Authorising the
 * caller is the controller's job; this class only reads.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */
final class AppChangeHistory {
	/**
	 * The most entries one history answers, and the most read per object.
	 */
	public const LIMIT = 100;

	/**
	 * Audit actions that are not a save.
	 *
	 * @var array<int,string>
	 */
	private const NOT_SAVES = ['read'];

	/**
	 * The change history of an app, newest first.
	 *
	 * @param array<string,mixed>            $application The Application record.
	 * @param array<int,array<string,mixed>> $versions    Its ApplicationVersion records.
	 * @param AuditTrailMapper               $trails      The OpenRegister audit trail.
	 *
	 * @return array<int,array<string,string|null>> The entries: `at`, `user`, `userName`, `action`, `object`, `version`, `versionName`.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	public function entries(array $application, array $versions, AuditTrailMapper $trails): array {
		$entries = [];
		foreach ($this->subjects(application: $application, versions: $versions) as $uuid => $subject) {
			$rows = $trails->findAll(
				limit: self::LIMIT,
				filters: ['object_uuid' => $uuid],
				sort: ['created' => 'DESC']
			);
			foreach ($rows as $row) {
				$entry = $this->entry(row: $row, subject: $subject);
				if ($entry !== null) {
					$entries[] = $entry;
				}
			}
		}

		usort(
			$entries,
			static fn (array $left, array $right): int => strcmp((string)$right['at'], (string)$left['at'])
		);

		return array_slice($entries, 0, self::LIMIT);
	}//end entries()

	/**
	 * The objects whose trail is read, by uuid, with what each one is.
	 *
	 * @param array<string,mixed>            $application The Application record.
	 * @param array<int,array<string,mixed>> $versions    Its ApplicationVersion records.
	 *
	 * @return array<string,array<string,string|null>> Per uuid: `object`, `version`, `versionName`.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	private function subjects(array $application, array $versions): array {
		$subjects = [];
		$appUuid  = $this->uuidOf(record: $application);
		if ($appUuid !== '') {
			$subjects[$appUuid] = ['object' => 'application', 'version' => null, 'versionName' => null];
		}

		foreach ($versions as $version) {
			$uuid = $this->uuidOf(record: $version);
			if ($uuid === '' || isset($subjects[$uuid]) === true) {
				continue;
			}

			$slug = (string)($version['slug'] ?? '');
			$name = (string)($version['name'] ?? '');
			if ($name === '') {
				$name = $slug;
			}

			$subjects[$uuid] = ['object' => 'version', 'version' => $slug, 'versionName' => $name];
		}

		return $subjects;
	}//end subjects()

	/**
	 * One history entry for an audit row, or null when the row is no save.
	 *
	 * @param object                    $row     An OpenRegister AuditTrail entity.
	 * @param array<string,string|null> $subject What the row's object is.
	 *
	 * @return array<string,string|null>|null The entry, or null.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	private function entry(object $row, array $subject): ?array {
		$action = (string)$row->getAction();
		if ($action === '' || in_array($action, self::NOT_SAVES, true) === true || str_contains($action, '.') === true) {
			return null;
		}

		$created = $row->getCreated();
		$moment  = null;
		if ($created instanceof DateTimeInterface) {
			$moment = $created->format(DateTimeInterface::ATOM);
		}

		$user     = $row->getUser();
		$userName = $row->getUserName();
		if ($userName === null || $userName === '') {
			$userName = $user;
		}

		return [
			'at'          => $moment,
			'user'        => $user,
			'userName'    => $userName,
			'action'      => $action,
			'object'      => $subject['object'],
			'version'     => $subject['version'],
			'versionName' => $subject['versionName'],
		];
	}//end entry()

	/**
	 * The uuid of a record as buildiq normalises it (`id`, else `uuid`).
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return string The uuid, or '' when it has none.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	private function uuidOf(array $record): string {
		return (string)($record['id'] ?? $record['uuid'] ?? '');
	}//end uuidOf()
}//end class
