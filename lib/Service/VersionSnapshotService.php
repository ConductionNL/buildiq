<?php

/**
 * Buildiq VersionSnapshotService
 *
 * Named snapshots of an ApplicationVersion's manifest, and rollback to them.
 * A snapshot is a `version-snapshot` object, never a sibling ApplicationVersion
 * (ADR-002). Restoring keeps the replaced manifest as a "Previous draft"
 * snapshot, so a rollback can be undone.
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
 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Buildiq\Exception\VersionSnapshotException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes, lists and restores version snapshots.
 *
 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
 */
class VersionSnapshotService {
	/**
	 * Schema slug of the snapshot objects.
	 */
	public const SNAPSHOT_SCHEMA = 'version-snapshot';

	/**
	 * Kind of a snapshot a user took.
	 */
	public const KIND_MANUAL = 'manual';

	/**
	 * Kind of the snapshot a rollback keeps of the state it replaced.
	 */
	public const KIND_PREVIOUS_DRAFT = 'previous-draft';

	/**
	 * Longest label accepted.
	 */
	private const MAX_LABEL_LENGTH = 120;

	/**
	 * Roles that may list snapshots.
	 *
	 * @var array<int,string>
	 */
	private const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * Roles that may take and restore snapshots.
	 *
	 * @var array<int,string>
	 */
	private const WRITE_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param RegisterMapper $registerMapper Register id lookup
	 * @param SchemaMapper $schemaMapper Schema id lookup
	 * @param PermissionResolver $permissionResolver Role check on the parent Application
	 * @param ApplicationVersionService $versionService Manifest checksum
	 * @param LoggerInterface $logger PSR logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly PermissionResolver $permissionResolver,
		private readonly ApplicationVersionService $versionService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List the snapshots of one version, newest first.
	 *
	 * @param string $appSlug The Application slug
	 * @param string $versionSlug The version slug; '' means the production version
	 * @param IUser|null $caller The signed-in user
	 *
	 * @return array<int,array<string,mixed>> The snapshots
	 *
	 * @throws VersionSnapshotException When the app or version is unknown or the caller has no role
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	public function listSnapshots(string $appSlug, string $versionSlug, ?IUser $caller): array {
		$application = $this->authorise(appSlug: $appSlug, caller: $caller, roles: self::READ_ROLES);
		$version = $this->resolveVersion(application: $application, versionSlug: $versionSlug);

		$snapshots = [];
		foreach ($this->search(schemaSlug: self::SNAPSHOT_SCHEMA, filters: ['version' => $this->idOf(row: $version)]) as $row) {
			if (($row['version'] ?? null) === $this->idOf(row: $version)
				&& ($row['application'] ?? null) === $this->idOf(row: $application)
			) {
				$snapshots[] = $row;
			}
		}

		usort(
			$snapshots,
			static fn (array $left, array $right): int => strcmp((string)($right['takenAt'] ?? ''), (string)($left['takenAt'] ?? ''))
		);

		return $snapshots;
	}//end listSnapshots()

	/**
	 * Take a named snapshot of a version's current manifest.
	 *
	 * @param string $appSlug The Application slug
	 * @param string $versionSlug The version slug; '' means the production version
	 * @param string $label The snapshot label
	 * @param IUser|null $caller The signed-in user
	 *
	 * @return array<string,mixed> The stored snapshot
	 *
	 * @throws VersionSnapshotException On a bad label, unknown app/version or missing role
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	public function takeSnapshot(string $appSlug, string $versionSlug, string $label, ?IUser $caller): array {
		$label = trim($label);
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			throw new VersionSnapshotException(
				errorCode: 'invalid_label',
				status: 400,
				message: 'A label of 1 to ' . self::MAX_LABEL_LENGTH . ' characters is required.'
			);
		}

		$application = $this->authorise(appSlug: $appSlug, caller: $caller, roles: self::WRITE_ROLES);
		$version = $this->resolveVersion(application: $application, versionSlug: $versionSlug);

		return $this->store(
			application: $application,
			version: $version,
			label: $label,
			kind: self::KIND_MANUAL,
			caller: $caller
		);
	}//end takeSnapshot()

	/**
	 * Roll a version back to a snapshot, keeping the replaced manifest.
	 *
	 * @param string $appSlug The Application slug
	 * @param string $snapshotUuid The snapshot to restore
	 * @param IUser|null $caller The signed-in user
	 *
	 * @return array{version: array<string,mixed>, previousDraft: array<string,mixed>} The updated version and the kept snapshot
	 *
	 * @throws VersionSnapshotException On an unknown snapshot, app or version, or a missing role
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	public function restoreSnapshot(string $appSlug, string $snapshotUuid, ?IUser $caller): array {
		$application = $this->authorise(appSlug: $appSlug, caller: $caller, roles: self::WRITE_ROLES);

		$snapshot = $this->findObject(id: $snapshotUuid, schemaSlug: self::SNAPSHOT_SCHEMA);
		if ($snapshot === null
			|| ($snapshot['application'] ?? null) !== $this->idOf(row: $application)
			|| is_array($snapshot['manifest'] ?? null) === false
		) {
			throw new VersionSnapshotException(errorCode: 'not_found', status: 404, message: 'Snapshot not found.');
		}

		$version = $this->findObject(
			id: (string)($snapshot['version'] ?? ''),
			schemaSlug: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
		);
		if ($version === null || ($version['application'] ?? null) !== $this->idOf(row: $application)) {
			throw new VersionSnapshotException(errorCode: 'not_found', status: 404, message: 'The snapshot\'s version no longer exists.');
		}

		$previousDraft = $this->store(
			application: $application,
			version: $version,
			label: 'Previous draft',
			kind: self::KIND_PREVIOUS_DRAFT,
			caller: $caller
		);

		$version['manifest'] = $snapshot['manifest'];
		$saved = $this->objectService->saveObject(
			object: $version,
			register: ApplicationVersionService::REGISTER_SLUG,
			schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
			uuid: $this->idOf(row: $version)
		);

		return [
			'version' => $this->normalise(object: $saved),
			'previousDraft' => $previousDraft,
		];
	}//end restoreSnapshot()

	/**
	 * Store a snapshot of a version's current manifest.
	 *
	 * @param array<string,mixed> $application The Application
	 * @param array<string,mixed> $version The version
	 * @param string $label The label
	 * @param string $kind `manual` or `previous-draft`
	 * @param IUser|null $caller The signed-in user
	 *
	 * @return array<string,mixed> The stored snapshot
	 */
	private function store(array $application, array $version, string $label, string $kind, ?IUser $caller): array {
		$manifest = $version['manifest'] ?? [];
		if (is_array($manifest) === false) {
			$manifest = [];
		}

		$takenBy = '';
		if ($caller !== null) {
			$takenBy = $caller->getUID();
		}

		$saved = $this->objectService->saveObject(
			object: [
				'application' => $this->idOf(row: $application),
				'version' => $this->idOf(row: $version),
				'label' => $label,
				'manifest' => $manifest,
				'checksum' => $this->versionService->hashManifest(manifest: $manifest),
				'takenBy' => $takenBy,
				'takenAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'kind' => $kind,
			],
			register: ApplicationVersionService::REGISTER_SLUG,
			schema: self::SNAPSHOT_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);

		return $this->normalise(object: $saved);
	}//end store()

	/**
	 * Load the Application and check the caller holds one of the roles.
	 *
	 * @param string $appSlug The Application slug
	 * @param IUser|null $caller The signed-in user
	 * @param array<int,string> $roles Accepted roles
	 *
	 * @return array<string,mixed> The Application
	 *
	 * @throws VersionSnapshotException When unauthenticated, unknown or not allowed
	 */
	private function authorise(string $appSlug, ?IUser $caller, array $roles): array {
		if ($caller === null) {
			throw new VersionSnapshotException(errorCode: 'unauthenticated', status: 401, message: 'Sign in first.');
		}

		$application = null;
		foreach ($this->search(schemaSlug: ApplicationVersionService::APPLICATION_SCHEMA, filters: ['slug' => $appSlug]) as $row) {
			if (($row['slug'] ?? null) === $appSlug) {
				$application = $row;
				break;
			}
		}

		if ($application === null) {
			throw new VersionSnapshotException(errorCode: 'not_found', status: 404, message: 'Application ' . $appSlug . ' not found.');
		}

		$allowed = $this->permissionResolver->matchesCaller(
			permissions: (array)($application['permissions'] ?? []),
			caller: $caller,
			userGroups: $this->permissionResolver->resolveUserGroups($caller),
			allowAdminBypass: false,
			roles: $roles
		);
		if ($allowed === false && $this->permissionResolver->isAdmin($caller) === true) {
			// Same audited admin bypass as the other version endpoints.
			$this->logger->info(
				'Buildiq: admin bypass by {actor} on version snapshots of {slug}',
				['actor' => $caller->getUID(), 'slug' => $appSlug, 'event' => 'rbac.admin_bypass']
			);
			$allowed = true;
		}

		if ($allowed === false) {
			throw new VersionSnapshotException(errorCode: 'buildiq.rbac.no_role', status: 403, message: 'You have no role on this app.');
		}

		return $application;
	}//end authorise()

	/**
	 * The named version of the app, or its production version.
	 *
	 * @param array<string,mixed> $application The Application
	 * @param string $versionSlug The version slug; '' means production
	 *
	 * @return array<string,mixed> The version
	 *
	 * @throws VersionSnapshotException When the app has no such version
	 */
	private function resolveVersion(array $application, string $versionSlug): array {
		$applicationUuid = $this->idOf(row: $application);
		$productionUuid = $application['productionVersion'] ?? '';
		foreach ($this->search(schemaSlug: ApplicationVersionService::APPLICATION_VERSION_SCHEMA, filters: ['application' => $applicationUuid]) as $row) {
			if (($row['application'] ?? null) !== $applicationUuid) {
				continue;
			}

			$matchesSlug = $versionSlug !== '' && ($row['slug'] ?? null) === $versionSlug;
			$isProduction = $versionSlug === '' && $this->idOf(row: $row) === $productionUuid;
			if ($matchesSlug === true || $isProduction === true) {
				return $row;
			}
		}

		throw new VersionSnapshotException(errorCode: 'not_found', status: 404, message: 'Version not found.');
	}//end resolveVersion()

	/**
	 * Search one buildiq schema, normalising every row.
	 *
	 * @param string $schemaSlug The schema slug
	 * @param array<string,mixed> $filters Property filters
	 *
	 * @return array<int,array<string,mixed>> The rows
	 */
	private function search(string $schemaSlug, array $filters): array {
		try {
			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find($schemaSlug, _multitenancy: false)->getId();
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: version snapshots could not resolve schema {schema}: {message}',
				['schema' => $schemaSlug, 'message' => $e->getMessage()]
			);
			throw new VersionSnapshotException(errorCode: 'internal_error', status: 500, message: 'Snapshots are not available.', previous: $e);
		}

		$rows = $this->objectService->searchObjects(
			query: array_merge(['@self' => ['register' => $registerId, 'schema' => $schemaId]], $filters),
			_rbac: false,
			_multitenancy: false
		);

		return array_map(fn (mixed $row): array => $this->normalise(object: $row), (array)$rows);
	}//end search()

	/**
	 * Load one object by id, or null when it does not exist.
	 *
	 * @param string $id The object id
	 * @param string $schemaSlug The schema slug
	 *
	 * @return array<string,mixed>|null The object
	 */
	private function findObject(string $id, string $schemaSlug): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: ApplicationVersionService::REGISTER_SLUG,
				schema: $schemaSlug
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->normalise(object: $object);
	}//end findObject()

	/**
	 * The id of a normalised row.
	 *
	 * @param array<string,mixed> $row The row
	 *
	 * @return string The id, or ''
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
	}//end idOf()

	/**
	 * Coerce an OpenRegister result to an array.
	 *
	 * @param mixed $object The result
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end normalise()
}//end class
