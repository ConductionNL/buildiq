<?php

/**
 * Abstract base class for Buildiq MCP tool handlers.
 *
 * Provides the shared utilities (slug validation, auth check, error envelope,
 * toArray/extractUuid coercion, deep-link builder, per-Application RBAC) that
 * every concrete handler needs, eliminating duplication across the handler family.
 *
 * @category Service
 * @package  OCA\Buildiq\Mcp\Handler
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Mcp\Handler;

use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Abstract base for all Buildiq MCP tool handler classes.
 *
 * Concrete handlers extend this and implement handle(array $args): array.
 */
abstract class AbstractToolHandler {

	protected const REGISTER_SLUG = 'buildiq';

	/**
	 * Roles that grant write access to an Application.
	 *
	 * @var array<int, string>
	 */
	private const WRITE_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession User session used to resolve the current authenticated user.
	 * @param ContainerInterface $container DI container used to resolve OpenRegister services lazily.
	 * @param LoggerInterface $logger PSR logger used for non-fatal warnings and error logging.
	 * @param IGroupManager $groupManager Group manager used for admin and group membership checks.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object contract (ADR-084) — the ONE way a handler reaches OR data.
	 * @param PermissionResolver $permissionResolver Shared permission-grammar resolver (H1 fix).
	 * @param AuditTrailMapper|null $auditTrailMapper Optional OR audit-trail writer for admin-bypass parity (L2).
	 */
	public function __construct(
		protected readonly IUserSession $userSession,
		protected readonly ContainerInterface $container,
		protected readonly LoggerInterface $logger,
		protected readonly IGroupManager $groupManager,
		// PROTECTED, not private: subclasses used to resolve the very same
		// service out of the container by string name (ADR-083 rule 1
		// violation — the dependency was declared nowhere a reader or a gate
		// could see it) while this constructor-injected contract sat unused
		// beside them. One injected dependency, one way in.
		protected readonly ObjectServiceInterface $objectService,
		protected readonly ?PermissionResolver $permissionResolver = null,
		protected readonly ?AuditTrailMapper $auditTrailMapper = null,
	) {
	}//end __construct()

	/**
	 * Execute the tool logic for the given arguments.
	 *
	 * @param array<string, mixed> $args Raw MCP tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function handle(array $args): array;

	/**
	 * Build a uniform MCP error envelope.
	 *
	 * @param string $error Machine-readable error code.
	 * @param string $message Human-readable, end-user-safe error message.
	 *
	 * @return array{isError: true, error: string, message: string}
	 */
	protected function errorResult(string $error, string $message): array {
		return ['isError' => true, 'error' => $error, 'message' => $message];
	}//end errorResult()

	/**
	 * Return the authenticated user's UID, or null if there is no session user.
	 *
	 * @return string|null
	 */
	protected function requireAuthenticatedUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$uid = $user->getUID();
		if ($uid === '') {
			return null;
		}

		return $uid;
	}//end requireAuthenticatedUser()

	/**
	 * Check whether the current user is an NC admin.
	 *
	 * Returns a forbidden error envelope when the user is not signed in or is not
	 * an admin. Returns null on success.
	 *
	 * @return array{isError: true, error: string, message: string}|null Null on allow.
	 */
	protected function requireAdminUser(): ?array {
		$uid = $this->requireAuthenticatedUser();
		if ($uid === null) {
			return $this->errorResult(error: 'forbidden', message: 'You must be signed in.');
		}

		if ($this->groupManager->isAdmin($uid) === false) {
			return $this->errorResult(
				error: 'forbidden',
				message: 'This operation requires Nextcloud admin privileges.'
			);
		}

		return null;
	}//end requireAdminUser()

	/**
	 * Verify the current user holds an owners or editors role on the Application
	 * identified by $appSlug.
	 *
	 * When $allowAdminBypass is true (the default) NC admins pass without needing
	 * an explicit role entry. Set it to false for promotion (spec REQ-OBVP-007).
	 *
	 * Returns a forbidden/not_found error envelope on denial, null on allow.
	 *
	 * @param string $appSlug Slug of the target Application.
	 * @param bool $allowAdminBypass Whether NC admin group membership grants access.
	 *
	 * @return array{isError: true, error: string, message: string}|null Null on allow.
	 */
	protected function requireWriteRole(string $appSlug, bool $allowAdminBypass = true): ?array {
		$uid = $this->requireAuthenticatedUser();
		if ($uid === null) {
			return $this->errorResult(error: 'forbidden', message: 'You must be signed in.');
		}

		// Openbuild is a system-wide register (not org-scoped); bypass the
		// organisation filter so MCP callers in any org can resolve apps.
		$apps = $this->objectService->searchObjectsBySlug(
			self::REGISTER_SLUG,
			'application',
			['slug' => $appSlug],
			_rbac: true,
			_multitenancy: false
		);
		if (is_array($apps) === false || $apps === []) {
			return $this->errorResult(error: 'not_found', message: "No virtual app found for slug '{$appSlug}'.");
		}

		$app = $this->toArray(item: $apps[0]);

		if ($this->callerHasWriteRole(app: $app, uid: $uid, allowAdminBypass: $allowAdminBypass) === true) {
			// Record only a *genuine* admin bypass: an admin who would NOT pass
			// without admin-group membership (no owner/editor role on this app).
			// An admin who also holds a real role is exercising a legitimate grant,
			// not a bypass — auditing it would produce false compliance records
			// (harden-rules-authz-and-audit-parity, L2 / #5).
			$genuineBypass = $allowAdminBypass === true
				&& $this->groupManager->isAdmin($uid) === true
				&& $this->callerHasWriteRole(app: $app, uid: $uid, allowAdminBypass: false) === false;
			if ($genuineBypass === true) {
				// Re-resolve the app as an ObjectEntity for the audit write.
				// searchObjectsBySlug returns rendered arrays (not ObjectEntity)
				// when the schema has property-level authorization or _extend/
				// _fields are in play, which would otherwise skip the audit; find()
				// returns a hydrated ObjectEntity regardless, at parity with the
				// Copilot path (harden-rules-authz-and-audit-parity, L2 / #3).
				$appEntity = null;
				try {
					$appEntity = $this->objectService->find((string)($app['uuid'] ?? ($app['id'] ?? '')));
				} catch (\Throwable $e) {
					$appEntity = null;
				}

				$this->recordAdminBypass(appEntity: $appEntity, appSlug: $appSlug, uid: $uid);
			}

			return null;
		}//end if

		return $this->errorResult(
			error: 'forbidden',
			message: "You do not have owner or editor access to application '{$appSlug}'."
		);

	}//end requireWriteRole()

	/**
	 * Record an MCP admin-bypass to the OpenRegister per-object audit trail, at
	 * parity with the HTTP path (REQ-OBRBAC-007). Falls back to a PSR info log
	 * when the audit mapper or the app ObjectEntity is unavailable; an
	 * audit-write failure is logged at critical (a compliance gap) but never
	 * aborts the bypassed operation (harden-rules-authz-and-audit-parity, L2).
	 *
	 * @param mixed $appEntity The resolved Application (an ObjectEntity when available).
	 * @param string $appSlug The app slug.
	 * @param string $uid The admin actor UID.
	 *
	 * @return void
	 */
	protected function recordAdminBypass(mixed $appEntity, string $appSlug, string $uid): void {
		$context = [
			'event' => 'rbac.admin_bypass',
			'actor' => $uid,
			'appSlug' => $appSlug,
			'channel' => 'mcp',
		];

		if ($this->auditTrailMapper !== null && $appEntity instanceof ObjectEntity) {
			try {
				$this->auditTrailMapper->createAuditTrailEntry(
					object: $appEntity,
					action: 'rbac.admin_bypass',
					context: $context
				);
				// Mirror to PSR at info so the bypass is also visible in log streams.
				$this->logger->info('Buildiq MCP: rbac.admin_bypass', $context);
				return;
			} catch (\Throwable $e) {
				// Audit-trail write failure is a compliance gap (system of record),
				// not a routine warning — emit at critical for ops alerting.
				$this->logger->critical(
					'Buildiq MCP: rbac.admin_bypass audit-trail write failed',
					array_merge($context, ['exception' => $e->getMessage()])
				);
				return;
			}
		}

		// No audit mapper / entity available — best-effort PSR log only.
		$this->logger->info('Buildiq MCP: rbac.admin_bypass', $context);

	}//end recordAdminBypass()

	/**
	 * Verify the current user holds ANY role (owner, editor, or viewer) on the Application.
	 *
	 * Used for read-level MCP tools (C1/C2 fix). NC admin bypass is NOT applied here
	 * — read access still requires an explicit role entry.
	 *
	 * Returns null on allow; forbidden/not_found error envelope on denial.
	 *
	 * @param array<string, mixed> $app Application data array.
	 *
	 * @return array{isError: true, error: string, message: string}|null Null on allow.
	 */
	protected function requireAnyRoleOnApp(array $app): ?array {
		$uid = $this->requireAuthenticatedUser();
		$caller = $this->userSession->getUser();
		if ($uid === null || $caller === null) {
			return $this->errorResult(error: 'forbidden', message: 'You must be signed in.');
		}

		$permissions = ($app['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		if ($this->hasAnyRole(app: $app, permissions: $permissions, caller: $caller, uid: $uid) === true) {
			return null;
		}

		return $this->errorResult(
			error: 'forbidden',
			message: 'You do not have access to this application.'
		);

	}//end requireAnyRoleOnApp()

	/**
	 * Whether the caller holds ANY role on the Application.
	 *
	 * Extracted from {@see self::requireAnyRoleOnApp()}: the resolver-backed
	 * and legacy paths both yield a bool, so an early return expresses the
	 * choice without an else branch. Deny-by-default is preserved — every path
	 * still returns an explicit bool from a real check.
	 *
	 * @param array<string, mixed> $app Application data array.
	 * @param array<string, mixed> $permissions The Application's permissions block.
	 * @param IUser $caller The authenticated caller.
	 * @param string $uid The caller's UID.
	 *
	 * @return bool True when the caller holds owner, editor or viewer.
	 */
	private function hasAnyRole(array $app, array $permissions, IUser $caller, string $uid): bool {
		if ($this->permissionResolver === null) {
			return $this->callerHasWriteRole(app: $app, uid: $uid, allowAdminBypass: true);
		}

		$userGroups = $this->permissionResolver->resolveUserGroups($caller);

		return $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: $userGroups,
			allowAdminBypass: true,
			roles: ['owners', 'editors', 'viewers']
		);

	}//end hasAnyRole()

	/**
	 * Check whether the session caller holds any WRITE_ROLES entry on the Application.
	 *
	 * Delegates to PermissionResolver when available (H1 fix — unified grammar);
	 * falls back to the inline implementation for backward-compatibility in tests
	 * that construct handlers without the resolver.
	 *
	 * @param array<string, mixed> $app Application data.
	 * @param string $uid Caller's user ID.
	 * @param bool $allowAdminBypass Whether NC admin bypass applies.
	 *
	 * @return bool
	 */
	private function callerHasWriteRole(array $app, string $uid, bool $allowAdminBypass = true): bool {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return false;
		}

		$permissions = ($app['permissions'] ?? []);
		if (is_array($permissions) === false) {
			return false;
		}

		if ($this->permissionResolver !== null) {
			$userGroups = $this->permissionResolver->resolveUserGroups($caller);
			return $this->permissionResolver->matchesCaller(
				permissions: $permissions,
				caller: $caller,
				userGroups: $userGroups,
				allowAdminBypass: $allowAdminBypass,
				roles: self::WRITE_ROLES
			);
		}

		// Inline fallback (no resolver injected — used by tests that pre-date
		// the resolver injection).
		$userSet = [];
		$groupSet = [];

		foreach (self::WRITE_ROLES as $role) {
			$bucket = ($permissions[$role] ?? []);
			if (is_array($bucket) === false) {
				continue;
			}

			foreach ($bucket as $principal) {
				if (is_string($principal) === false || $principal === '') {
					continue;
				}

				if (str_starts_with($principal, 'user:') === true) {
					$pUid = substr($principal, 5);
					if ($pUid !== '') {
						$userSet[$pUid] = true;
					}

					continue;
				}

				$gid = $principal;
				if (str_starts_with($principal, 'group:') === true) {
					$gid = substr($principal, 6);
				}

				if ($gid !== '') {
					$groupSet[$gid] = true;
				}
			}//end foreach
		}//end foreach

		if (isset($userSet[$uid]) === true) {
			return true;
		}

		if ($allowAdminBypass === true && $this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		$userGroups = $this->groupManager->getUserGroups($caller);
		foreach ($userGroups as $group) {
			if ($group instanceof IUser === false && isset($groupSet[$group->getGID()]) === true) {
				return true;
			}
		}

		return false;
	}//end callerHasWriteRole()

	/**
	 * Validate that a candidate string matches the Buildiq slug shape.
	 *
	 * @param string $candidate Candidate slug to validate.
	 *
	 * @return bool
	 */
	protected function isValidSlug(string $candidate): bool {
		if (strlen($candidate) < 2 || strlen($candidate) > 48) {
			return false;
		}

		return (bool)preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $candidate);
	}//end isValidSlug()

	/**
	 * Check manifest-size growth caps before a write.
	 *
	 * Enforces H4 caps:
	 *   - max 256 KB total manifest JSON size
	 *   - max 100 pages per manifest
	 *   - max 30 menu items per manifest
	 *   - max 50 widgets per page (pass $pageIdx for page-scoped check)
	 *
	 * Returns an `invalid_arguments` error envelope when a cap would be exceeded;
	 * null when all caps are satisfied.
	 *
	 * @param array<string, mixed> $manifest The NEW manifest after the proposed write.
	 * @param int|null $pageIdx Index of the page being written to (widgets cap).
	 *
	 * @return array{isError: true, error: string, message: string}|null Null on pass.
	 */
	protected function checkManifestCaps(array $manifest, ?int $pageIdx = null): ?array {
		// 256 KB total size cap.
		$json = json_encode($manifest);
		if ($json !== false && strlen($json) > 256 * 1024) {
			return $this->errorResult(
				error: 'invalid_arguments',
				message: 'Manifest exceeds maximum size of 256 KB.'
			);
		}

		// 100 pages per manifest.
		$pages = (array)($manifest['pages'] ?? []);
		if (count($pages) > 100) {
			return $this->errorResult(
				error: 'invalid_arguments',
				message: 'Manifest exceeds maximum of 100 pages.'
			);
		}

		// 30 menu items per manifest.
		$menu = (array)($manifest['menu'] ?? []);
		if (count($menu) > 30) {
			return $this->errorResult(
				error: 'invalid_arguments',
				message: 'Manifest exceeds maximum of 30 menu items.'
			);
		}

		// 50 widgets per page (only when a specific page is being written).
		if ($pageIdx !== null && isset($pages[$pageIdx]) === true) {
			$pageConfig = (array)($pages[$pageIdx]['config'] ?? []);
			$widgets = (array)($pageConfig['widgets'] ?? []);
			if (count($widgets) > 50) {
				return $this->errorResult(
					error: 'invalid_arguments',
					message: 'Page exceeds maximum of 50 widgets.'
				);
			}
		}

		return null;
	}//end checkManifestCaps()

	/**
	 * Build a Nextcloud deep link into the Buildiq builder for the given application slug.
	 *
	 * @param string $slug Application slug (empty falls back to the app root).
	 *
	 * @return string
	 */
	protected function buildDeepLink(string $slug): string {
		if ($slug === '') {
			return '/apps/buildiq';
		}

		return "/apps/buildiq/builder/{$slug}";
	}//end buildDeepLink()

	/**
	 * Build an MCP "source" descriptor pointing at the Buildiq app deep link.
	 *
	 * @param string $uuid Application UUID.
	 * @param string $slug Application slug used to build the deep link.
	 * @param string $label Human-readable label for the source descriptor.
	 *
	 * @return array{type: string, uuid: string, url: string, label: string}
	 */
	protected function sourceDescriptor(string $uuid, string $slug, string $label): array {
		return ['type' => 'buildiq.application', 'uuid' => $uuid, 'url' => $this->buildDeepLink(slug: $slug), 'label' => $label];
	}//end sourceDescriptor()

	/**
	 * Coerce an OR entity, array, or generic value into an associative array.
	 *
	 * @param mixed $item Value to coerce.
	 *
	 * @return array<string, mixed>
	 */
	protected function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			$serialised = $item->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return (array)$item;
	}//end toArray()

	/**
	 * Extract a UUID from a normalised OR object array.
	 *
	 * @param array<string, mixed> $item Normalised OR object as an associative array.
	 *
	 * @return string
	 */
	protected function extractUuid(array $item): string {
		$uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
		return (string)$uuid;
	}//end extractUuid()

	/**
	 * Resolve <appSlug, versionSlug> to {version, appUuid, appName}, or {error, message}.
	 *
	 * @param object $objectService OpenRegister ObjectService instance.
	 * @param string $appSlug Application slug to resolve.
	 * @param string $versionSlug ApplicationVersion slug to resolve.
	 *
	 * @return array{version?: array, appUuid?: string, appName?: string, error?: string, message?: string}
	 */
	protected function loadVersion(object $objectService, string $appSlug, string $versionSlug): array {
		$apps = $objectService->searchObjectsBySlug(self::REGISTER_SLUG, 'application', ['slug' => $appSlug], _rbac: true, _multitenancy: false);
		if (is_array($apps) === false || $apps === []) {
			return ['error' => 'not_found', 'message' => "No virtual app found for slug '{$appSlug}'."];
		}

		$app = $this->toArray(item: $apps[0]);
		$appUuid = $this->extractUuid(item: $app);

		$versions = $objectService->searchObjectsBySlug(
			self::REGISTER_SLUG,
			'applicationVersion',
			['application' => $appUuid, 'slug' => $versionSlug],
			_rbac: true,
			_multitenancy: false
		);
		if (is_array($versions) === false || $versions === []) {
			return ['error' => 'not_found', 'message' => "No version '{$versionSlug}' found for app '{$appSlug}'."];
		}

		return [
			'version' => $this->toArray(item: $versions[0]),
			'appUuid' => $appUuid,
			'appName' => (string)($app['name'] ?? $appSlug),
		];

	}//end loadVersion()

	/**
	 * Save an ApplicationVersion with a new manifest, protected by an OR
	 * object lock to serialise concurrent manifest mutations (issue #159).
	 *
	 * The lock is acquired before the read-modify-write cycle. If OR returns
	 * a contention error (another agent holds the lock), a
	 * RuntimeException with code 409 is thrown so callers can return an
	 * `isError` envelope without retrying blindly.
	 *
	 * H3: The `method_exists` guard that previously silently skipped locking
	 * when ObjectService lacked `lockObject` has been removed. OR has shipped
	 * `lockObject` since the concurrency-fix release; failing loudly (503) is
	 * safer than silently allowing last-writer-wins data loss.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister ObjectService instance.
	 * @param array<string, mixed> $version The existing ApplicationVersion as an associative array.
	 * @param array<string, mixed> $manifest The new manifest blob to write onto the version.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException (code 409) when the version is locked by another writer.
	 * @throws \RuntimeException (code 503) when the ObjectService does not provide lockObject.
	 */
	protected function saveVersionManifest(ObjectServiceInterface $objectService, array $version, array $manifest): array {
		$versionUuid = $this->extractUuid(item: $version);

		// Acquire an OR optimistic lock before the write to prevent last-writer-
		// wins data loss when two concurrent MCP agents mutate the same version.
		// H3: guard removed — fail loudly (503) rather than silently skip locking.
		// A lock failure throws out of this block, so the write below only ever
		// runs with the lock held — no `$locked` flag is needed to decide whether
		// the finally must release it.
		try {
			$objectService->lockObject(
				identifier: $versionUuid,
				process: 'buildiq.mcp-manifest-edit',
				duration: 30
			);
		} catch (\Throwable $lockError) {
			$this->logger->warning(
				'Buildiq MCP: manifest lock contention on version ' . $versionUuid,
				['exception' => $lockError->getMessage()]
			);
			throw new RuntimeException(
				'Version ' . $versionUuid . ' is currently locked by another writer. Retry after a moment.',
				409,
				$lockError
			);
		}

		try {
			$payload = $version;
			$payload['manifest'] = $manifest;

			// Drop OR-internal `@self` / metadata keys that some readers tack on so
			// saveObject treats the input as a clean property bag.
			unset($payload['@self'], $payload['id'], $payload['uuid']);

			$saved = $objectService->saveObject(
				object: $payload,
				register: self::REGISTER_SLUG,
				schema: 'applicationVersion',
				uuid: $versionUuid,
			);

			return $this->toArray(item: $saved);
		} finally {
			try {
				$objectService->unlockObject(identifier: $versionUuid);
			} catch (\Throwable $unlockError) {
				$this->logger->warning(
					'Buildiq MCP: failed to release manifest lock on ' . $versionUuid,
					['exception' => $unlockError->getMessage()]
				);
			}
		}//end try

	}//end saveVersionManifest()
}//end class
