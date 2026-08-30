<?php

/**
 * Buildiq Capabilities
 *
 * Contributes `{ buildiq: { enabled: true, canEdit: <bool> } }` to the
 * Nextcloud capabilities document so a fleet app can read a robust
 * edit-availability signal via @nextcloud/capabilities rather than inferring
 * it from OC.appswebroots (design D6).
 *
 * `enabled` is always true when this capability is contributed (the Buildiq
 * app is enabled). `canEdit` reflects whether the CALLING user may use
 * Buildiq's in-place edit feature — computed server-side from the real
 * request user context via IAppManager::isEnabledForUser, which respects the
 * NC app group-restriction. `canEdit` is a UI hint only; the write/delete
 * endpoints re-check Buildiq access independently.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\Buildiq
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/openbuild-capability/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq;

use OCA\Buildiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;
use OCP\IUserSession;

/**
 * Advertises Buildiq's edit-availability capability.
 *
 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/openbuild-capability/spec.md
 */
class Capabilities implements ICapability {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Current Nextcloud user session.
	 * @param IAppManager $appManager Resolves Buildiq-access for the calling user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Return Buildiq's capabilities block.
	 *
	 * `enabled` is always true (this method only runs when the app is enabled).
	 * `canEdit` is true when the calling user is within Buildiq's NC app
	 * group-restriction (the same condition the write guard enforces), false
	 * for an out-of-scope or anonymous caller.
	 *
	 * @return array<string, array<string, mixed>> The capabilities document fragment.
	 *
	 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/openbuild-capability/spec.md
	 */
	public function getCapabilities(): array {
		return [
			'buildiq' => [
				'enabled' => true,
				'canEdit' => $this->computeCanEdit(),
			],
		];

	}//end getCapabilities()

	/**
	 * Compute `canEdit` for the calling user.
	 *
	 * @return bool True when the user can reach the enabled Buildiq app.
	 *
	 * @spec openspec/changes/openbuild-inline-edit-persistence/specs/openbuild-capability/spec.md
	 */
	private function computeCanEdit(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->appManager->isEnabledForUser(Application::APP_ID, $user);
	}//end computeCanEdit()
}//end class
