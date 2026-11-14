<?php

/**
 * Organisation scope on the wizard's create path.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-an-app-has-an-organisation-scope-req-bqos-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\WizardCreationException;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\ApplicationCreationService;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\SlugValidator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A new app gets the default organisation scope, and a taken slug is refused
 * without saying who holds it.
 *
 * @covers \OCA\Buildiq\Service\ApplicationCreationService
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Service\SlugValidator
 * @uses   \OCA\Buildiq\Exception\WizardCreationException
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 * @uses   \OCA\Buildiq\Service\ApplicationVersionService
 */
class ApplicationCreationOrganisationScopeTest extends TestCase {

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Every object saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The new app takes the scope the guard gives, `holder` while multitenancy is on.
	 *
	 * @return void
	 */
	public function testANewAppStartsWithTheGuardsDefaultScope(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->expects(self::once())->method('defaultScope')->willReturn('holder');

		$uuid = $this->service(guard: $guard, existing: [])->createApplication(['name' => 'Toezicht', 'slug' => 'toezicht', 'preset' => 'single']);

		self::assertSame('app-uuid-1', $uuid);
		self::assertSame('toezicht', $this->saved[0]['slug']);
		self::assertSame('holder', $this->saved[0]['organisationScope']);
		self::assertSame(['user:maker'], $this->saved[0]['permissions']['owners']);
	}//end testANewAppStartsWithTheGuardsDefaultScope()

	/**
	 * With multitenancy off the guard answers `all`, and the app is shared.
	 *
	 * @return void
	 */
	public function testANewAppIsSharedWhenTheGuardSaysAll(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->method('defaultScope')->willReturn('all');

		$this->service(guard: $guard, existing: [])->createApplication(['name' => 'Toezicht', 'slug' => 'toezicht', 'preset' => 'single']);

		self::assertSame('all', $this->saved[0]['organisationScope']);
	}//end testANewAppIsSharedWhenTheGuardSaysAll()

	/**
	 * Without a guard the scope stays absent, which means all organisations.
	 *
	 * @return void
	 */
	public function testWithoutAGuardTheScopeStaysAbsent(): void {
		$this->service(guard: null, existing: [])->createApplication(['name' => 'Toezicht', 'slug' => 'toezicht', 'preset' => 'single']);

		self::assertArrayNotHasKey('organisationScope', $this->saved[0]);
	}//end testWithoutAGuardTheScopeStaysAbsent()

	/**
	 * A slug held by another organisation's scoped app is refused with the
	 * generic message, which names neither the app nor its organisation.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-slugs-stay-unique-without-leaking-req-bqos-004
	 *
	 * @return void
	 */
	public function testATakenSlugSaysNothingAboutWhoTookIt(): void {
		$taken = [['slug' => 'toezicht', 'organisationScope' => 'holder', '@self' => ['organisation' => 'Omgevingsdienst Noord']]];
		$service = $this->service(guard: $this->createMock(AppOrganisationGuard::class), existing: $taken);

		try {
			$service->createApplication(['name' => 'Toezicht', 'slug' => 'toezicht', 'preset' => 'single']);
			self::fail('A taken slug must be refused');
		} catch (WizardCreationException $e) {
			self::assertSame('app_slug_conflict', $e->getErrorCode());
			self::assertSame('validate', $e->getFailedAtStep());
			self::assertSame('This slug is taken. Choose another.', $e->getMessage());
			self::assertSame(ApplicationCreationService::SLUG_TAKEN, $e->getMessage());
		}

		self::assertSame([], $this->saved, 'Nothing is saved for a taken slug');
	}//end testATakenSlugSaysNothingAboutWhoTookIt()

	/**
	 * The service over mocks: the slug search answers `$existing`, saves are recorded.
	 *
	 * @param AppOrganisationGuard|null        $guard    The guard.
	 * @param array<int, array<string, mixed>> $existing What the slug search finds.
	 *
	 * @return ApplicationCreationService The service.
	 */
	private function service(?AppOrganisationGuard $guard, array $existing): ApplicationCreationService {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturn($existing);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn(['id' => 'app-uuid-1', 'uuid' => 'app-uuid-1'] + $object);
				return $entity;
			}
		);

		$register = $this->getMockBuilder(Register::class)->disableOriginalConstructor()->onlyMethods(['getSchemas', 'setSchemas', 'getId'])->getMock();
		$register->method('getSchemas')->willReturn([]);
		$register->method('getId')->willReturn(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$registerMapper->method('createFromArray')->willReturn($register);
		$registerMapper->method('update')->willReturn($register);

		$schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(2);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);
		$schemaMapper->method('createFromArray')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maker');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ApplicationCreationService(
			logger: $this->createMock(LoggerInterface::class),
			store: new RegisterStore(objectService: $this->objectService, registerMapper: $registerMapper, schemaMapper: $schemaMapper),
			registerService: $this->createMock(RegisterService::class),
			userSession: $session,
			slugValidator: new SlugValidator(),
			organisationGuard: $guard,
		);
	}//end service()
}//end class
