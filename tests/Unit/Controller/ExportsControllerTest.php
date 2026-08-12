<?php

/**
 * OpenBuild ExportsController unit tests
 *
 * Covers the HTTP surface — submit() validation, RBAC fallback,
 * 202 queue semantics, GitHub-field validation, and download() expiry +
 * authorization. These tests sit on top of a mocked ExportJobService so
 * the controller is exercised in isolation.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ExportsController;
use OCA\OpenBuild\Service\ExportJobService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see ExportsController} — HTTP surface + RBAC + lifecycle.
 */
final class ExportsControllerTest extends TestCase {

	/**
	 * IRequest mock — getParams() is the only relevant method.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * ExportJobService mock — queue() / resolveDownload() are stubbed.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService&MockObject $exportJobService;

	/**
	 * Session mock — drives the RBAC user lookup.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Container mock — drives the fallback authorization path.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * GroupManager mock — drives the admin-bypass and group-membership checks.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Build the dependency mocks shared across every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->exportJobService = $this->createMock(ExportJobService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		// Non-admin by default.
		$this->groupManager->method('isInGroup')->willReturn(false);
	}//end setUp()

	/**
	 * Build a controller with the shared mocks, optionally adjusting the
	 * authenticated user for the test.
	 *
	 * @param bool $authenticated Whether session returns a user.
	 *
	 * @return ExportsController
	 */
	private function buildController(bool $authenticated = true): ExportsController {
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$this->userSession->method('getUser')->willReturn($user);
		} else {
			$this->userSession->method('getUser')->willReturn(null);
		}

		return new ExportsController(
			$this->request,
			$this->exportJobService,
			$this->userSession,
			$this->container,
			new NullLogger(),
			$this->groupManager,
		);
	}//end buildController()

	/**
	 * Stub the container so the RBAC authorization path returns "authorised"
	 * — ObjectService::searchObjectsBySlug returns the app with alice as owner,
	 * and ::find returns the export job owned by alice (issue #158).
	 *
	 * @return void
	 */
	private function stubAuthorisedFallback(): void {
		$objectService = new class() {
			/**
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string,mixed> $query Search parameters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $query): array {
				// OpenRegister returns ObjectEntity OBJECTS here, not arrays, and keys the
				// id as `id` (not `uuid`). This stub used to return a plain array keyed by
				// `uuid`, which is why the controller's `is_array($app)` guard looked
				// correct in tests while never once being true in production.
				return [
					new class($query['slug'] ?? 'hello-world') implements \JsonSerializable {

						public function __construct(
							private string $slug,
						) {
						}//end __construct()

						/**
						 * @return array<string,mixed>
						 */
						public function jsonSerialize(): array {
							return [
								'id' => 'app-uuid-1',
								'slug' => $this->slug,
								'permissions' => [
									'owners' => ['user:alice'],
									'editors' => [],
									'viewers' => [],
								],
								'@self' => ['id' => 'app-uuid-1'],
							];
						}//end jsonSerialize()
					},
				];
			}//end searchObjectsBySlug()

			/**
			 * @param string $id UUID to look up.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				// The record persists `requestedBy` (not the never-written
				// `submittedBy`); download authz must key on it (L8).
				return ['uuid' => $id, 'requestedBy' => 'alice'];
			}//end find()
		};

		$this->container->method('has')->willReturnCallback(
			static function (string $class): bool {
				return $class === 'OCA\\OpenRegister\\Service\\ObjectService';
			}
		);
		$this->container->method('get')->willReturn($objectService);
	}//end stubAuthorisedFallback()

	/**
	 * Test 1: submit() with an invalid `target` returns 422
	 * (UNPROCESSABLE_ENTITY) — the body-validation guard short-circuits
	 * before the ExportJob is queued.
	 *
	 * @return void
	 */
	public function testSubmitReturns422ForInvalidTarget(): void {
		$this->stubAuthorisedFallback();
		$this->request->method('getParams')->willReturn(
			[
				'target' => 'ftp',
				'applicationVersion' => '1.0.0',
			]
		);

		$this->exportJobService->expects(self::never())->method('queue');

		$response = $this->buildController()->submit('hello-world');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testSubmitReturns422ForInvalidTarget()

	/**
	 * Test 2: submit() requires per-object Application access — when the
	 * RBAC fallback denies (user not authenticated → IUserSession::getUser
	 * returns null), the controller returns 403 Forbidden and the
	 * ExportJob is NOT queued.
	 *
	 * This pins the ADR-005 Rule 3 IDOR guard.
	 *
	 * @return void
	 */
	public function testSubmitReturns403WhenRbacDenies(): void {
		$this->container->method('has')->willReturn(false);
		$this->request->method('getParams')->willReturn(
			[
				'target' => 'zip',
				'applicationVersion' => '1.0.0',
			]
		);

		$this->exportJobService->expects(self::never())->method('queue');

		// Unauthenticated → user null → RBAC denies.
		$response = $this->buildController(authenticated: false)->submit('hello-world');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSubmitReturns403WhenRbacDenies()

	/**
	 * Test 3: submit() happy path — queues the ExportJob via
	 * ExportJobService::queue() and returns 202 Accepted with the UUID.
	 *
	 * @return void
	 */
	public function testSubmitQueuesJobAndReturns202(): void {
		$this->stubAuthorisedFallback();
		$this->request->method('getParams')->willReturn(
			[
				'target' => 'zip',
				'applicationVersion' => '1.0.0',
			]
		);

		$this->exportJobService
			->expects(self::once())
			->method('queue')
			->willReturn('new-job-uuid-123');

		$response = $this->buildController()->submit('hello-world');
		self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$data = $response->getData();
		self::assertSame('new-job-uuid-123', $data['uuid']);
	}//end testSubmitQueuesJobAndReturns202()

	/**
	 * Test 4: submit() with target=github validates that both
	 * `githubOrg` and `githubRepo` are present — otherwise 422.
	 *
	 * @return void
	 */
	public function testSubmitValidatesGithubOrgAndRepo(): void {
		$this->stubAuthorisedFallback();
		$this->request->method('getParams')->willReturn(
			[
				'target' => 'github',
				'applicationVersion' => '1.0.0',
				// Missing githubOrg + githubRepo.
			]
		);

		$this->exportJobService->expects(self::never())->method('queue');

		$response = $this->buildController()->submit('hello-world');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

		$data = $response->getData();
		self::assertStringContainsString('github', strtolower((string)($data['error'] ?? '')));
	}//end testSubmitValidatesGithubOrgAndRepo()

	/**
	 * Test 5: download() returns 410 Gone when the ExportJob has expired.
	 * The controller honours the `expired` flag from
	 * ExportJobService::resolveDownload().
	 *
	 * @return void
	 */
	public function testDownloadReturns410ForExpiredJob(): void {
		$this->stubAuthorisedFallback();

		$this->exportJobService
			->method('resolveDownload')
			->willReturn(['path' => '/tmp/some.zip', 'expired' => true]);

		$response = $this->buildController()->download('expired-uuid');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_GONE, $response->getStatus());
	}//end testDownloadReturns410ForExpiredJob()

	/**
	 * Test 6: download() returns 404 for unauthorized callers — masked as
	 * "Unknown export job" to avoid revealing the UUID space (defence in
	 * depth on the IDOR vector documented in the controller).
	 *
	 * @return void
	 */
	public function testDownloadReturns404ForUnauthorizedCaller(): void {
		// Container has NO ObjectService → the authz fallback returns false.
		$this->container->method('has')->willReturn(false);

		$this->exportJobService->expects(self::never())->method('resolveDownload');

		$response = $this->buildController()->download('some-uuid');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDownloadReturns404ForUnauthorizedCaller()

	/**
	 * Test 7: download() returns the ZIP for the owner — content-type
	 * `application/zip` and a DataDownloadResponse with the file body.
	 *
	 * @return void
	 */
	public function testDownloadReturnsZipForOwner(): void {
		$this->stubAuthorisedFallback();

		$tmpZip = sys_get_temp_dir() . '/openbuild-controller-test-' . uniqid() . '.zip';
		file_put_contents($tmpZip, 'PK fake zip bytes');

		try {
			$this->exportJobService
				->method('resolveDownload')
				->willReturn(['path' => $tmpZip, 'expired' => false]);

			$response = $this->buildController()->download('owned-uuid');
			self::assertInstanceOf(DataDownloadResponse::class, $response);
			self::assertSame(Http::STATUS_OK, $response->getStatus());
		} finally {
			@unlink($tmpZip);
		}
	}//end testDownloadReturnsZipForOwner()

	/**
	 * L8 / #11-#4: when a job persisted `requestedBy` as an empty string (queued
	 * without a resolvable requester), authorization MUST fall back to the OR
	 * `@self.owner`. A plain `??` treats '' as present and never reaches the
	 * fallback, so the legitimate owner would be denied — this test locks in the
	 * empty-string-aware coalesce.
	 *
	 * @return void
	 */
	public function testDownloadFallsBackToOwnerWhenRequestedByEmpty(): void {
		// ObjectService::find returns a record whose requestedBy is '' but whose
		// @self.owner is the caller (alice) — the owner fallback must authorise.
		$objectService = new class() {
			/**
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string,mixed> $query Search parameters.
			 *
			 * @return array<int, object>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $query): array {
				return [
					new class implements \JsonSerializable {
						/**
						 * @return array<string,mixed>
						 */
						public function jsonSerialize(): array {
							return [
								'id' => 'app-uuid-1',
								'slug' => 'hello-world',
								'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
								'@self' => ['id' => 'app-uuid-1'],
							];
						}//end jsonSerialize()
					},
				];
			}//end searchObjectsBySlug()

			/**
			 * @param string $id UUID to look up.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id): array {
				// requestedBy persisted empty (null requester → (string) null === '');
				// owner is alice on the @self envelope.
				return ['uuid' => $id, 'requestedBy' => '', '@self' => ['owner' => 'alice']];
			}//end find()
		};

		$this->container->method('has')->willReturnCallback(
			static function (string $class): bool {
				return $class === 'OCA\\OpenRegister\\Service\\ObjectService';
			}
		);
		$this->container->method('get')->willReturn($objectService);

		$tmpZip = sys_get_temp_dir() . '/openbuild-controller-test-' . uniqid() . '.zip';
		file_put_contents($tmpZip, 'PK fake zip bytes');

		try {
			$this->exportJobService
				->method('resolveDownload')
				->willReturn(['path' => $tmpZip, 'expired' => false]);

			$response = $this->buildController()->download('owned-uuid');
			self::assertInstanceOf(DataDownloadResponse::class, $response);
			self::assertSame(Http::STATUS_OK, $response->getStatus());
		} finally {
			@unlink($tmpZip);
		}
	}//end testDownloadFallsBackToOwnerWhenRequestedByEmpty()

	/**
	 * Test 8: download() preserves the original filename via
	 * Content-Disposition (DataDownloadResponse derives it from the
	 * second constructor arg; we assert the basename of the resolved
	 * path appears in the headers).
	 *
	 * @return void
	 */
	public function testDownloadPreservesContentDispositionFilename(): void {
		$this->stubAuthorisedFallback();

		$tmpZip = sys_get_temp_dir() . '/openbuild-filename-test.zip';
		file_put_contents($tmpZip, 'PK');

		try {
			$this->exportJobService
				->method('resolveDownload')
				->willReturn(['path' => $tmpZip, 'expired' => false]);

			$response = $this->buildController()->download('owned-uuid');
			self::assertInstanceOf(DataDownloadResponse::class, $response);

			// Read $headers directly via Reflection — getHeaders() requires
			// the full OC::$server stack which isn't booted in unit tests.
			$headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
			$headersProp->setAccessible(true);
			$headers = $headersProp->getValue($response);
			$disposition = $headers['Content-Disposition'] ?? '';
			self::assertStringContainsString(
				'openbuild-filename-test.zip',
				(string)$disposition,
				'Content-Disposition must include the original filename'
			);
		} finally {
			@unlink($tmpZip);
		}//end try
	}//end testDownloadPreservesContentDispositionFilename()

	/**
	 * Test 9: regression pin for the CSRF hardening fix — `submit()` is a
	 * state-changing POST and must NOT carry `#[NoCSRFRequired]`, while
	 * `download()` is a GET-only navigation download and legitimately
	 * keeps it. Both must still declare `#[NoAdminRequired]` (per-object
	 * RBAC guard lives in the method body, not the framework attribute).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openbuild-export-csrf-hardening/tasks.md#task-2.1
	 */
	public function testSubmitDoesNotCarryNoCsrfRequiredWhileDownloadDoes(): void {
		$reflection = new \ReflectionClass(ExportsController::class);
		$attributeNameMapper = static fn (\ReflectionAttribute $attribute): string => $attribute->getName();

		$submitAttributeNames = array_map(callback: $attributeNameMapper, array: $reflection->getMethod('submit')->getAttributes());

		self::assertNotContains(
			needle: \OCP\AppFramework\Http\Attribute\NoCSRFRequired::class,
			haystack: $submitAttributeNames,
			message: 'submit() is a state-changing POST and must be CSRF-protected.'
		);
		self::assertContains(
			needle: \OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
			haystack: $submitAttributeNames,
			message: 'submit() must remain reachable by non-admin users (guarded by isAuthorisedForApplication).'
		);

		$downloadAttributeNames = array_map(callback: $attributeNameMapper, array: $reflection->getMethod('download')->getAttributes());

		self::assertContains(
			needle: \OCP\AppFramework\Http\Attribute\NoCSRFRequired::class,
			haystack: $downloadAttributeNames,
			message: 'download() is a GET-only navigation download and intentionally skips the CSRF check.'
		);
		self::assertContains(
			needle: \OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
			haystack: $downloadAttributeNames,
			message: 'download() must remain reachable by non-admin users (guarded by isAuthorisedForJob).'
		);
	}//end testSubmitDoesNotCarryNoCsrfRequiredWhileDownloadDoes()
}//end class
