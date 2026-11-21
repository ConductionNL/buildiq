<?php

/**
 * Buildiq ManifestBaseResolver
 *
 * Turns an ApplicationVersion into the manifest it serves. A version without a
 * `baseRef` is a legacy or standalone app: its stored `manifest` blob is the
 * manifest, returned unchanged. A version with a `baseRef` names what it
 * extends; the base is read live (or at its pin) and the version's
 * `manifestDelta` is merged over it with {@see ManifestDeltaMerger}.
 *
 * A base can be a template, another Buildiq app (its production version, or a
 * pinned released version), a fleet app's bundled `src/manifest.json`, or one
 * ApplicationVersion by uuid. A base that extends a base is resolved the same
 * way, up to {@see self::MAX_DEPTH} hops; a cycle or a longer chain stops at
 * the last version reached, which then serves its own stored blob. Any base
 * that cannot be read falls back to the stored blob as well, so a failure
 * never blanks an app that served before.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a version's `baseRef` + `manifestDelta` into one merged manifest.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
 */
class ManifestBaseResolver {
	/**
	 * The most base hops one manifest may take before resolution stops.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 5;

	private const REGISTER = 'buildiq';

	private const TEMPLATE_SCHEMA = 'application-template';

	private const APPLICATION_SCHEMA = 'built-app';

	private const VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param LoggerInterface        $logger        Records why a base could not be used.
	 * @param ManifestDeltaMerger    $merger        The keyed merge of the JS contract.
	 * @param IAppManager|null       $appManager    Locates a fleet app's bundled manifest; without it no fleet base resolves.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly ManifestDeltaMerger $merger=new ManifestDeltaMerger(),
		private readonly ?IAppManager $appManager=null,
	) {
	}//end __construct()

	/**
	 * The manifest a version serves: its blob without a base, else base plus delta.
	 *
	 * @param array<string, mixed> $version The ApplicationVersion.
	 * @param callable|null        $visible Whether the caller may build on an Application (organisation scope);
	 *                                      null admits every app.
	 *
	 * @return mixed The manifest, or the stored blob (whatever it holds) when no base applies.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.1
	 */
	public function manifestOf(array $version, ?callable $visible=null): mixed {
		return $this->resolveVersion(version: $version, visible: $visible)['manifest'];
	}//end manifestOf()

	/**
	 * Resolve a version, with the orphaned delta paths and the reasons a base was not used.
	 *
	 * @param array<string, mixed> $version The ApplicationVersion.
	 * @param callable|null        $visible Whether the caller may build on an Application; null admits every app.
	 * @param list<string>         $seen    The base references already on this chain.
	 *
	 * @return array{manifest: mixed, orphanedDeltaPaths: list<string>, diagnostics: list<string>}
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.2
	 */
	public function resolveVersion(array $version, ?callable $visible=null, array $seen=[]): array {
		$blob = ($version['manifest'] ?? null);
		$baseRef = $this->referenceOf(version: $version);
		if ($baseRef === null) {
			return $this->result(manifest: $blob);
		}

		$key = $baseRef['kind'] . ':' . $baseRef['id'];
		if (in_array($key, $seen, true) === true || count($seen) >= self::MAX_DEPTH) {
			return $this->result(manifest: $blob, diagnostics: [$this->note(reason: 'the base chain loops or is too deep at ' . $key)]);
		}

		$base = $this->resolveBase(baseRef: $baseRef, visible: $visible, seen: [...$seen, $key]);
		if (is_array($base['manifest']) === false) {
			return $this->result(manifest: $blob, diagnostics: [...$base['diagnostics'], $this->note(reason: 'base ' . $key . ' could not be read')]);
		}

		$delta = ($version['manifestDelta'] ?? []);
		if (is_array($delta) === false) {
			$delta = [];
		}

		$merged = $this->merger->merge(base: $base['manifest'], delta: $delta);

		return $this->result(
			manifest: $merged['manifest'],
			orphans: [...$base['orphanedDeltaPaths'], ...$merged['orphanedDeltaPaths']],
			diagnostics: $base['diagnostics']
		);
	}//end resolveVersion()

	/**
	 * The manifest a base reference points at.
	 *
	 * @param array{kind: string, id: string, manifestVersion: string} $baseRef The reference.
	 * @param callable|null                                            $visible Whether the caller may build on an
	 *                                                                          Application.
	 * @param list<string>                                             $seen    The references on this chain, this
	 *                                                                          one included.
	 *
	 * @return array{manifest: mixed, orphanedDeltaPaths: list<string>, diagnostics: list<string>}
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function resolveBase(array $baseRef, ?callable $visible=null, array $seen=[]): array {
		$id = $baseRef['id'];
		$pin = $baseRef['manifestVersion'];

		$version = match ($baseRef['kind']) {
			'template' => null,
			'fleet-app' => null,
			'buildiq-app' => $this->appVersion(appRef: $id, pin: $pin, visible: $visible),
			'application-version' => $this->versionByUuid(uuid: $id),
			default => null,
		};
		if ($version !== null) {
			return $this->resolveVersion(version: $version, visible: $visible, seen: $seen);
		}

		$manifest = match ($baseRef['kind']) {
			'template' => $this->templateManifest(templateRef: $id, pin: $pin),
			'fleet-app' => $this->fleetManifest(appId: $id, pin: $pin),
			default => null,
		};

		return $this->result(manifest: $manifest);
	}//end resolveBase()

	/**
	 * The version's base reference, or null when it has none (a legacy blob app).
	 *
	 * @param array<string, mixed> $version The ApplicationVersion.
	 *
	 * @return array{kind: string, id: string, manifestVersion: string}|null
	 */
	private function referenceOf(array $version): ?array {
		$baseRef = ($version['baseRef'] ?? null);
		if (is_array($baseRef) === false) {
			return null;
		}

		$kind = trim((string)($baseRef['kind'] ?? ''));
		$id = trim((string)($baseRef['id'] ?? ''));
		if ($kind === '' || $id === '') {
			return null;
		}

		return ['kind' => $kind, 'id' => $id, 'manifestVersion' => trim((string)($baseRef['manifestVersion'] ?? ''))];
	}//end referenceOf()

	/**
	 * A template's manifest by slug or uuid, at its pin when one is set.
	 *
	 * @param string $templateRef The template slug or uuid.
	 * @param string $pin         The version it must carry, or '' for any.
	 *
	 * @return array<string, mixed>|null
	 */
	private function templateManifest(string $templateRef, string $pin): ?array {
		$template = $this->firstBySlug(schema: self::TEMPLATE_SCHEMA, filters: ['slug' => $templateRef]);
		if ($template === null) {
			$template = $this->findByUuid(schema: self::TEMPLATE_SCHEMA, uuid: $templateRef);
		}

		if ($template === null || $this->pinMatches(pin: $pin, actual: $template['version'] ?? null) === false) {
			return null;
		}

		return ObjectRecord::arrayOrNull(object: $template['manifest'] ?? null);
	}//end templateManifest()

	/**
	 * The version of another Buildiq app a base names: its pinned released version, else production.
	 *
	 * @param string        $appRef  The app slug or uuid.
	 * @param string        $pin     The version slug or semver it is pinned to, or '' to follow production.
	 * @param callable|null $visible Whether the caller may build on the app.
	 *
	 * @return array<string, mixed>|null
	 */
	private function appVersion(string $appRef, string $pin, ?callable $visible): ?array {
		$application = $this->firstBySlug(schema: self::APPLICATION_SCHEMA, filters: ['slug' => $appRef]);
		if ($application === null) {
			$application = $this->findByUuid(schema: self::APPLICATION_SCHEMA, uuid: $appRef);
		}

		if ($application === null || ($visible !== null && $visible($application) !== true)) {
			return null;
		}

		$appUuid = (string)($application['uuid'] ?? $application['id'] ?? '');
		if ($pin !== '') {
			return $this->pinnedVersion(appUuid: $appUuid, pin: $pin);
		}

		$production = ($application['productionVersion'] ?? null);
		if (is_array($production) === true) {
			$production = (string)($production['uuid'] ?? $production['id'] ?? '');
		}

		$version = $this->versionByUuid(uuid: (string)$production);
		if ($version === null || $this->belongsTo(version: $version, appUuid: $appUuid) === false) {
			return null;
		}

		return $version;
	}//end appVersion()

	/**
	 * A released version of an app by slug or semver; a draft is never a base.
	 *
	 * @param string $appUuid The app's uuid.
	 * @param string $pin     The version slug or semver.
	 *
	 * @return array<string, mixed>|null
	 */
	private function pinnedVersion(string $appUuid, string $pin): ?array {
		foreach (['slug', 'semver'] as $field) {
			$version = $this->firstBySlug(schema: self::VERSION_SCHEMA, filters: ['application' => $appUuid, $field => $pin]);
			if ($version !== null
				&& $this->belongsTo(version: $version, appUuid: $appUuid) === true
				&& ($version['status'] ?? 'draft') !== 'draft'
			) {
				return $version;
			}
		}

		return null;
	}//end pinnedVersion()

	/**
	 * A fleet app's bundled manifest (`src/manifest.json` in its app folder), at its pin when one is set.
	 *
	 * @param string $appId The fleet app id.
	 * @param string $pin   The manifest `version` it must carry, or '' for any.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fleetManifest(string $appId, string $pin): ?array {
		if ($this->appManager === null || preg_match('/^[a-z0-9_-]+$/', $appId) !== 1) {
			return null;
		}

		try {
			$path = rtrim($this->appManager->getAppPath($appId), '/') . '/src/manifest.json';
			$text = is_readable($path) === true ? file_get_contents($path) : false;
			$manifest = is_string($text) === true ? json_decode($text, true) : null;
		} catch (Throwable $e) {
			$this->logger->debug('ManifestBaseResolver: no bundled manifest for {app}: {message}', ['app' => $appId, 'message' => $e->getMessage()]);
			return null;
		}

		if (is_array($manifest) === false || $this->pinMatches(pin: $pin, actual: $manifest['version'] ?? null) === false) {
			return null;
		}

		return $manifest;
	}//end fleetManifest()

	/**
	 * One ApplicationVersion by uuid.
	 *
	 * @param string $uuid The version uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function versionByUuid(string $uuid): ?array {
		return $this->findByUuid(schema: self::VERSION_SCHEMA, uuid: $uuid);
	}//end versionByUuid()

	/**
	 * Whether a version's `application` relation names this app.
	 *
	 * @param array<string, mixed> $version The version.
	 * @param string               $appUuid The app's uuid.
	 *
	 * @return bool
	 */
	private function belongsTo(array $version, string $appUuid): bool {
		$relation = ($version['application'] ?? null);
		if (is_array($relation) === true) {
			$relation = ($relation['uuid'] ?? $relation['id'] ?? null);
		}

		return $appUuid !== '' && (string)$relation === $appUuid;
	}//end belongsTo()

	/**
	 * Whether a stored version matches a pin; no pin matches anything.
	 *
	 * @param string $pin    The pin, or ''.
	 * @param mixed  $actual The stored version.
	 *
	 * @return bool
	 */
	private function pinMatches(string $pin, mixed $actual): bool {
		return $pin === '' || (is_scalar($actual) === true && (string)$actual === $pin);
	}//end pinMatches()

	/**
	 * The first object of a schema in Buildiq's register matching the filters.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function firstBySlug(string $schema, array $filters): ?array {
		try {
			$rows = $this->objectService->searchObjectsBySlug(self::REGISTER, $schema, $filters, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->debug('ManifestBaseResolver: lookup in {schema} failed: {message}', ['schema' => $schema, 'message' => $e->getMessage()]);
			return null;
		}

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		return ObjectRecord::arrayOrNull(object: reset($rows));
	}//end firstBySlug()

	/**
	 * One object of a schema in Buildiq's register by uuid.
	 *
	 * Read through the slug search with a metadata filter, which every
	 * OpenRegister version of the contract takes the same way.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid   The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findByUuid(string $schema, string $uuid): ?array {
		if ($uuid === '') {
			return null;
		}

		return $this->firstBySlug(schema: $schema, filters: ['@self' => ['uuid' => $uuid]]);
	}//end findByUuid()

	/**
	 * Log why a base was not used and return the reason.
	 *
	 * @param string $reason What went wrong.
	 *
	 * @return string The reason.
	 */
	private function note(string $reason): string {
		$this->logger->warning('ManifestBaseResolver: ' . $reason . '; serving the stored manifest.');
		return $reason;
	}//end note()

	/**
	 * One resolution result.
	 *
	 * @param mixed        $manifest    The manifest.
	 * @param list<string> $orphans     Delta paths that targeted nothing in the base.
	 * @param list<string> $diagnostics Why a base was not used.
	 *
	 * @return array{manifest: mixed, orphanedDeltaPaths: list<string>, diagnostics: list<string>}
	 */
	private function result(mixed $manifest, array $orphans=[], array $diagnostics=[]): array {
		return ['manifest' => $manifest, 'orphanedDeltaPaths' => $orphans, 'diagnostics' => $diagnostics];
	}//end result()
}//end class
