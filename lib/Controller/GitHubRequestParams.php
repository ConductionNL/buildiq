<?php

/**
 * Buildiq GitHubRequestParams
 *
 * The request parameters the GitHub-backed search and install endpoints read the
 * same way: the free-text search query and the optional broker credential.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCP\IRequest;

/**
 * Reads the shared GitHub request parameters.
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */
final class GitHubRequestParams {

	/**
	 * The `q` search parameter, or null when it is not a string.
	 *
	 * @param IRequest $request The current HTTP request.
	 *
	 * @return string|null The search query.
	 */
	public static function searchQuery(IRequest $request): ?string {
		$query = $request->getParam('q');
		if (is_string($query) === false) {
			return null;
		}

		return $query;
	}//end searchQuery()

	/**
	 * The optional `credentialId` parameter (broker upgrade), or null when absent or empty.
	 *
	 * @param IRequest $request The current HTTP request.
	 *
	 * @return string|null The credential UUID.
	 */
	public static function credentialId(IRequest $request): ?string {
		$credentialId = $request->getParam('credentialId');
		if (is_string($credentialId) === true && $credentialId !== '') {
			return $credentialId;
		}

		return null;
	}//end credentialId()
}//end class
