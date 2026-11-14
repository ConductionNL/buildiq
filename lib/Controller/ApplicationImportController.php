<?php

/**
 * ApplicationImportController
 *
 * Imports an app archive exported by buildiq as a new app the caller owns. The
 * same gate as a GitHub install: any signed-in user may create an app they own
 * from a package, and the route creates and reads no existing object.
 *
 * @category  Controller
 * @package   OCA\Buildiq\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The upload route of the app import.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
 */
class ApplicationImportController extends Controller {

	/**
	 * The multipart field the archive is uploaded in.
	 *
	 * @var string
	 */
	public const UPLOAD_FIELD = 'archive';

	/**
	 * Constructor.
	 *
	 * @param string                           $appName     The app id.
	 * @param IRequest                         $request     The request.
	 * @param IUserSession                     $userSession Names the caller, who becomes the owner.
	 * @param AppPackageImportServiceInterface $importer    Reads and installs the archive.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AppPackageImportServiceInterface $importer,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Import an uploaded archive as a new app owned by the caller.
	 *
	 * @return JSONResponse 201 with the app; 400 without an archive or with a bad slug,
	 *                      401 signed out, 409 for a taken slug, 413 or 422 for a bad archive.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-the-archive-is-read-and-never-run-req-bqic-002
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	public function import(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$upload = $this->request->getUploadedFile(self::UPLOAD_FIELD);
		$path = self::uploadedPath(upload: $upload);
		if ($path === null) {
			return new JSONResponse(
				data: ['error' => 'archive_missing', 'detail' => 'Upload the exported archive in the "archive" field.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->importer->importWithRecords(
			archivePath: $path,
			target: [
				'name' => (string)($this->request->getParam('name') ?? ''),
				'slug' => (string)($this->request->getParam('slug') ?? ''),
				'owner' => $user->getUID(),
				'records' => self::isOn(value: $this->request->getParam('records')),
			]
		);

		return new JSONResponse(data: $result['data'], statusCode: $result['status']);
	}//end import()

	/**
	 * Read an uploaded archive and say what it would import, without importing it.
	 *
	 * The dialog shows this summary before the maker confirms; nothing is created.
	 *
	 * @return JSONResponse 200 with name, slug, pages, schemas and records; 400 without an archive,
	 *                      401 signed out, 413 or 422 for a bad archive.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	public function preview(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$path = self::uploadedPath(upload: $this->request->getUploadedFile(self::UPLOAD_FIELD));
		if ($path === null) {
			return new JSONResponse(
				data: ['error' => 'archive_missing', 'detail' => 'Upload the exported archive in the "archive" field.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->importer->preview(archivePath: $path);
		return new JSONResponse(data: $result['data'], statusCode: $result['status']);
	}//end preview()

	/**
	 * Whether a form flag is on: true, 1, "1", "true" or "on". Anything else, absent included, is off.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return bool Whether it is on.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
	 */
	private static function isOn(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_scalar($value) === false) {
			return false;
		}

		return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on'], true);
	}//end isOn()

	/**
	 * The temporary path of a successful upload, or null.
	 *
	 * @param mixed $upload The `$_FILES` entry the request gives.
	 *
	 * @return string|null The path PHP stored the upload at.
	 */
	private static function uploadedPath(mixed $upload): ?string {
		if (is_array($upload) === false || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return null;
		}

		$path = ($upload['tmp_name'] ?? null);
		if (is_string($path) === false || $path === '') {
			return null;
		}

		return $path;
	}//end uploadedPath()
}//end class
