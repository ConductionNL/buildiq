<?php

/**
 * Buildiq VersionBranchService
 *
 * "Start a branch" (lifecycle-git-branches, REQ-BQGB-001): a new draft
 * version with a copy of the source version's manifest, its schemas copied
 * into a register of its own without any records, a `branchBase` recording
 * where it started, and a `githubBranch` when the app is linked to GitHub.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Branch;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Support\ObjectRecord;

/**
 * Starts a branch from a version.
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
 */
class VersionBranchService {

	/**
	 * The shape of a branch name: a version slug, which is also a valid Git ref.
	 */
	public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,46}[a-z0-9]$/';

	/**
	 * Constructor.
	 *
	 * @param RegisterStore             $store    OpenRegister's object service and register mapper.
	 * @param VersionSchemaWriter       $schemas  Writes the copied schemas into the branch register.
	 * @param VersionState              $state    The neutral snapshot of the source.
	 * @param ApplicationVersionService $versions Hashes the manifest and stamps the semver.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterStore $store,
		private readonly VersionSchemaWriter $schemas,
		public readonly VersionState $state,
		public readonly ApplicationVersionService $versions,
	) {
	}//end __construct()

	/**
	 * Start a branch named $name from $source.
	 *
	 * @param array<string,mixed> $application The Application (`id`/`uuid`, `slug`, optional `githubRepo`).
	 * @param array<string,mixed> $source      The version branched from.
	 * @param string              $name        The branch name, also its version slug.
	 *
	 * @return array<string,mixed> The new version.
	 *
	 * @throws VersionBranchRefused When the name is not a valid slug (400) or its register is taken (409).
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
	 */
	public function start(array $application, array $source, string $name): array {
		if (preg_match(self::NAME_PATTERN, $name) !== 1) {
			throw new VersionBranchRefused(errorCode: 'invalid_name', detail: 'A branch name is lowercase letters, digits and dashes.', status: 400);
		}

		$snapshot = $this->state->read(version: $source);
		$register = $this->createRegister(appSlug: (string)($application['slug'] ?? ''), name: $name);
		$this->schemas->write(version: ['register' => $register], schemas: $snapshot['schemas']);
		$manifest = $this->state->restore(manifest: $snapshot['manifest'], version: ['register' => $register]);

		$branch  = ['slug' => $name, 'register' => $register, 'manifest' => $manifest];
		$payload = $this->versions->onSave(current: null, next: $this->branchPayload(application: $application, source: $source, version: $branch));
		$payload['branchBase'] = $this->baseOf(source: $source, snapshot: $snapshot);

		return ObjectRecord::toArray(
			object: $this->store->objectService->saveObject(
				object: $payload,
				register: ApplicationVersionServiceInterface::REGISTER_SLUG,
				schema: ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA
			)
		);
	}//end start()

	/**
	 * The `branchBase` of a version started from, or merged with, $source.
	 *
	 * @param array<string,mixed>                                                  $source   The version.
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $snapshot Its neutral state.
	 *
	 * @return array{source: string, manifestHash: string, manifest: array<string,mixed>, schemas: array<string,mixed>}
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
	 */
	public function baseOf(array $source, array $snapshot): array {
		$manifest = [];
		if (is_array($source['manifest'] ?? null) === true) {
			$manifest = $source['manifest'];
		}

		return [
			'source' => (string)($source['slug'] ?? ''),
			'manifestHash' => $this->versions->hashManifest(manifest: $manifest),
			'manifest' => $snapshot['manifest'],
			'schemas' => $snapshot['schemas'],
		];
	}//end baseOf()

	/**
	 * The new version's object data, before the save hook.
	 *
	 * @param array<string,mixed>                                                   $application The Application.
	 * @param array<string,mixed>                                                   $source      The version branched from.
	 * @param array{slug: string, register: string, manifest: mixed}                $version     The branch's slug, register and manifest.
	 *
	 * @return array<string,mixed>
	 */
	private function branchPayload(array $application, array $source, array $version): array {
		$payload = [
			'name' => $version['slug'],
			'slug' => $version['slug'],
			'application' => (string)($application['id'] ?? $application['uuid'] ?? ''),
			'manifest' => $version['manifest'],
			'register' => $version['register'],
			'status' => 'draft',
			'scope' => ($source['scope'] ?? null),
		];
		if (is_array($application['githubRepo'] ?? null) === true && (string)($application['githubRepo']['name'] ?? '') !== '') {
			$payload['githubBranch'] = $version['slug'];
		}

		return array_filter($payload, static fn (mixed $value): bool => $value !== null);
	}//end branchPayload()

	/**
	 * Create the branch's own register, refusing a name another register already has.
	 *
	 * @param string $appSlug The Application slug.
	 * @param string $name    The branch name.
	 *
	 * @return string The register slug.
	 *
	 * @throws VersionBranchRefused When the register exists.
	 */
	private function createRegister(string $appSlug, string $name): string {
		$slug = ApplicationVersionServiceInterface::VERSION_REGISTER_PREFIX . $appSlug . '-' . $name;
		try {
			$this->store->registerMapper->find($slug, _multitenancy: false);
			$taken = true;
		} catch (\Throwable $e) {
			$taken = false;
		}

		if ($taken === true) {
			throw new VersionBranchRefused(errorCode: 'name_taken', detail: 'A version named "' . $name . '" already exists.');
		}

		$this->store->registerMapper->createFromArray(
			[
				'slug' => $slug,
				'title' => 'Buildiq — ' . $appSlug . ' (' . $name . ')',
				'description' => 'Per-version schema namespace for Buildiq app `' . $appSlug . '` branch `' . $name . '`.',
				'version' => '0.1.0',
				'schemas' => [],
			]
		);

		return $slug;
	}//end createRegister()
}//end class
