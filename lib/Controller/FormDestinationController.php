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
 * The check reads only schema definitions and the manifest in the body; it
 * writes nothing and reads no object, so any signed-in author may ask.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks a manifest's form pages against their destinations.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
 */
class FormDestinationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param ManifestFormDestinations $destinations The manifest check.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ManifestFormDestinations $destinations,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every destination finding on the manifest's form pages.
	 *
	 * Body: `{ manifest }`. Answer: `{ accepted, findings }`.
	 *
	 * @return JSONResponse 200 with the findings; 400 without a manifest.
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

		try {
			$findings = $this->destinations->findings(manifest: $manifest);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: destination check failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'internal_error', 'message' => 'The forms could not be checked.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['accepted' => ($findings === []), 'findings' => $findings], Http::STATUS_OK);
	}//end check()
}//end class
