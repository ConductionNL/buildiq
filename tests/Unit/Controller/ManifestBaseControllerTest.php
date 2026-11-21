<?php

/**
 * ManifestBaseController unit test.
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

use OCA\Buildiq\Controller\ManifestBaseController;
use OCA\Buildiq\Service\EditorBaseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Controller\ManifestBaseController
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
final class ManifestBaseControllerTest extends TestCase {

	/**
	 * The controller for a caller, a `?_version=` value and the service's answer.
	 *
	 * @param IUser|null        $caller  The session user.
	 * @param string            $version The `_version` parameter.
	 * @param EditorBaseService $bases   The service.
	 *
	 * @return ManifestBaseController
	 */
	private function controller(?IUser $caller, string $version, EditorBaseService $bases): ManifestBaseController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($version): mixed {
				if ($key === '_version') {
					return $version;
				}
				return $default;
			}
		);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($caller);

		return new ManifestBaseController(
			request: $request,
			userSession: $session,
			bases: $bases,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * A designer gets the base for the named version; production when none is named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	public function testADesignerGetsTheBase(): void {
		$caller = $this->createMock(originalClassName: IUser::class);
		$answer = ['base' => ['pages' => []], 'orphanedDeltaPaths' => ['pages/gone']];
		$asked = [];
		$bases = $this->createMock(originalClassName: EditorBaseService::class);
		$bases->method('authorizeBase')->willReturnCallback(
			static function (string $appSlug, ?string $versionSlug, IUser $user) use (&$asked, $answer, $caller): array {
				self::assertSame(expected: $caller, actual: $user);
				$asked[] = [$appSlug, $versionSlug];
				return $answer;
			}
		);

		$named = $this->controller(caller: $caller, version: 'next', bases: $bases)->base(slug: 'crm');
		$production = $this->controller(caller: $caller, version: '', bases: $bases)->base(slug: 'crm');

		$this->assertSame(expected: Http::STATUS_OK, actual: $named->getStatus());
		$this->assertSame(expected: $answer, actual: $named->getData());
		$this->assertSame(expected: Http::STATUS_OK, actual: $production->getStatus());
		$this->assertSame(expected: [['crm', 'next'], ['crm', null]], actual: $asked);
	}//end testADesignerGetsTheBase()

	/**
	 * No session is 401; anyone the service refuses is 404; a failure is 500 without its message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	public function testRefusalsAndFailures(): void {
		$bases = $this->createMock(originalClassName: EditorBaseService::class);
		$bases->expects($this->never())->method('authorizeBase');
		$this->assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $this->controller(caller: null, version: '', bases: $bases)->base(slug: 'crm')->getStatus()
		);

		$caller = $this->createMock(originalClassName: IUser::class);
		$refusing = $this->createMock(originalClassName: EditorBaseService::class);
		$refusing->method('authorizeBase')->willReturn(null);
		$this->assertSame(
			expected: Http::STATUS_NOT_FOUND,
			actual: $this->controller(caller: $caller, version: '', bases: $refusing)->base(slug: 'crm')->getStatus()
		);

		$failing = $this->createMock(originalClassName: EditorBaseService::class);
		$failing->method('authorizeBase')->willThrowException(new \RuntimeException('register down'));
		$response = $this->controller(caller: $caller, version: '', bases: $failing)->base(slug: 'crm');
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'internal_error'], actual: $response->getData());
	}//end testRefusalsAndFailures()
}//end class
