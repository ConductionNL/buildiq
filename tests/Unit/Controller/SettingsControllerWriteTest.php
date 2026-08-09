<?php

/**
 * SettingsController write-path + admin-guard unit tests.
 *
 * Covers the canonical `PUT /api/settings` (`settings#update`) write and its
 * legacy `POST /api/settings` (`settings#create`) alias:
 *   - update() persists the request params through SettingsService and returns
 *     the config the service actually stored.
 *   - create() delegates to update() and therefore still writes.
 *   - The H6 admin guard rejects a non-admin caller with 403 and an
 *     unauthenticated caller with 401 — on BOTH verbs — without writing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/settings-and-observability/spec.md#req-obs-002
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\SettingsController;
use OCA\OpenBuild\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The canonical AppHost route table routes BOTH `PUT /api/settings`
 * (`settings#update`) and `POST /api/settings` (`settings#create`) into this
 * controller, and because OpenBuild ships the class itself no generic is
 * aliased in to cover either.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` with the request's own parameters, and
 * that the returned payload carries the service's result. A test that only
 * checked for a 200, or only that the response was a JSONResponse, would pass
 * against a controller that silently wrote nothing.
 *
 * The guard tests are the highest-value ones here. Nextcloud's
 * SecurityMiddleware only evaluates auth attributes on the DISPATCHED method,
 * so `#[NoAdminRequired]` on `update()` is NOT a guard — if the in-body H6
 * check were ever dropped from the write path, any authenticated user could
 * rewrite instance-wide OpenBuild configuration. Asserting the guard on
 * `update()` (not merely on `create()`) is what makes that regression visible.
 *
 * @spec openspec/specs/settings-and-observability/spec.md#req-obs-002
 */
class SettingsControllerWriteTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The mocked settings service.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * The mocked group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Set up the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);
    }//end setUp()

    /**
     * Build the controller under test with the collaborators mocked.
     *
     * @return SettingsController The controller under test.
     */
    private function controller(): SettingsController
    {
        return new SettingsController(
            request: $this->request,
            settingsService: $this->settingsService,
            userSession: $this->userSession,
            groupManager: $this->groupManager
        );
    }//end controller()

    /**
     * Put a signed-in user with the given uid into the session.
     *
     * @param string $uid The user id to report.
     *
     * @return void
     */
    private function signIn(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end signIn()

    /**
     * PUT /api/settings must persist the request parameters and return the
     * config the service stored (not the submission).
     *
     * @return void
     */
    public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void
    {
        $submitted = [
            'register'     => 'openbuild',
            'registry_url' => 'https://store.example.org',
        ];

        // The service whitelists keys and re-reads, so the stored shape is not
        // the submitted shape — asserting on it proves the response comes from
        // the service rather than being echoed back.
        $stored = [
            'register'           => 'openbuild',
            'registry_url'       => 'https://store.example.org',
            'openregisters'      => true,
            'isAdmin'            => true,
            'registry_token_set' => false,
            'storeConfigured'    => true,
        ];

        $this->signIn('alice');
        $this->groupManager->method('isInGroup')->with('alice', 'admin')->willReturn(true);
        $this->request->method('getParams')->willReturn($submitted);

        // The ITEM: the write reaches the service, with the submitted params.
        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $response = $this->controller()->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [
                'success' => true,
                'config'  => $stored,
            ],
            $response->getData(),
            'update() must return the config the service actually stored, not the submission'
        );
    }//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()

    /**
     * POST /api/settings is a legacy alias and must write identically.
     *
     * The canonical table still ships `settings#create`, so the alias staying a
     * real write — not an empty success — is load-bearing (ADR-029).
     *
     * @return void
     */
    public function testCreateDelegatesToUpdateAndStillWrites(): void
    {
        $submitted = ['register' => 'openbuild'];
        $stored    = [
            'register' => 'openbuild',
            'isAdmin'  => true,
        ];

        $this->signIn('alice');
        $this->groupManager->method('isInGroup')->with('alice', 'admin')->willReturn(true);
        $this->request->method('getParams')->willReturn($submitted);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(
            [
                'success' => true,
                'config'  => $stored,
            ],
            $response->getData(),
            'create() must produce the same written result as update()'
        );
    }//end testCreateDelegatesToUpdateAndStillWrites()

    /**
     * A signed-in NON-ADMIN must be refused on the canonical write with 403,
     * and nothing may be persisted.
     *
     * This is the guard that `#[NoAdminRequired]` does NOT provide.
     *
     * @return void
     */
    public function testUpdateRejectsANonAdminWithForbiddenAndWritesNothing(): void
    {
        $this->signIn('mallory');
        $this->groupManager->method('isInGroup')->with('mallory', 'admin')->willReturn(false);

        $this->settingsService->expects($this->never())->method('updateSettings');

        $response = $this->controller()->update();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['error' => 'Forbidden.'], $response->getData());
    }//end testUpdateRejectsANonAdminWithForbiddenAndWritesNothing()

    /**
     * An unauthenticated caller must be refused on the canonical write with
     * 401, and nothing may be persisted.
     *
     * @return void
     */
    public function testUpdateRejectsAnUnauthenticatedCallerAndWritesNothing(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->settingsService->expects($this->never())->method('updateSettings');

        $response = $this->controller()->update();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Unauthenticated.'], $response->getData());
    }//end testUpdateRejectsAnUnauthenticatedCallerAndWritesNothing()

    /**
     * The legacy POST alias must inherit the same guard through delegation.
     *
     * @return void
     */
    public function testCreateRejectsANonAdminWithForbiddenAndWritesNothing(): void
    {
        $this->signIn('mallory');
        $this->groupManager->method('isInGroup')->with('mallory', 'admin')->willReturn(false);

        $this->settingsService->expects($this->never())->method('updateSettings');

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame(['error' => 'Forbidden.'], $response->getData());
    }//end testCreateRejectsANonAdminWithForbiddenAndWritesNothing()

    /**
     * The legacy POST alias must still reject an unauthenticated caller.
     *
     * @return void
     */
    public function testCreateRejectsAnUnauthenticatedCallerAndWritesNothing(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->settingsService->expects($this->never())->method('updateSettings');

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Unauthenticated.'], $response->getData());
    }//end testCreateRejectsAnUnauthenticatedCallerAndWritesNothing()

    /**
     * `settings#load` re-provisions registers and schemas instance-wide, so its
     * unauthenticated branch must reject too.
     *
     * This branch was the one statement in the controller left uncovered by the
     * existing suite; a guard whose rejecting branch is never executed is a
     * guard nobody has ever seen work.
     *
     * @return void
     */
    public function testLoadRejectsAnUnauthenticatedCallerAndReloadsNothing(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->settingsService->expects($this->never())->method('reloadConfiguration');

        $response = $this->controller()->load();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Unauthenticated.'], $response->getData());
    }//end testLoadRejectsAnUnauthenticatedCallerAndReloadsNothing()

}//end class
