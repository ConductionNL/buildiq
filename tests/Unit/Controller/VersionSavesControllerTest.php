<?php

/**
 * VersionSavesControllerTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\VersionSavesController;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Listener\VersionSaveListener;
use OCA\Buildiq\Service\AppChangeHistory;
use OCA\Buildiq\Service\VersionSaveHistory;
use OCA\Buildiq\Service\VersionSaveReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Listing a version's saves, reading one, and keeping them as versions are saved.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
class VersionSavesControllerTest extends TestCase {

	/**
	 * The saved manifests.
	 *
	 * @var VersionSaveHistory&MockObject
	 */
	private VersionSaveHistory&MockObject $saves;

	/**
	 * The roles the resolver was asked for, per call.
	 *
	 * @var list<array{roles:list<string>,allowAdminBypass:?bool}>
	 */
	private array $asked = [];

	/**
	 * A store that answers one save of the development version.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saves = $this->createMock(originalClassName: VersionSaveHistory::class);
	}//end setUp()

	/**
	 * A viewer lists the saves of the development version, without an admin bypass.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function aViewerListsTheSavesOfAVersion(): void {
		$list = [['id' => 'save-2', 'savedAt' => '2026-11-24T10:00:02+00:00', 'user' => 'sanne', 'pages' => ['dashboard']]];
		$this->saves->expects(self::once())->method('saves')->with('v-dev')->willReturn($list);

		$response = $this->controller(uid: 'olaf')->index(appSlug: 'permits', versionSlug: 'development');

		self::assertSame(expected: [200, ['saves' => $list]], actual: [$response->getStatus(), $response->getData()]);
		self::assertSame(expected: [['owners', 'editors', 'viewers'], false], actual: [$this->asked[0]['roles'], $this->asked[0]['allowAdminBypass']]);
	}//end aViewerListsTheSavesOfAVersion()

	/**
	 * One save's manifest comes back whole; an unknown save is 404.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function oneSavesManifestComesBack(): void {
		$manifest = ['pages' => [['id' => 'permits']]];
		$this->saves->method('manifest')->willReturnMap([['v-dev', 'save-1', $manifest], ['v-dev', 'save-9', null]]);

		$found   = $this->controller(uid: 'olaf')->show(appSlug: 'permits', versionSlug: 'development', saveId: 'save-1');
		$missing = $this->controller(uid: 'olaf')->show(appSlug: 'permits', versionSlug: 'development', saveId: 'save-9');

		self::assertSame(expected: [200, ['manifest' => $manifest]], actual: [$found->getStatus(), $found->getData()]);
		self::assertSame(expected: [404, ['error' => 'not_found']], actual: [$missing->getStatus(), $missing->getData()]);
	}//end oneSavesManifestComesBack()

	/**
	 * Refusals: nobody signed in 401, no role 403, an app the caller cannot see or another app's version 404;
	 * none of them reads a save.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function refusalsReadNoSave(): void {
		$this->saves->expects(self::never())->method('saves');
		$this->saves->expects(self::never())->method('manifest');

		$answers = [
			$this->controller(uid: null)->index(appSlug: 'permits', versionSlug: 'development'),
			$this->controller(uid: 'mallory')->index(appSlug: 'permits', versionSlug: 'development'),
			$this->controller(uid: 'olaf', sees: false)->index(appSlug: 'permits', versionSlug: 'development'),
			$this->controller(uid: 'olaf')->index(appSlug: 'permits', versionSlug: 'production'),
			$this->controller(uid: 'mallory')->show(appSlug: 'permits', versionSlug: 'development', saveId: 'save-1'),
		];

		self::assertSame(
			expected: [401, 403, 404, 404, 403],
			actual: array_map(static fn ($response): int => $response->getStatus(), $answers)
		);
	}//end refusalsReadNoSave()

	/**
	 * A store that fails is a 500 without detail.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function aFailingStoreIsAnInternalError(): void {
		$this->saves->method('saves')->willThrowException(new RuntimeException('storage down'));

		$response = $this->controller(uid: 'olaf')->index(appSlug: 'permits', versionSlug: 'development');

		self::assertSame(expected: [500, ['error' => 'internal_error']], actual: [$response->getStatus(), $response->getData()]);
	}//end aFailingStoreIsAnInternalError()

	/**
	 * The listener keeps the manifest of a created or updated version, as the signed-in user.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function theListenerKeepsEveryVersionSave(): void {
		$kept = [];
		$this->saves->method('record')->willReturnCallback(
			static function (string $versionUuid, array $manifest, string $user) use (&$kept): string {
				$kept[] = [$versionUuid, $manifest['pages'][0]['id'], $user];
				return 'save';
			}
		);
		$listener = $this->listener(schema: 'applicationVersion');

		$created = $this->entity(uuid: 'v-dev', data: ['manifest' => ['pages' => [['id' => 'permits']]]]);
		$updated = $this->entity(uuid: 'v-dev', data: ['manifest' => ['pages' => [['id' => 'dashboard']]]]);
		$listener->handle(event: new ObjectCreatedEvent(object: $created));
		$listener->handle(event: new ObjectUpdatedEvent(newObject: $updated));

		self::assertSame(expected: [['v-dev', 'permits', 'olaf'], ['v-dev', 'dashboard', 'olaf']], actual: $kept);
	}//end theListenerKeepsEveryVersionSave()

	/**
	 * Other records, versions without a manifest and other events are left alone; a failing store never throws.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function theListenerLeavesEverythingElseAlone(): void {
		$this->saves->expects(self::once())->method('record')->willThrowException(new RuntimeException('storage down'));

		$version = $this->listener(schema: 'applicationVersion');
		$this->listener(schema: 'permit')->handle(event: new ObjectUpdatedEvent(newObject: $this->entity(uuid: 'p-1', data: ['manifest' => []])));
		$version->handle(event: new ObjectUpdatedEvent(newObject: $this->entity(uuid: 'v-dev', data: ['status' => 'draft'])));
		$version->handle(event: new Event());
		$version->handle(event: new ObjectUpdatedEvent(newObject: $this->entity(uuid: 'v-dev', data: ['manifest' => []])));

		$this->addToAssertionCount(count: 1);
	}//end theListenerLeavesEverythingElseAlone()

	/**
	 * The listener with a slug resolver answering buildiq's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return VersionSaveListener
	 */
	private function listener(string $schema): VersionSaveListener {
		$slugs = $this->createMock(originalClassName: ObjectSchemaSlugResolverInterface::class);
		$slugs->method('registerSlug')->willReturn('buildiq');
		$slugs->method('schemaSlug')->willReturn($schema);

		return new VersionSaveListener(
			slugs: $slugs,
			saves: $this->saves,
			userSession: $this->session(uid: 'olaf'),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end listener()

	/**
	 * An object entity with a uuid and data.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getUuid')->willReturn($uuid);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A session with this user signed in, or nobody.
	 *
	 * @param string|null $uid The user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The controller; olaf has a role on app `permits`, whose only version is `development`.
	 *
	 * @param string|null $uid  The signed-in user, or null for nobody.
	 * @param bool        $sees Whether the caller's organisation sees the app.
	 *
	 * @return VersionSavesController
	 */
	private function controller(?string $uid, bool $sees = true): VersionSavesController {
		$application = ['id' => 'app-uuid', 'slug' => 'permits', 'permissions' => ['viewers' => ['user:olaf']]];
		$versions    = [
			['id' => 'v-dev', 'slug' => 'development', 'application' => 'app-uuid'],
			['id' => 'v-other', 'slug' => 'production', 'application' => 'other-uuid'],
		];
		$objects     = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			static function (array $query) use ($application, $versions): array {
				if (isset($query['slug']) === true) {
					return [$application];
				}

				return $versions;
			}
		);

		$register = new Register();
		$register->setId(1);
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$resolver = $this->createMock(originalClassName: PermissionResolverInterface::class);
		$resolver->method('seesApplication')->willReturn($sees);
		$resolver->method('resolveUserGroups')->willReturn([]);
		$resolver->method('matchesCaller')->willReturnCallback(
			function (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles): bool {
				$this->asked[] = ['allowAdminBypass' => $allowAdminBypass, 'roles' => $roles];
				return $caller->getUID() === 'olaf';
			}
		);

		return new VersionSavesController(
			request: $this->createMock(originalClassName: IRequest::class),
			reader: new VersionSaveReader(
				permissionResolver: $resolver,
				apps: new AppChangeHistory(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
				saves: $this->saves,
				userSession: $this->session(uid: $uid)
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()
}//end class
