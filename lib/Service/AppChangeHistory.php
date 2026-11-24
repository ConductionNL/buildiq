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
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Builds an app's change history from the audit trail of its records.
 *
 * The Application object and each of its ApplicationVersion objects are
 * read by their uuid. A save is a create, an update, a delete or a revert of
 * one of those objects; reads and named events (an id with a dot, such as
 * `rbac.admin_bypass`) are not saves and are left out. Each entry names the
 * user, the time and, for a version, which version changed. Authorising the
 * caller is the controller's job; this class only reads. Versions are kept
 * by their `application` back-reference in PHP, as the version listing does,
 * because OpenRegister's relation filter is unreliable on some installs.
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
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService  Reads the Application and its versions.
	 * @param RegisterMapper         $registerMapper Resolves buildiq's register.
	 * @param SchemaMapper           $schemaMapper   Resolves the Application and version schemas.
	 * @param AuditTrailMapper|null  $trails         The OpenRegister audit trail, null until OpenRegister is loaded.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ?AuditTrailMapper $trails=null,
	) {
	}//end __construct()

	/**
	 * Whether the audit trail can be read.
	 *
	 * @return bool True when OpenRegister's audit trail is available.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	public function available(): bool {
		return $this->trails !== null;
	}//end available()

	/**
	 * The Application with this slug, or null when there is none.
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,mixed>|null The Application record.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	public function application(string $slug): ?array {
		$rows = $this->rowsOf(schemaSlug: ApplicationVersionServiceInterface::APPLICATION_SCHEMA, filters: ['slug' => $slug]);
		if ($rows === null || $rows === []) {
			return null;
		}

		return $rows[0];
	}//end application()

	/**
	 * The ApplicationVersions of an Application, or null when they cannot be read.
	 *
	 * @param array<string,mixed> $application The Application record.
	 *
	 * @return array<int,array<string,mixed>>|null The versions.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	public function versions(array $application): ?array {
		$rows = $this->rowsOf(schemaSlug: ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA, filters: []);
		if ($rows === null) {
			return null;
		}

		$appUuid = $this->uuidOf(record: $application);
		return array_values(
			array_filter(
				$rows,
				static fn (array $version): bool => (string)($version['application'] ?? '') === $appUuid
			)
		);
	}//end versions()

	/**
	 * The change history of an app, newest first.
	 *
	 * @param array<string,mixed>            $application The Application record.
	 * @param array<int,array<string,mixed>> $versions    Its ApplicationVersion records.
	 *
	 * @return array<int,array<string,string|null>> The entries (`at`, `user`, `userName`, `action`,
	 *                                               `object`, `version`, `versionName`); none without a trail.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	public function entries(array $application, array $versions): array {
		$trails = $this->trails;
		if ($trails === null) {
			return [];
		}

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
	 * The objects of one of buildiq's schemas matching the filters, or null
	 * when the register or the schema cannot be resolved.
	 *
	 * @param string               $schemaSlug The schema.
	 * @param array<string,string> $filters    Property filters.
	 *
	 * @return array<int,array<string,mixed>>|null The records.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function rowsOf(string $schemaSlug, array $filters): ?array {
		try {
			$registerId = $this->registerMapper->find(ApplicationVersionServiceInterface::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId   = $this->schemaMapper->find($schemaSlug, _multitenancy: false)->getId();
		} catch (Throwable $e) {
			return null;
		}

		$rows = $this->objectService->searchObjects(
			query: ['@self' => ['register' => $registerId, 'schema' => $schemaId]] + $filters
		);
		if (is_array($rows) === false) {
			return [];
		}

		return array_map(static fn (mixed $row): array => ObjectRecord::toArray(object: $row), array_values($rows));
	}//end rowsOf()

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
