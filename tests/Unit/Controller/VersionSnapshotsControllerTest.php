<?php

/**
 * Unit tests for VersionSnapshotsController (change version-snapshots).
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\VersionSnapshotsController;
use OCA\Buildiq\Exception\VersionSnapshotException;
use OCA\Buildiq\Service\VersionSnapshotService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for VersionSnapshotsController.
 */
class VersionSnapshotsControllerTest extends TestCase {
	/**
	 * @var VersionSnapshotService&MockObject
	 */
	private VersionSnapshotService&MockObject $service;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Controller under test.
	 */
	private VersionSnapshotsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(VersionSnapshotService::class);
		$this->request = $this->createMock(IRequest::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->controller = new VersionSnapshotsController(
			request: $this->request,
			snapshots: $this->service,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Create passes label and version through and answers 201.
	 *
	 * @return void
	 */
	public function testCreateAnswers201WithTheSnapshot(): void {
		$this->request->method('getParam')->willReturnMap([
			['version', '', 'development'],
			['label', '', 'v1'],
		]);
		$this->service->expects(self::once())->method('takeSnapshot')
			->with('shop', 'development', 'v1', self::anything())
			->willReturn(['id' => 's-1', 'label' => 'v1']);

		$response = $this->controller->create(appSlug: 'shop');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('v1', $response->getData()['label']);
	}//end testCreateAnswers201WithTheSnapshot()

	/**
	 * A refused request keeps the service's status and code.
	 *
	 * @return void
	 */
	public function testRefusalKeepsStatusAndCode(): void {
		$this->service->method('restoreSnapshot')->willThrowException(
			new VersionSnapshotException(errorCode: 'buildiq.rbac.no_role', status: 403, message: 'no role')
		);

		$response = $this->controller->restore(appSlug: 'shop', snapshotUuid: 'abcdef12');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('buildiq.rbac.no_role', $response->getData()['error']);
	}//end testRefusalKeepsStatusAndCode()

	/**
	 * An unexpected failure is a 500 without internals.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureIs500(): void {
		$this->service->method('listSnapshots')->willThrowException(new RuntimeException('db gone'));

		$response = $this->controller->index(appSlug: 'shop');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('db gone', json_encode($response->getData()));
	}//end testUnexpectedFailureIs500()
}//end class
