<?php

/**
 * ExportsController where OpenRegister cannot answer the ordinary way.
 *
 * The export guards are IDOR guards, so every way the lookup can fail must
 * refuse rather than pass: the register-slug resolver cannot be built, the
 * object service lacks the method the check needs, or the lookup throws. An
 * admin still passes the guard, and is then told the application is unknown.
 * Also the request-shape refusals the main tests leave out (a version that is
 * no semver, a complete GitHub target), the job guard for an admin and for a
 * job stored as a plain object, and a download whose file cannot be read.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ExportsController;
use OCA\Buildiq\Service\ExportJobService;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Fail-closed paths of ExportsController.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExportsControllerFailClosedTest extends TestCase {

	/**
	 * The export job service double.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService $jobs;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->jobs = $this->createMock(ExportJobService::class);
	}//end setUp()

	/**
	 * When the register-slug resolver cannot be built, a member is refused and
	 * an admin learns the application is unknown; nothing is queued.
	 *
	 * @return void
	 */
	public function testAnUnbuildableResolverRefusesAndLeavesTheAppUnknown(): void {
		$this->jobs->expects($this->never())->method('queue');
		$service = $this->objectService(app: ['id' => 'app-uuid', 'permissions' => ['owners' => ['user:alice']]]);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice', $service, resolverFails: true)->submit('hello-world')->getStatus());

		$response = $this->controller('root', $service, resolverFails: true)->submit('hello-world');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Unknown application: hello-world'], $response->getData());
	}//end testAnUnbuildableResolverRefusesAndLeavesTheAppUnknown()

	/**
	 * An object service without a slug search refuses a member and cannot
	 * resolve the app for an admin.
	 *
	 * @return void
	 */
	public function testAServiceWithoutASlugSearchRefuses(): void {
		$service = new class {
		};

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice', $service)->submit('hello-world')->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('root', $service)->submit('hello-world')->getStatus());
	}//end testAServiceWithoutASlugSearchRefuses()

	/**
	 * A lookup that throws refuses a member, and an admin learns the app is unknown.
	 *
	 * @return void
	 */
	public function testAThrowingLookupRefuses(): void {
		$service = $this->objectService(app: new RuntimeException('register down'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice', $service)->submit('hello-world')->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('root', $service)->submit('hello-world')->getStatus());
	}//end testAThrowingLookupRefuses()

	/**
	 * An app whose id is not a string cannot be exported.
	 *
	 * @return void
	 */
	public function testAnAppWithoutAStringIdIsUnknown(): void {
		$this->jobs->expects($this->never())->method('queue');
		$service = $this->objectService(app: ['id' => 42, 'permissions' => []]);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('root', $service)->submit('hello-world')->getStatus());
	}//end testAnAppWithoutAStringIdIsUnknown()

	/**
	 * An admin passes the guard but cannot export an app that is not there,
	 * or one OpenRegister does not offer to look up.
	 *
	 * @return void
	 */
	public function testAnAdminCannotExportAnAppThatIsNotThere(): void {
		$this->jobs->expects($this->never())->method('queue');

		$noApp = $this->controller('root', $this->objectService(app: null));
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $noApp->submit('hello-world')->getStatus());

		$service = $this->objectService(app: ['id' => 'app-uuid', 'permissions' => []]);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('root', $service, hasService: false)->submit('hello-world')->getStatus());
	}//end testAnAdminCannotExportAnAppThatIsNotThere()

	/**
	 * A target other than zip, server or github is a 422, and so is a missing version.
	 *
	 * @return void
	 */
	public function testAnUnknownTargetIsRefused(): void {
		$this->jobs->expects($this->never())->method('queue');
		$service = $this->objectService(app: ['id' => 'app-uuid', 'permissions' => []]);

		$response = $this->controller('root', $service, params: ['target' => 'ftp', 'applicationVersion' => '1.0.0'])->submit('hello-world');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Invalid target: must be zip, server or github.'], $response->getData());

		$missing = $this->controller('root', $service, params: ['target' => 'server'])->submit('hello-world');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $missing->getStatus());
		$this->assertSame(['error' => 'applicationVersion is required.'], $missing->getData());
	}//end testAnUnknownTargetIsRefused()

	/**
	 * A version that is not a semver is a 422 naming what was received.
	 *
	 * @return void
	 */
	public function testAVersionThatIsNoSemverIsRefused(): void {
		$this->jobs->expects($this->never())->method('queue');
		$service = $this->objectService(app: ['id' => 'app-uuid', 'permissions' => []]);

		$response = $this->controller('root', $service, params: ['target' => 'zip', 'applicationVersion' => 'production'])->submit('hello-world');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['error' => 'applicationVersion must be a semver (e.g. 1.0.0), not a version slug. Received: production'],
			$response->getData()
		);
	}//end testAVersionThatIsNoSemverIsRefused()

	/**
	 * A complete GitHub target passes validation and is queued for the app, without a PAT.
	 *
	 * @return void
	 */
	public function testACompleteGithubTargetIsQueued(): void {
		$service = $this->objectService(app: ['@self' => ['id' => 'app-uuid'], 'permissions' => []]);
		$queued = [];
		$this->jobs->expects($this->once())
			->method('queue')
			->willReturnCallback(
				static function (string $applicationSlug, array $payload, ?string $requestedBy) use (&$queued): string {
					$queued = [$applicationSlug, $payload, $requestedBy];
					return 'job-1';
				}
			);

		$params = ['target' => 'github', 'applicationVersion' => '1.2.0', 'githubOrg' => 'ConductionNL', 'githubRepo' => 'hello', 'githubPat' => 'secret'];
		$response = $this->controller('root', $service, params: $params)->submit('hello-world');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['uuid' => 'job-1'], $response->getData());
		$this->assertSame(
			[
				'hello-world',
				[
					'target' => 'github',
					'applicationVersion' => '1.2.0',
					'githubOrg' => 'ConductionNL',
					'githubRepo' => 'hello',
					'applicationUuid' => 'app-uuid',
				],
				'root',
			],
			$queued
		);
	}//end testACompleteGithubTargetIsQueued()

	/**
	 * An admin may run any job; a job stored as a plain object is read for its
	 * requester; a service without find() or a throwing find() refuses.
	 *
	 * @return void
	 */
	public function testTheJobGuard(): void {
		$this->jobs->method('runNow')->willReturn(true);

		$this->assertSame(Http::STATUS_OK, $this->controller('root', $this->objectService(job: null))->run('job-1')->getStatus());

		$plain = new \stdClass();
		$plain->requestedBy = 'alice';
		$this->assertSame(Http::STATUS_OK, $this->controller('alice', $this->objectService(job: $plain))->run('job-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('bob', $this->objectService(job: $plain))->run('job-1')->getStatus());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice', new class {
		})->run('job-1')->getStatus());
		$throwing = $this->controller('alice', $this->objectService(job: new RuntimeException('gone')));
		$this->assertSame(Http::STATUS_NOT_FOUND, $throwing->run('job-1')->getStatus());
		$nobody = $this->controller(null, $this->objectService(job: ['requestedBy' => 'alice']));
		$this->assertSame(Http::STATUS_NOT_FOUND, $nobody->run('job-1')->getStatus());
	}//end testTheJobGuard()

	/**
	 * A download whose file cannot be read is a 500, not an empty zip.
	 *
	 * @return void
	 */
	public function testAnUnreadableExportIsAServerError(): void {
		$missing = sys_get_temp_dir() . '/buildiq-missing-' . uniqid() . '.zip';
		$this->jobs->method('resolveDownload')->with('job-1')->willReturn(['path' => $missing, 'expired' => false]);

		$response = @$this->controller('alice', $this->objectService(job: ['requestedBy' => 'alice']))->download('job-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Unable to read export.'], $response->getData());
	}//end testAnUnreadableExportIsAServerError()

	/**
	 * An object service answering the app and the job; a Throwable is thrown instead.
	 *
	 * @param mixed $app The app record, null for none, or a Throwable to throw.
	 * @param mixed $job The job record, null for none, or a Throwable to throw.
	 *
	 * @return object
	 */
	private function objectService(mixed $app = null, mixed $job = null): object {
		return new class ($app, $job) {
			/**
			 * Build the double.
			 *
			 * @param mixed $app The app.
			 * @param mixed $job The job.
			 */
			public function __construct(private readonly mixed $app, private readonly mixed $job) {
			}

			/**
			 * The app, as a list of one, or nothing.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $query    The query.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $query): array {
				if ($this->app instanceof \Throwable) {
					throw $this->app;
				}

				if ($this->app === null) {
					return [];
				}

				return [$this->app];
			}

			/**
			 * The job.
			 *
			 * @param string $id The uuid.
			 *
			 * @return mixed
			 */
			public function find(string $id): mixed {
				if ($this->job instanceof \Throwable) {
					throw $this->job;
				}

				return $this->job;
			}
		};
	}//end objectService()

	/**
	 * The controller for a caller over an object service. `root` is an admin.
	 *
	 * @param string|null          $uid           The caller, or null for nobody.
	 * @param object               $service       The object service.
	 * @param bool                 $resolverFails Whether the slug resolver cannot be built.
	 * @param array<string, mixed> $params        The request body.
	 * @param bool                 $hasService    Whether the container says it has the object service.
	 *
	 * @return ExportsController
	 */
	private function controller(
		?string $uid,
		object $service,
		bool $resolverFails = false,
		array $params = ['target' => 'zip', 'applicationVersion' => '1.0.0'],
		bool $hasService = true
	): ExportsController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $who, string $gid): bool => ($who === 'root' && $gid === 'admin'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($hasService);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service, $resolverFails): object {
				if ($id === RegisterSlugResolverInterface::class) {
					if ($resolverFails === true) {
						throw new RuntimeException('resolver not registered');
					}

					return new FakeSlugResolver(present: ['buildiq']);
				}

				return $service;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new ExportsController($request, $this->jobs, $session, $container, new NullLogger(), $groupManager);
	}//end controller()
}//end class
