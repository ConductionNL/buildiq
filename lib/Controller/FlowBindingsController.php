<?php

/**
 * Buildiq FlowBindingsController
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Wires an application's flow bindings: after the settings dialog saves
 * `Application.flows`, the client asks here for the wrapper flows of its
 * `runOn` entries (REQ-BQSF-002). Only an owner of the app may, the same
 * role the settings dialog already requires to change the bindings.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Kernel\Contracts\VersionPromotionServiceInterface;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\FlowBindingWrapperService;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saves the wrapper flows of an application's flow bindings.
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */
class FlowBindingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request            The request.
	 * @param FlowBindingWrapperService   $wrappers           Keeps the wrapper flows in step.
	 * @param ObjectServiceInterface      $objectService      Loads the Application and its production version.
	 * @param IUserSession                $userSession        The caller.
	 * @param PermissionResolverInterface $permissionResolver Matches the caller against the app's owners.
	 * @param LoggerInterface             $logger             Records a failed sync.
	 * @param AppOrganisationGuard|null   $organisationGuard  Keeps another organisation's scoped app out of reach.
	 */
	public function __construct(
		IRequest $request,
		private readonly FlowBindingWrapperService $wrappers,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly LoggerInterface $logger,
		private readonly ?AppOrganisationGuard $organisationGuard=null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Save, publish and bind the wrapper flows of the app's `runOn` entries, and remove stale ones.
	 *
	 * RBAC inside: the caller must be in the app's `owners` (with the
	 * administrator bypass the other owner-only application actions have).
	 *
	 * @param string $appUuid The Application's uuid.
	 *
	 * @return JSONResponse `{flows, created, removed, failed}`; 401, 403, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function sync(string $appUuid): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$application = $this->load(uuid: $appUuid, schema: VersionPromotionServiceInterface::APPLICATION_SCHEMA);
		if ($application === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		// Organisation scope before any role: another organisation's scoped app
		// answers exactly as an unknown one does (REQ-BQOS-002).
		if ($this->visibleToOrganisation(application: $application, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($this->callerOwns(application: $application, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->wrappers->sync(
				applicationUuid: $appUuid,
				application: $application,
				register: $this->productionRegister(application: $application)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: wrapper flows for application {uuid} could not be saved: {message}',
				['uuid' => $appUuid, 'message' => $e->getMessage()]
			);
			return new JSONResponse(data: ['error' => 'internal_error'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $result);
	}//end sync()

	/**
	 * Whether the caller is in the app's `owners`, with the administrator bypass.
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param IUser               $user        The caller.
	 *
	 * @return bool True for an owner or an administrator.
	 */
	private function callerOwns(array $application, IUser $user): bool {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		return $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $this->permissionResolver->resolveUserGroups($user),
			allowAdminBypass: true,
			roles: ['owners']
		);
	}//end callerOwns()

	/**
	 * The production version's register, or '' when the app has none yet.
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return string The register.
	 */
	private function productionRegister(array $application): string {
		$production = ($application['productionVersion'] ?? null);
		if (is_array($production) === true) {
			return (string)($production['register'] ?? '');
		}

		$version = $this->load(uuid: (string)$production, schema: VersionPromotionServiceInterface::APPLICATION_VERSION_SCHEMA);
		return (string)($version['register'] ?? '');
	}//end productionRegister()

	/**
	 * Whether the caller may see the app at all: a shared app always, a scoped
	 * one only through the organisation guard.
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param IUser               $user        The caller.
	 *
	 * @return bool True when the app is visible to the caller.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AppOrganisationGuard::isShared is pure.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
	 */
	private function visibleToOrganisation(array $application, IUser $user): bool {
		if (AppOrganisationGuard::isShared(application: $application) === true) {
			return true;
		}

		return $this->organisationGuard?->canSee(application: $application, user: $user) === true;
	}//end visibleToOrganisation()

	/**
	 * One object of the buildiq register, or null.
	 *
	 * @param string $uuid   The object's uuid.
	 * @param string $schema The schema.
	 *
	 * @return array<string,mixed>|null The object.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function load(string $uuid, string $schema): ?array {
		if ($uuid === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: VersionPromotionServiceInterface::REGISTER_SLUG, schema: $schema)
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return ObjectRecord::toArray(object: $entity);
	}//end load()
}//end class
