<?php

/**
 * The readers reviewed for the organisation scope consult the guard.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\ApplicationDeletionService;
use OCA\Buildiq\Service\ApplicationInsightsService;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Service\AgentRunLogger;
use OCA\Buildiq\Exception\CopilotException;
use OCA\Buildiq\Service\Copilot\CopilotPlanValidator;
use OCA\Buildiq\Service\Copilot\CopilotPromptBuilder;
use OCA\Buildiq\Service\CopilotService;
use OCA\Buildiq\Service\IconService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Another organisation's scoped app is "not found" through each reader that
 * operate-organisation-scoped-apps T04 reviewed: insights, the app icon,
 * automation writes and the copilot. A member, and a shared app, pass.
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class OrganisationScopeReviewedReadersTest extends TestCase {

	/**
	 * A scoped app alice may edit.
	 *
	 * @var array<string, mixed>
	 */
	private const SCOPED_APP = [
		'uuid' => 'app-uuid',
		'id' => 'app-uuid',
		'slug' => 'permit-tracker',
		'organisationScope' => 'holder',
		'productionVersion' => 'prod-uuid',
		'permissions' => ['owners' => ['user:alice'], 'viewers' => ['user:alice']],
	];

	/**
	 * A group manager that knows no groups and no admins.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(): IGroupManager {
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);
		return $groupManager;
	}//end groupManager()

	/**
	 * An OpenRegister object rendering the given payload.
	 *
	 * @param array<string, mixed> $payload The object's data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid = 'alice'): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * A guard answering `canSee` with the given value.
	 *
	 * @param bool $member Whether the caller belongs to the holder.
	 *
	 * @return AppOrganisationGuard
	 */
	private function guard(bool $member): AppOrganisationGuard {
		$guard = $this->createMock(originalClassName: AppOrganisationGuard::class);
		$guard->method('canSee')->willReturn($member);
		return $guard;
	}//end guard()

	/**
	 * The insights service over a production version of the given app.
	 *
	 * @param array<string, mixed>      $app   The Application.
	 * @param AppOrganisationGuard|null $guard The guard, if any.
	 *
	 * @return ApplicationInsightsService
	 */
	private function insights(array $app, ?AppOrganisationGuard $guard): ApplicationInsightsService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->entity(payload: $app),
			$this->entity(payload: ['uuid' => 'prod-uuid', 'application' => 'app-uuid', 'manifest' => ['pages' => []]])
		);
		$cache = $this->createMock(originalClassName: ICache::class);
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new ApplicationInsightsService(
			store: new RegisterStore(
				objectService: $objectService,
				registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
				schemaMapper: $this->createMock(originalClassName: SchemaMapper::class)
			),
			auditTrailMapper: $this->createMock(originalClassName: AuditTrailMapper::class),
			cacheFactory: $cacheFactory,
			logger: $logger,
			permissionResolver: new PermissionResolver(groupManager: $this->groupManager(), logger: $logger),
			organisationGuard: $guard
		);
	}//end insights()

	/**
	 * Ask insights whether alice may read the production version of the app.
	 *
	 * @param array<string, mixed>      $app   The Application.
	 * @param AppOrganisationGuard|null $guard The guard, if any.
	 *
	 * @return array<int, array<string, mixed>>|null The app and version, or null.
	 */
	private function authorise(array $app, ?AppOrganisationGuard $guard): ?array {
		return $this->insights(app: $app, guard: $guard)->requireAuthorisedCaller(
			appUuid: 'app-uuid',
			versionUuid: 'prod-uuid',
			caller: $this->user()
		);
	}//end authorise()

	/**
	 * Insights: a non-member gets nothing, a member gets the app, and a shared app needs no guard.
	 *
	 * @return void
	 */
	public function testInsightsHideAScopedAppFromANonMember(): void {
		self::assertNull(actual: $this->authorise(app: self::SCOPED_APP, guard: $this->guard(member: false)));
		self::assertNull(actual: $this->authorise(app: self::SCOPED_APP, guard: null));
		self::assertNotNull(actual: $this->authorise(app: self::SCOPED_APP, guard: $this->guard(member: true)));

		$shared = array_merge(self::SCOPED_APP, ['organisationScope' => 'all']);
		self::assertNotNull(actual: $this->authorise(app: $shared, guard: null));
	}//end testInsightsHideAScopedAppFromANonMember()

	/**
	 * The icon service, with a file service expecting the given number of reads.
	 *
	 * @param AppOrganisationGuard|null $guard The guard, if any.
	 * @param int                       $reads How often the app's own icon file is read.
	 *
	 * @return IconService
	 */
	private function icons(?AppOrganisationGuard $guard, int $reads): IconService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([array_merge(self::SCOPED_APP, ['icon' => ['ref' => 'app-icon.svg']])]);
		$file = $this->createMock(originalClassName: File::class);
		$file->method('getContent')->willReturn('<svg xmlns="http://www.w3.org/2000/svg"></svg>');
		$fileService = $this->createMock(originalClassName: FileService::class);
		$fileService->expects(self::exactly(count: $reads))->method('getFile')->willReturn($file);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->user());

		return new IconService(
			objectService: $objectService,
			fileService: $fileService,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			serverRoot: sys_get_temp_dir(),
			organisationGuard: $guard,
			userSession: $session
		);
	}//end icons()

	/**
	 * Icon: a non-member gets the default icon and the app's own file is never read.
	 *
	 * @return void
	 */
	public function testIconOfAScopedAppIsTheDefaultForANonMember(): void {
		$this->icons(guard: $this->guard(member: false), reads: 0)->getIconStream(slug: 'permit-tracker', dark: false);
		$result = $this->icons(guard: $this->guard(member: true), reads: 1)->getIconStream(slug: 'permit-tracker', dark: false);
		self::assertSame(expected: 'image/svg+xml', actual: $result['mimeType']);
	}//end testIconOfAScopedAppIsTheDefaultForANonMember()

	/**
	 * The automation write service with alice signed in.
	 *
	 * @param AppOrganisationGuard|null $guard The guard, if any.
	 *
	 * @return AutomationWriteService
	 */
	private function automations(?AppOrganisationGuard $guard): AutomationWriteService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($this->entity(payload: self::SCOPED_APP));
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->user());
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new AutomationWriteService(
			logger: $logger,
			objectService: $objectService,
			compiler: $this->createMock(originalClassName: AutomationCompilerService::class),
			permissionResolver: new PermissionResolver(groupManager: $this->groupManager(), logger: $logger),
			userSession: $session,
			organisationGuard: $guard
		);
	}//end automations()

	/**
	 * Automations: a scoped app loads only for a member.
	 *
	 * @return void
	 */
	public function testAutomationWritesDoNotFindAnotherOrganisationsApp(): void {
		self::assertNull(actual: $this->automations(guard: $this->guard(member: false))->loadApplication(slug: 'permit-tracker'));
		self::assertNull(actual: $this->automations(guard: null)->loadApplication(slug: 'permit-tracker'));
		$loaded = $this->automations(guard: $this->guard(member: true))->loadApplication(slug: 'permit-tracker');
		self::assertSame(expected: 'permit-tracker', actual: $loaded['slug'] ?? null);
	}//end testAutomationWritesDoNotFindAnotherOrganisationsApp()

	/**
	 * The copilot over the scoped app, with alice known to the user manager.
	 *
	 * @param AppOrganisationGuard|null $guard The guard, if any.
	 *
	 * @return CopilotService
	 */
	private function copilot(?AppOrganisationGuard $guard): CopilotService {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('searchObjectsBySlug')->willReturn([self::SCOPED_APP]);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('get')->willReturn($this->user());
		$toolProvider = $this->createMock(originalClassName: BuildiqToolProvider::class);
		$toolProvider->method('getToolDescriptors')->willReturn([]);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$groupManager = $this->groupManager();

		return new CopilotService(
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $logger,
			objectService: $objectService,
			userManager: $userManager,
			groupManager: $groupManager,
			permissionResolver: new PermissionResolver(groupManager: $groupManager, logger: $logger),
			toolProvider: $toolProvider,
			planValidator: new CopilotPlanValidator(),
			promptBuilder: new CopilotPromptBuilder(toolProvider: $toolProvider),
			appDeletionService: $this->createMock(originalClassName: ApplicationDeletionService::class),
			agentRunLogger: $this->createMock(originalClassName: AgentRunLogger::class),
			organisationGuard: $guard
		);
	}//end copilot()

	/**
	 * Copilot: a non-member owner gets "not found", a member owner gets the app.
	 *
	 * @return void
	 */
	public function testCopilotAnswersNotFoundForAnotherOrganisationsApp(): void {
		$app = $this->copilot(guard: $this->guard(member: true))->requireWritableApp(appSlug: 'permit-tracker', userId: 'alice');
		self::assertSame(expected: 'permit-tracker', actual: $app['slug']);

		try {
			$this->copilot(guard: $this->guard(member: false))->requireWritableApp(appSlug: 'permit-tracker', userId: 'alice');
			self::fail(message: 'A non-member must not reach the app.');
		} catch (CopilotException $e) {
			self::assertSame(expected: 404, actual: $e->getHttpStatus());
			self::assertSame(expected: 'not_found', actual: $e->getErrorCode());
		}
	}//end testCopilotAnswersNotFoundForAnotherOrganisationsApp()
}//end class
