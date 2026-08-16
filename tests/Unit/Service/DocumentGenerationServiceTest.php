<?php

/**
 * Unit tests for DocumentGenerationService.
 *
 * Covers the impersonation call path, the Docudesk HTTP contract (single
 * dataRefs entry, pinned route), and the attach/download-link/notify output
 * branches (automation-document-action spec).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-document-action/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\DocumentGenerationService;
use OCA\OpenBuild\Service\JobOwnerImpersonator;
use OCA\OpenBuild\Service\RuleActionDispatcher;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use stdClass;

/**
 * Tests for {@see DocumentGenerationService}.
 */
final class DocumentGenerationServiceTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var JobOwnerImpersonator&MockObject
	 */
	private JobOwnerImpersonator&MockObject $ownerImpersonator;

	/**
	 * @var RuleActionDispatcher&MockObject
	 */
	private RuleActionDispatcher&MockObject $ruleActionDispatcher;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $httpClientService;

	/**
	 * @var IRootFolder&MockObject
	 */
	private IRootFolder&MockObject $rootFolder;

	/**
	 * @var IAppDataFactory&MockObject
	 */
	private IAppDataFactory&MockObject $appDataFactory;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The service under test.
	 *
	 * @var DocumentGenerationService
	 */
	private DocumentGenerationService $service;

	/**
	 * Standard test automation + generateDocument action fixtures.
	 */
	private const AUTOMATION = ['applicationSlug' => 'permit-tracker'];
	private const ACTION = ['templateId' => 'tpl-1', 'output' => ['attach']];

	/**
	 * Wire the service with mocked boundaries; JobOwnerImpersonator's
	 * runAsOwner() just invokes the callback directly (mirrors a successful
	 * impersonation without exercising the real owner-lookup logic, which
	 * has its own dedicated test suite).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->ownerImpersonator = $this->createMock(JobOwnerImpersonator::class);
		$this->ruleActionDispatcher = $this->createMock(RuleActionDispatcher::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->httpClientService = $this->createMock(IClientService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);
		$this->container = $this->createMock(ContainerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('app-owner');
		$this->userSession->method('getUser')->willReturn($user);

		$this->ownerImpersonator->method('runAsOwner')->willReturnCallback(
			static fn (string $objectId, callable $work) => $work()
		);

		// No internal token provider available by default — Docudesk calls
		// fail closed (individual tests override to exercise the happy path).
		$this->container->method('has')->willReturn(false);

		$this->urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []) => 'https://cloud.test/' . $route . '/' . implode('/', $params)
		);

		$application = ['id' => 'app-uuid-1', 'slug' => 'permit-tracker'];
		$this->objectService->method('searchObjects')->willReturn([$application]);

		$registerEntity = $this->createMock(Register::class);
		$registerEntity->method('getId')->willReturn(1);
		$this->registerMapper->method('find')->willReturn($registerEntity);

		$schemaEntity = $this->createMock(Schema::class);
		$schemaEntity->method('getId')->willReturn(2);
		$this->schemaMapper->method('find')->willReturn($schemaEntity);

		$this->service = new DocumentGenerationService(
			$this->objectService,
			$this->registerMapper,
			$this->schemaMapper,
			$this->ownerImpersonator,
			$this->ruleActionDispatcher,
			$this->userSession,
			$this->urlGenerator,
			$this->httpClientService,
			$this->rootFolder,
			$this->appDataFactory,
			$this->container,
			new NullLogger(),
			objectService: $provider,
		);

	}//end setUp()

	/**
	 * Wire a working internal-token provider + successful Docudesk HTTP
	 * response, capturing the request body for assertion.
	 *
	 * @param array<string,mixed> $captured Reference filled with the request options.
	 *
	 * @return void
	 */
	private function wireSuccessfulDocudeskCall(array &$captured): void {
		$provider = $this->getMockBuilder(stdClass::class)->addMethods(['generateToken', 'invalidateToken'])->getMock();
		$provider->method('generateToken')->willReturn(null);
		$provider->method('invalidateToken')->willReturn(null);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willReturn($provider);

		$client = $this->createMock(IClient::class);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('%PDF-bytes%');
		$response->method('getHeader')->willReturn('application/pdf');

		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$captured, $response) {
				$captured = $options;
				return $response;
			}
		);
		$this->httpClientService->method('newClient')->willReturn($client);

		$this->service = new DocumentGenerationService(
			$this->objectService,
			$this->registerMapper,
			$this->schemaMapper,
			$this->ownerImpersonator,
			$this->ruleActionDispatcher,
			$this->userSession,
			$this->urlGenerator,
			$this->httpClientService,
			$this->rootFolder,
			$this->appDataFactory,
			$this->container,
			new NullLogger(),
			objectService: $provider,
		);

	}//end wireSuccessfulDocudeskCall()

	/**
	 * REQ: object data maps to template variables exclusively via a single
	 * dataRefs entry — no flattening.
	 *
	 * @return void
	 */
	public function testDataRefsContainsExactlyOneEntryNamingTheObject(): void {
		$captured = [];
		$this->wireSuccessfulDocudeskCall($captured);

		$folder = $this->createMock(Folder::class);
		$folder->method('newFolder')->willReturn($folder);
		$folder->method('nodeExists')->willReturn(false);
		$file = $this->createMock(Node::class);
		$folder->method('newFile')->willReturn($file);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->service->generate(self::AUTOMATION, self::ACTION, 'permit', 'obj-uuid-9');

		$this->assertArrayHasKey('json', $captured);
		$this->assertSame(
			[['register' => 'openbuild', 'schema' => 'permit', 'id' => 'obj-uuid-9']],
			$captured['json']['dataRefs']
		);
		$this->assertSame('tpl-1', $captured['json']['templateId']);

	}//end testDataRefsContainsExactlyOneEntryNamingTheObject()

	/**
	 * `attach` output writes the bytes to Files and sets the `{ref}`
	 * attachment field on the object.
	 *
	 * @return void
	 */
	public function testAttachModeWritesFileReferenceOnObject(): void {
		$captured = [];
		$this->wireSuccessfulDocudeskCall($captured);

		$folder = $this->createMock(Folder::class);
		$folder->method('newFolder')->willReturn($folder);
		$folder->method('nodeExists')->willReturn(false);
		$file = $this->createMock(Node::class);
		$file->method('getId')->willReturn(42);
		$folder->method('newFile')->willReturn($file);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		// NOTE: ObjectService::saveObject()'s real signature is
		// (object, extend, register, schema, uuid, ...) — `extend` sits
		// BETWEEN `object` and `register`; since writeAttachmentReference()
		// calls it with named args and omits `extend`, PHP still fills its
		// default ([]) into that positional slot, so the mock receives 5
		// positional arguments, not 4.
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->equalTo([DocumentGenerationService::ATTACHMENT_FIELD => ['ref' => '42']]),
				$this->equalTo([]),
				$this->equalTo('openbuild'),
				$this->equalTo('permit'),
				$this->equalTo('obj-uuid-9')
			);

		$result = $this->service->generate(self::AUTOMATION, ['templateId' => 'tpl-1', 'output' => ['attach']], 'permit', 'obj-uuid-9');

		$this->assertTrue($result);

	}//end testAttachModeWritesFileReferenceOnObject()

	/**
	 * `download-link` output writes to app-private storage, NEVER the
	 * user's Files tree (`IRootFolder` untouched).
	 *
	 * @return void
	 */
	public function testDownloadLinkModeNeverTouchesUserFiles(): void {
		$captured = [];
		$this->wireSuccessfulDocudeskCall($captured);

		$this->rootFolder->expects($this->never())->method('getUserFolder');

		$appData = $this->createMock(IAppData::class);
		$tokenFolder = $this->createMock(ISimpleFolder::class);
		$rootAppFolder = $this->createMock(ISimpleFolder::class);
		$rootAppFolder->method('newFolder')->willReturn($tokenFolder);
		$appData->method('getFolder')->willThrowException(new NotFoundException());
		$appData->method('newFolder')->willReturn($rootAppFolder);
		$tokenFolder->method('newFile')->willReturn($this->createMock(ISimpleFile::class));
		$this->appDataFactory->method('get')->willReturn($appData);

		$result = $this->service->generate(
			self::AUTOMATION,
			['templateId' => 'tpl-1', 'output' => ['download-link']],
			'permit',
			'obj-uuid-9'
		);

		$this->assertTrue($result);

	}//end testDownloadLinkModeNeverTouchesUserFiles()

	/**
	 * `notify` paired with `attach` dispatches through the shared
	 * RuleActionDispatcher (reuse, not a second notification path).
	 *
	 * @return void
	 */
	public function testNotifyPairedWithAttachDispatchesNotification(): void {
		$captured = [];
		$this->wireSuccessfulDocudeskCall($captured);

		$folder = $this->createMock(Folder::class);
		$folder->method('newFolder')->willReturn($folder);
		$folder->method('nodeExists')->willReturn(false);
		$file = $this->createMock(Node::class);
		$file->method('getId')->willReturn(42);
		$folder->method('newFile')->willReturn($file);
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$this->ruleActionDispatcher->expects($this->once())
			->method('__invoke')
			->with('send-notification', $this->arrayHasKey('recipientUid'), []);

		$this->service->generate(
			self::AUTOMATION,
			['templateId' => 'tpl-1', 'output' => ['attach', 'notify']],
			'permit',
			'obj-uuid-9'
		);

	}//end testNotifyPairedWithAttachDispatchesNotification()

	/**
	 * A missing templateId fails closed — no HTTP call is attempted.
	 *
	 * @return void
	 */
	public function testMissingTemplateIdFailsClosed(): void {
		$this->httpClientService->expects($this->never())->method('newClient');

		$result = $this->service->generate(self::AUTOMATION, ['output' => ['attach']], 'permit', 'obj-uuid-9');

		$this->assertFalse($result);

	}//end testMissingTemplateIdFailsClosed()

	/**
	 * `notify` alone (no attach/download-link) fails closed.
	 *
	 * @return void
	 */
	public function testNotifyAloneFailsClosed(): void {
		$this->httpClientService->expects($this->never())->method('newClient');

		$result = $this->service->generate(self::AUTOMATION, ['templateId' => 'tpl-1', 'output' => ['notify']], 'permit', 'obj-uuid-9');

		$this->assertFalse($result);

	}//end testNotifyAloneFailsClosed()

	/**
	 * When the owning Application cannot be resolved, generation is skipped
	 * (no impersonation, no HTTP call).
	 *
	 * @return void
	 */
	public function testUnresolvableApplicationFailsClosed(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturn([]);

		$this->service = new DocumentGenerationService(
			$this->objectService,
			$this->registerMapper,
			$this->schemaMapper,
			$this->ownerImpersonator,
			$this->ruleActionDispatcher,
			$this->userSession,
			$this->urlGenerator,
			$this->httpClientService,
			$this->rootFolder,
			$this->appDataFactory,
			$this->container,
			new NullLogger(),
			objectService: $provider,
		);

		$this->ownerImpersonator->expects($this->never())->method('runAsOwner');

		$result = $this->service->generate(self::AUTOMATION, self::ACTION, 'permit', 'obj-uuid-9');

		$this->assertFalse($result);

	}//end testUnresolvableApplicationFailsClosed()

	/**
	 * When the internal token provider is unavailable, the Docudesk call is
	 * skipped — fails closed, never fatal (mirrors JobOwnerImpersonator's
	 * soft-optional collaborator posture).
	 *
	 * @return void
	 */
	public function testMissingTokenProviderFailsClosed(): void {
		// Default setUp() container->has() already returns false.
		$result = $this->service->generate(self::AUTOMATION, self::ACTION, 'permit', 'obj-uuid-9');

		$this->assertFalse($result);

	}//end testMissingTokenProviderFailsClosed()
}//end class
