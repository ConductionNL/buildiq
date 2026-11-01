<?php

/**
 * Buildiq FormDestinationController
 *
 * `POST /api/forms/destination-check`: the page designer sends the manifest it
 * is about to save and gets back every destination finding on its form pages,
 * each naming its page and field. The designer saves anyway (the pages are a
 * draft, decision 189) and marks each finding on its field; publishing the
 * app refuses while any remain.
 *
 * The check writes nothing, but its findings name the destination schema's
 * properties, so only someone who may edit the app the manifest belongs to
 * may ask: an owner or editor of that Application (or an admin).
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Service\ManifestFormDestinations;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
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
 * Checks a manifest's form pages against their destinations.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
 */
class FormDestinationController extends Controller {
	/**
	 * The Application roles that may edit its pages.
	 *
	 * @var array<int, string>
	 */
	private const EDIT_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ManifestFormDestinations $destinations The manifest check.
	 * @param ManifestResolverService $applications Finds the Application the manifest belongs to.
	 * @param PermissionResolver $permissions Decides whether the caller may edit it.
	 * @param IUserSession $userSession The caller.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ManifestFormDestinations $destinations,
		private readonly ManifestResolverService $applications,
		private readonly PermissionResolver $permissions,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every destination finding on the manifest's form pages.
	 *
	 * Body: `{ appSlug, manifest }`. Answer: `{ accepted, findings }`.
	 *
	 * @return JSONResponse 200 with the findings; 400 without a manifest; 403 when the caller may not edit the app.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function check(): JSONResponse {
		$manifest = $this->request->getParam('manifest');
		if (is_array($manifest) === false) {
			return new JSONResponse(['error' => 'invalid_manifest', 'message' => 'The body has to carry the manifest to check.'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->mayEdit(appSlug: trim((string)$this->request->getParam('appSlug', ''))) === false) {
			return new JSONResponse(['error' => 'forbidden', 'message' => 'Only someone who may edit this app can check its forms.'], Http::STATUS_FORBIDDEN);
		}

		try {
			$findings = $this->destinations->findings(manifest: $manifest);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: destination check failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'internal_error', 'message' => 'The forms could not be checked.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['accepted' => ($findings === []), 'findings' => $findings], Http::STATUS_OK);
	}//end check()

	/**
	 * Whether the caller may edit the pages of the Application with this slug.
	 *
	 * @param string $appSlug The Application's slug.
	 *
	 * @return bool True for an owner, an editor or an admin.
	 */
	private function mayEdit(string $appSlug): bool {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser === false || $appSlug === '') {
			return false;
		}

		$application = $this->applications->findApplication(appSlug: $appSlug);
		if ($application === null) {
			return $this->permissions->isAdmin($user);
		}

		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		return $this->permissions->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $this->permissions->resolveUserGroups($user),
			allowAdminBypass: true,
			roles: self::EDIT_ROLES
		);
	}//end mayEdit()
}//end class
