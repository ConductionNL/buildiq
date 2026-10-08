<?php

/**
 * Buildiq ExportAppSourceResolver
 *
 * Finds the application and the version an export job names.
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
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an application and picks the version an export job means.
 *
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
 */
class ExportAppSourceResolver {
	/**
	 * Canonical slug of Buildiq's own register.
	 *
	 * @var string
	 */
	private const CANONICAL_REGISTER = 'buildiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the application and its versions.
	 * @param RegisterSlugResolverInterface $slugResolver Which slug Buildiq's own register answers to here.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find the application and the version an export job names.
	 *
	 * The version is picked by slug when the job names one, else by semver
	 * (the production version first when several share it), else the
	 * production version, else the first version found.
	 *
	 * @param string $applicationUuid The application UUID.
	 * @param string $semver The version semver on the job.
	 * @param string $versionSlug The version slug on the job, '' when absent.
	 *
	 * @return array{application: array<string,mixed>, version: array<string,mixed>}|null Null when the application is unknown.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
	 */
	public function resolveSource(string $applicationUuid, string $semver, string $versionSlug = ''): ?array {
		$application = $this->loadApplication(applicationUuid: $applicationUuid);
		if ($application === null) {
			return null;
		}

		$versions = $this->loadVersions(applicationUuid: $applicationUuid);
		$version = $this->pickVersion(
			versions: $versions,
			productionUuid: (string)($application['productionVersion'] ?? ''),
			semver: $semver,
			versionSlug: $versionSlug
		);

		return [
			'application' => $application,
			'version' => ($version ?? []),
		];
	}//end resolveSource()

	/**
	 * Load the application record.
	 *
	 * @param string $applicationUuid The application UUID.
	 *
	 * @return array<string,mixed>|null The record, or null when it cannot be read.
	 */
	private function loadApplication(string $applicationUuid): ?array {
		try {
			$object = $this->objectService->find($applicationUuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not load application ' . $applicationUuid . ': ' . $e->getMessage());
			return null;
		}

		if ($object === null) {
			return null;
		}

		$data = $this->normalise(object: $object);
		if ($data === []) {
			return null;
		}

		return $data;
	}//end loadApplication()

	/**
	 * Load every version row of an application.
	 *
	 * Fetched unfiltered and matched here, because OpenRegister does not
	 * reliably filter on the `application` relation (the pattern
	 * GitHubAppSyncService::resolveVersion() already uses).
	 *
	 * @param string $applicationUuid The application UUID.
	 *
	 * @return array<int,array<string,mixed>> The versions.
	 */
	private function loadVersions(string $applicationUuid): array {
		$resolution = $this->slugResolver->resolve(canonical: self::CANONICAL_REGISTER);
		if ($resolution->isResolved() === false) {
			$this->logger->warning('Buildiq export: Buildiq\'s own register is not on this instance.');
			return [];
		}

		try {
			$results = $this->objectService->searchObjectsBySlug(
				(string)$resolution->slug,
				'applicationVersion',
				['_limit' => 1000],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not list application versions: ' . $e->getMessage());
			return [];
		}

		$versions = [];
		foreach ((array)$results as $result) {
			$row = $this->normalise(object: $result);
			if ((string)($row['application'] ?? '') === $applicationUuid) {
				$versions[] = $row;
			}
		}

		return $versions;
	}//end loadVersions()

	/**
	 * Pick the version an export job means.
	 *
	 * @param array<int,array<string,mixed>> $versions The application's versions.
	 * @param string $productionUuid The application's production version UUID.
	 * @param string $semver The semver on the job.
	 * @param string $versionSlug The version slug on the job.
	 *
	 * @return array<string,mixed>|null The version, or null when there are none.
	 */
	private function pickVersion(array $versions, string $productionUuid, string $semver, string $versionSlug): ?array {
		if ($versions === []) {
			return null;
		}

		// Production first, so a semver shared by two versions resolves to the published one.
		usort(
			$versions,
			fn (array $left, array $right): int => (int)($this->uuidOf(object: $right) === $productionUuid)
				- (int)($this->uuidOf(object: $left) === $productionUuid)
		);

		$candidates = [
			static fn (array $row): bool => $versionSlug !== '' && (string)($row['slug'] ?? '') === $versionSlug,
			static fn (array $row): bool => $semver !== '' && (string)($row['semver'] ?? '') === $semver,
		];
		foreach ($candidates as $matches) {
			foreach ($versions as $version) {
				if ($matches($version) === true) {
					return $version;
				}
			}
		}

		// Sorted production-first, so this is the production version when there is one.
		return $versions[0];
	}//end pickVersion()

	/**
	 * The UUID of a serialised object.
	 *
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string The UUID, '' when absent.
	 */
	private function uuidOf(array $object): string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true && (string)($self['id'] ?? '') !== '') {
			return (string)$self['id'];
		}

		return (string)($object['id'] ?? ($object['uuid'] ?? ''));
	}//end uuidOf()

	/**
	 * Coerce an OpenRegister result to an array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string,mixed> The array, empty when it is neither.
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
