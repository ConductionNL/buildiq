<?php

/**
 * ExportsController access and failure paths its main test leaves out.
 *
 * Who may export an application: an admin without a lookup, or a caller
 * named in the app's owners, editors or viewers, directly or through a
 * group. What happens when this app's register cannot be named, when queueing
 * fails, and how run() and download() answer for a job the caller does not
 * own or that cannot be started.
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

use InvalidArgumentException;
use OCA\Buildiq\Controller\ExportsController;
use OCA\Buildiq\Service\ExportJobService;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
 * Access and failure paths of ExportsController.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExportsControllerAccessTest extends TestCase {

	/**
	 * The export job service double.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService $jobs;

	/**
	 * The groups each uid is in.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $groups = [];

	/**
	 * What the object service answers for the app and for find().
	 *
	 * @var array{app: mixed, job: mixed}
	 */
	private array $objects = ['app' => null, 'job' => null];

	/**
	 * The register slugs present on this instance.
	 *
	 * @var array<int, string>
	 */
	private array $registers = ['buildiq'];

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->jobs = $this->createMock(ExportJobService::class);
		$this->groups = [];
		$this->objects = ['app' => null, 'job' => null];
		$this->registers = ['buildiq'];
	}//end setUp()

	/**
	 * A caller named as a user or through a group in any role may submit; nobody else may.
	 *
	 * @return void
	 */
	public function testEveryRoleAndGroupFormGrantsSubmit(): void {
		$this->jobs->method('queue')->willReturn('job-1');
		$this->groups = ['bob' => ['designers'], 'carol' => ['reviewers']];
		$this->objects['app'] = [
			'id' => 'app-uuid',
			'permissions' => [
				'owners' => 'not-a-list',
				'editors' => ['', 42, 'group:designers', 'user:'],
				'viewers' => ['user:dave', 'reviewers'],
			],
		];

		$expected = ['bob' => Http::STATUS_ACCEPTED, 'carol' => Http::STATUS_ACCEPTED, 'dave' => Http::STATUS_ACCEPTED, 'eve' => Http::STATUS_FORBIDDEN];
		foreach ($expected as $uid => $status) {
			$this->assertSame($status, $this->controller($uid)->submit('hello-world')->getStatus(), $uid);
		}
	}//end testEveryRoleAndGroupFormGrantsSubmit()

	/**
	 * Permissions that are not a list, or an app nobody finds, refuse a non-admin.
	 *
	 * @return void
	 */
	public function testUnreadablePermissionsOrAMissingAppRefuse(): void {
		$this->objects['app'] = ['id' => 'app-uuid', 'permissions' => 'owners'];
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice')->submit('hello-world')->getStatus());

		$this->objects['app'] = null;
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice')->submit('hello-world')->getStatus());
	}//end testUnreadablePermissionsOrAMissingAppRefuse()

	/**
	 * Without this app's register on the instance, nobody but an admin passes, and the app cannot be resolved.
	 *
	 * @return void
	 */
	public function testAnUnnamedRegisterRefusesAndLeavesTheAppUnknown(): void {
		$this->registers = [];
		$this->objects['app'] = ['id' => 'app-uuid', 'permissions' => ['owners' => ['user:alice']]];
		$this->jobs->expects($this->never())->method('queue');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice')->submit('hello-world')->getStatus());

		$this->groups = ['root' => ['admin']];
		$response = $this->controller('root')->submit('hello-world');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Unknown application: hello-world'], $response->getData());
	}//end testAnUnnamedRegisterRefusesAndLeavesTheAppUnknown()

	/**
	 * A queue refusal is a 422 with its message; any other failure a generic 500.
	 *
	 * @return void
	 */
	public function testQueueFailures(): void {
		$this->objects['app'] = ['id' => 'app-uuid', 'permissions' => ['owners' => ['user:alice']]];
		$this->jobs->method('queue')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('Pick a branch.')),
			$this->throwException(new RuntimeException('database gone'))
		);

		$refused = $this->controller('alice')->submit('hello-world');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'Pick a branch.'], $refused->getData());

		$failed = $this->controller('alice')->submit('hello-world');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'Internal error queueing export.'], $failed->getData());
	}//end testQueueFailures()

	/**
	 * The queued job carries the resolved application uuid and the caller as its requester.
	 *
	 * @return void
	 */
	public function testTheQueuedJobNamesTheAppAndTheCaller(): void {
		$this->objects['app'] = ['@self' => ['id' => 'self-uuid'], 'permissions' => ['owners' => ['user:alice']]];
		$this->jobs->expects($this->once())
			->method('queue')
			->with('hello-world', $this->callback(static fn (array $body): bool => $body['applicationUuid'] === 'self-uuid'), 'alice')
			->willReturn('job-9');

		$response = $this->controller('alice')->submit('hello-world');

		$this->assertSame(['uuid' => 'job-9'], $response->getData());
	}//end testTheQueuedJobNamesTheAppAndTheCaller()

	/**
	 * run() is 404 for a job the caller did not request, and nothing is started.
	 *
	 * @return void
	 */
	public function testRunRefusesAJobOfSomeoneElse(): void {
		$this->objects['job'] = ['requestedBy' => 'bob'];
		$this->jobs->expects($this->never())->method('runNow');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->run('job-1')->getStatus());
	}//end testRunRefusesAJobOfSomeoneElse()

	/**
	 * run() for the requester starts the job, or says it is already running, or that it failed.
	 *
	 * @return void
	 */
	public function testRunForTheRequester(): void {
		$this->objects['job'] = new class implements \JsonSerializable {
			/**
			 * The stored job, owned through its metadata.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['requestedBy' => '', '@self' => ['owner' => 'alice']];
			}
		};
		$this->jobs->method('runNow')->with('job-1')->willReturnOnConsecutiveCalls(
			true,
			false,
			$this->throwException(new RuntimeException('worker gone'))
		);

		$this->assertSame(['started' => true], $this->controller('alice')->run('job-1')->getData());

		$busy = $this->controller('alice')->run('job-1');
		$this->assertSame(Http::STATUS_CONFLICT, $busy->getStatus());
		$this->assertSame(['started' => false], $busy->getData());

		$failed = $this->controller('alice')->run('job-1');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'The export could not be started.'], $failed->getData());
	}//end testRunForTheRequester()

	/**
	 * download() is 401 without a user, and 404 for a job nobody finds or one the service no longer resolves.
	 *
	 * @return void
	 */
	public function testDownloadRefusals(): void {
		$unauthenticated = $this->controller(null)->download('job-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $unauthenticated->getStatus());

		$this->objects['job'] = null;
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('alice')->download('job-1')->getStatus());

		$this->objects['job'] = ['requestedBy' => 'alice'];
		$this->jobs->method('resolveDownload')->with('job-1')->willReturn(null);
		$response = $this->controller('alice')->download('job-1');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDownloadRefusals()

	/**
	 * The controller for a signed-in uid (or none), over an object service answering $this->objects.
	 *
	 * @param string|null $uid The caller, or null for nobody signed in.
	 *
	 * @return ExportsController The controller.
	 */
	private function controller(?string $uid): ExportsController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $who, string $gid): bool => in_array($gid, ($this->groups[$who] ?? []), true)
		);

		$objects = $this->objects;
		$objectService = new class ($objects) {
			/**
			 * Build the double.
			 *
			 * @param array{app: mixed, job: mixed} $objects What it answers.
			 */
			public function __construct(private readonly array $objects) {
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
				if ($this->objects['app'] === null) {
					return [];
				}

				return [$this->objects['app']];
			}

			/**
			 * The job.
			 *
			 * @param string $id The uuid.
			 *
			 * @return mixed
			 */
			public function find(string $id): mixed {
				return $this->objects['job'];
			}
		};

		$registers = $this->registers;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $registers): object {
				if ($id === RegisterSlugResolverInterface::class) {
					return new FakeSlugResolver(present: $registers);
				}

				return $objectService;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['target' => 'zip', 'applicationVersion' => '1.0.0']);

		return new ExportsController($request, $this->jobs, $session, $container, new NullLogger(), $groupManager);
	}//end controller()
}//end class
