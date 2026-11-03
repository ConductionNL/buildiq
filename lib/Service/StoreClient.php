<?php

/**
 * Buildiq Store Client
 *
 * OpenRegister's store client and the authorizer that decides who may publish. StoreController
 * searches, resolves and publishes store items through this pair.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;

/**
 * OpenRegister's store client and the authorizer that decides who may publish.
 *
 * @spec openspec/specs/openbuild-remote-template-store/spec.md
 */
final class StoreClient {
	/**
	 * Constructor.
	 *
	 * @param GenericStoreService $storeService Engine-owned store client.
	 * @param StoreActionAuthorizer|null $actionAuthorizer Decides who may publish; absent, nobody may.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly GenericStoreService $storeService,
		public readonly ?StoreActionAuthorizer $actionAuthorizer = null,
	) {
	}//end __construct()
}//end class
