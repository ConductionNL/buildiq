<?php

/**
 * Unit tests for ApplicationTestsController.
 *
 * Who may read the Tests tab, run the suite and write record tests, and what
 * each route answers.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Controller\ApplicationTestsController;
use OCA\Buildiq\Service\ApplicationTestsService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\ReleaseTestGate;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the roles and answers of the Tests tab routes.
 *
 * @covers \OCA\Buildiq\Controller\ApplicationTestsController
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 */
final class ApplicationTestsControllerTest extends TestCase {

	private const APP = [
		'id' => 'uuid-app',
		'slug' => 'vergunningen',
		'permissions' => ['owners' => ['user:olivia'], 'editors' => ['user:eddie'], 'viewers' => ['user:vera']],
	];

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The signed-in user, or null.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * @var ApplicationTestsService&MockObject
	 */
	private ApplicationTestsService&MockObject $tests;

	/**
	 * @var ReleaseTestGate&MockObject
	 */
	private ReleaseTestGate&MockObject $gate;

	/**
	 * Controller under test.
	 *
	 * @var ApplicationTestsController
	 */
	private ApplicationTestsController $controller;

	/**
	 * Set up the controller over the real permission resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);
		$this->tests = $this->createMock(ApplicationTestsService::class);
		$this->gate = $this->createMock(ReleaseTestGate::class);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);
		$groups->method('isAdmin')->willReturn(false);

		$this->tests->method('application')->willReturnCallback(static fn (string $slug): ?array => ($slug === 'vergunningen' ? self::APP : null));

		$this->controller = new ApplicationTestsController(
			request: $this->request,
			userSession: $this->session,
			permissions: new PermissionResolver($groups, $this->createMock(LoggerInterface::class)),
			tests: $this->tests,
			gate: $this->gate,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A viewer reads the tab; someone without a role, or without a session, does not.
	 *
	 * @return void
	 */
	public function testAViewerReadsTheTabAndAStrangerDoesNot(): void {
		$this->tests->method('overview')->with(self::APP)->willReturn(['recordTests' => [], 'ruleSets' => [], 'latestRuns' => []]);

		$this->signIn(uid: 'vera');
		$response = $this->controller->index(appSlug: 'vergunningen');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['recordTests' => [], 'ruleSets' => [], 'latestRuns' => []], $response->getData());

		$this->signIn(uid: 'stranger');
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->index(appSlug: 'vergunningen')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->index(appSlug: 'missing')->getStatus());
	}//end testAViewerReadsTheTabAndAStrangerDoesNot()

	/**
	 * Without a session every route answers 401.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheRoutesAnswer401(): void {
		$this->user = null;

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index(appSlug: 'vergunningen')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->run(appSlug: 'vergunningen', versionSlug: 'development')->getStatus());
	}//end testWithoutASessionTheRoutesAnswer401()

	/**
	 * An editor runs the suite of a version and gets the kept run; a viewer may not run it.
	 *
	 * @return void
	 */
	public function testAnEditorRunsTheTestsAndAViewerMayNot(): void {
		$version = ['id' => 'v-dev', 'slug' => 'development', 'application' => 'uuid-app'];
		$this->tests->method('version')->willReturnCallback(static fn (array $app, string $slug): ?array => ($slug === 'development' ? $version : null));
		$this->gate->expects(self::once())->method('runManually')->with(self::APP, $version)
			->willReturn(['id' => 'run-1', 'trigger' => 'manual', 'passed' => 42, 'failed' => 0, 'skipped' => 1]);

		$this->signIn(uid: 'eddie');
		$response = $this->controller->run(appSlug: 'vergunningen', versionSlug: 'development');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(42, $response->getData()['passed']);
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->run(appSlug: 'vergunningen', versionSlug: 'nope')->getStatus());

		$this->signIn(uid: 'vera');
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->run(appSlug: 'vergunningen', versionSlug: 'development')->getStatus());
	}//end testAnEditorRunsTheTestsAndAViewerMayNot()

	/**
	 * An owner adds a record test (201) from the request body.
	 *
	 * @return void
	 */
	public function testAnOwnerAddsARecordTest(): void {
		$this->signIn(uid: 'olivia');
		$this->body(values: ['name' => 'Permit needs an applicant', 'schema' => 'permit', 'record' => ['title' => 'Shed'], 'expect' => 'refuse', 'expectedFields' => ['applicant']]);
		$this->tests->expects(self::once())->method('saveRecordTest')
			->with(
				self::APP,
				['name' => 'Permit needs an applicant', 'schema' => 'permit', 'record' => ['title' => 'Shed'], 'expect' => 'refuse', 'expectedFields' => ['applicant']],
				null
			)
			->willReturn(['id' => 't-1']);

		$response = $this->controller->createRecordTest(appSlug: 'vergunningen');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['id' => 't-1'], $response->getData());
	}//end testAnOwnerAddsARecordTest()

	/**
	 * An invalid test answers 400 with the reason; a test of another app answers 404.
	 *
	 * @return void
	 */
	public function testInvalidAndForeignRecordTestsAreRefused(): void {
		$this->signIn(uid: 'eddie');
		$this->body(values: []);
		$this->tests->method('saveRecordTest')->willReturnCallback(
			static function (array $app, array $input, ?string $id): ?array {
				if ($id === null) {
					throw new InvalidArgumentException('A record test needs a name and a schema.');
				}
				return null;
			}
		);

		$invalid = $this->controller->createRecordTest(appSlug: 'vergunningen');
		self::assertSame(Http::STATUS_BAD_REQUEST, $invalid->getStatus());
		self::assertSame(['error' => 'invalid_record_test', 'detail' => 'A record test needs a name and a schema.'], $invalid->getData());

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->updateRecordTest(appSlug: 'vergunningen', id: 't-foreign')->getStatus());
	}//end testInvalidAndForeignRecordTestsAreRefused()

	/**
	 * An editor removes a record test (204); a viewer may not write at all.
	 *
	 * @return void
	 */
	public function testAnEditorRemovesARecordTestAndAViewerMayNotWrite(): void {
		$this->tests->method('deleteRecordTest')->willReturnCallback(static fn (array $app, string $id): bool => $id === 't-1');

		$this->signIn(uid: 'eddie');
		self::assertSame(Http::STATUS_NO_CONTENT, $this->controller->destroyRecordTest(appSlug: 'vergunningen', id: 't-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->destroyRecordTest(appSlug: 'vergunningen', id: 't-2')->getStatus());

		$this->signIn(uid: 'vera');
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->destroyRecordTest(appSlug: 'vergunningen', id: 't-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->createRecordTest(appSlug: 'vergunningen')->getStatus());
	}//end testAnEditorRemovesARecordTestAndAViewerMayNotWrite()

	/**
	 * Sign this user in (replacing whoever was).
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->user = $user;
	}//end signIn()

	/**
	 * The request body.
	 *
	 * @param array<string, mixed> $values The body's values.
	 *
	 * @return void
	 */
	private function body(array $values): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $values[$key] ?? $default
		);
	}//end body()
}//end class
