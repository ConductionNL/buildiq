<?php

/**
 * Unit tests for ApplicationImportController: the 401 guard, the upload and the import's answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationImportController;
use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the upload route.
 *
 * @covers \OCA\Buildiq\Controller\ApplicationImportController
 */
class ApplicationImportControllerTest extends TestCase {

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Import service mock.
	 *
	 * @var AppPackageImportServiceInterface&MockObject
	 */
	private AppPackageImportServiceInterface&MockObject $importer;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->importer = $this->createMock(AppPackageImportServiceInterface::class);

	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ApplicationImportController The controller.
	 */
	private function controller(): ApplicationImportController {
		return new ApplicationImportController('buildiq', $this->request, $this->session, $this->importer);

	}//end controller()

	/**
	 * Sign sanne in.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sanne');
		$this->session->method('getUser')->willReturn($user);

	}//end signedIn()

	/**
	 * Signed out is 401 and nothing is imported.
	 *
	 * @return void
	 */
	public function testSignedOutIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->importer->expects($this->never())->method('import');

		$response = $this->controller()->import();

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'unauthenticated'], $response->getData());

	}//end testSignedOutIsRefused()

	/**
	 * Without an uploaded archive, or with a failed upload, the answer is 400.
	 *
	 * @return void
	 */
	public function testAMissingUploadIsRefused(): void {
		$this->signedIn();
		$this->importer->expects($this->never())->method('import');
		$this->request->method('getUploadedFile')->with('archive')->willReturnOnConsecutiveCalls(
			null,
			['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '/tmp/x'],
			['error' => UPLOAD_ERR_OK, 'tmp_name' => '']
		);

		foreach ([1, 2, 3] as $attempt) {
			$response = $this->controller()->import();
			$this->assertSame(400, $response->getStatus(), (string)$attempt);
			$this->assertSame('archive_missing', $response->getData()['error']);
		}

	}//end testAMissingUploadIsRefused()

	/**
	 * The upload is imported for the caller with the given name and slug, and the import's status is the answer's.
	 *
	 * @return void
	 */
	public function testTheUploadIsImportedForTheCaller(): void {
		$this->signedIn();
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/php123']);
		$this->request->method('getParam')->willReturnMap([['name', null, 'Vergunningen'], ['slug', null, 'vergunningen']]);
		$this->importer->expects($this->exactly(2))->method('import')->with('/tmp/php123', 'Vergunningen', 'vergunningen', 'sanne')
			->willReturnOnConsecutiveCalls(
				['status' => 201, 'data' => ['slug' => 'vergunningen']],
				['status' => 409, 'data' => ['error' => 'slug_collision', 'detail' => 'vergunningen']]
			);

		$created = $this->controller()->import();
		$this->assertSame(201, $created->getStatus());
		$this->assertSame(['slug' => 'vergunningen'], $created->getData());

		$taken = $this->controller()->import();
		$this->assertSame(409, $taken->getStatus());
		$this->assertSame('slug_collision', $taken->getData()['error']);

	}//end testTheUploadIsImportedForTheCaller()
}//end class
