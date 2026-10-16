<?php

/**
 * Unit tests for RuleActionDispatcher.
 *
 * Covers REQ-AUTD-010: send-notification hits IManager, object-op hits
 * ObjectService::saveObject with the mapped fields, webhook POSTs the
 * compiled target, and an unrecognised type surfaces as a logged no-op
 * (never throws — a bad action must not abort the caller's remaining work).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#4.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\JobOwnerImpersonator;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see RuleActionDispatcher}.
 */
final class RuleActionDispatcherTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $notificationManager;

	/**
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $httpClientService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The dispatcher under test.
	 *
	 * @var RuleActionDispatcher
	 */
	private RuleActionDispatcher $dispatcher;

	/**
	 * Wire the dispatcher with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->httpClientService = $this->createMock(IClientService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->dispatcher = new RuleActionDispatcher(
			$this->objectService,
			$this->notificationManager,
			$this->httpClientService,
			$this->userSession,
			$this->createMock(JobOwnerImpersonator::class),
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
		);

	}//end setUp()

	/**
	 * send-notification creates and dispatches a Nextcloud notification for
	 * the resolved recipient via IManager.
	 *
	 * @return void
	 */
	public function testSendNotificationHitsNotificationManager(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify')->with($notification);

		$sent = ($this->dispatcher)('send-notification', ['subject' => 'hello', 'recipientUid' => 'bob'], []);

		$this->assertSame(1, $sent);

	}//end testSendNotificationHitsNotificationManager()

	/**
	 * send-notification with no resolvable recipient is a silent no-op.
	 *
	 * @return void
	 */
	public function testSendNotificationWithoutRecipientIsNoOp(): void {
		$this->notificationManager->expects($this->never())->method('createNotification');

		$sent = ($this->dispatcher)('send-notification', ['subject' => 'hello'], []);

		$this->assertSame(0, $sent);

	}//end testSendNotificationWithoutRecipientIsNoOp()

	/**
	 * object-op create writes through ObjectService::saveObject with the
	 * mapped fields.
	 *
	 * @return void
	 */
	public function testObjectOpCreateHitsObjectService(): void {
		$captured = [];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (...$args) use (&$captured) {
					$captured = $args;
					$entity = new \OCA\OpenRegister\Db\ObjectEntity();
					$entity->setUuid('new-1');
					$entity->setObject(['title' => 'from automation']);
					return $entity;
				}
			);

		$result = ($this->dispatcher)(
			'object-op',
			['schema' => 'permit', 'operation' => 'create', 'object' => ['title' => 'from automation']],
			[]
		);

		$this->assertSame(['title' => 'from automation'], $captured[0]);
		$this->assertSame('buildiq', $captured[2] ?? null);
		$this->assertSame('permit', $captured[3] ?? null);
		$this->assertSame('from automation', $result['title']);

	}//end testObjectOpCreateHitsObjectService()

	/**
	 * object-op update requires an id and passes the uuid through.
	 *
	 * @return void
	 */
	public function testObjectOpUpdateRequiresId(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = ($this->dispatcher)('object-op', ['schema' => 'permit', 'operation' => 'update'], []);

		$this->assertNull($result);

	}//end testObjectOpUpdateRequiresId()

	/**
	 * webhook POSTs the compiled target URL + payload and returns the status.
	 *
	 * @return void
	 */
	public function testWebhookPostsCompiledTarget(): void {
		$client = $this->createMock(IClient::class);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);

		$client->expects($this->once())
			->method('post')
			->with('https://example.test/hook', $this->arrayHasKey('json'))
			->willReturn($response);

		$this->httpClientService->method('newClient')->willReturn($client);

		$status = ($this->dispatcher)('webhook', ['url' => 'https://example.test/hook', 'payload' => ['a' => 1]], []);

		$this->assertSame(200, $status);

	}//end testWebhookPostsCompiledTarget()

	/**
	 * A webhook post never follows a redirect, so a public URL cannot bounce
	 * the request on to an address the egress guard would have refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-010
	 */
	public function testWebhookDoesNotFollowRedirects(): void {
		$client = $this->createMock(IClient::class);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);

		$client->expects($this->once())
			->method('post')
			->with('https://example.test/hook', $this->callback(static fn (array $options): bool => ($options['allow_redirects'] ?? null) === false))
			->willReturn($response);

		$this->httpClientService->method('newClient')->willReturn($client);

		$this->assertSame(200, ($this->dispatcher)('webhook', ['url' => 'https://example.test/hook'], []));

	}//end testWebhookDoesNotFollowRedirects()

	/**
	 * A webhook URL the maker typed that points at a metadata endpoint, a
	 * private or loopback address, or a scheme other than http(s) is refused
	 * before any request leaves the server (ADR-067, buildiq#987).
	 *
	 * @param string $url The refused webhook URL.
	 *
	 * @return void
	 *
	 * @dataProvider refusedWebhookUrls
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-010
	 */
	public function testWebhookToANonPublicTargetIsRefusedBeforeAnyRequest(string $url): void {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('post');
		$this->httpClientService->method('newClient')->willReturn($client);

		$this->assertNull(($this->dispatcher)('webhook', ['url' => $url, 'payload' => ['a' => 1]], []));

	}//end testWebhookToANonPublicTargetIsRefusedBeforeAnyRequest()

	/**
	 * Webhook URLs the egress guard must refuse.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function refusedWebhookUrls(): array {
		return [
			'cloud metadata endpoint' => ['http://169.254.169.254/latest/meta-data/'],
			'loopback' => ['http://127.0.0.1:8080/hook'],
			'private range' => ['http://10.0.0.5/hook'],
			'ipv6 loopback' => ['http://[::1]/hook'],
			'file scheme' => ['file:///etc/passwd'],
			'gopher scheme' => ['gopher://example.test/_hook'],
		];

	}//end refusedWebhookUrls()

	/**
	 * An unrecognised action type never throws — it is logged and no-op'd.
	 *
	 * @return void
	 */
	public function testUnknownActionTypeIsNoOp(): void {
		$result = ($this->dispatcher)('not-a-real-action', [], []);

		$this->assertNull($result);

	}//end testUnknownActionTypeIsNoOp()
}//end class
