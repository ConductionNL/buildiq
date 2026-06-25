<?php

/**
 * Unit tests for PermissionResolver::matchesUserScopeOwner (layered-versioned-app-deltas).
 *
 * The user-scope ownership rule has NO group logic: a `scope: user`
 * ApplicationVersion is matchable only by the UID in its `owner` field (or by an
 * NC admin under the audited bypass). Exercises owner-allow, foreign-deny,
 * admin-bypass, bypass-disabled, and the fail-closed missing-owner branch.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\PermissionResolver;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PermissionResolver::matchesUserScopeOwner.
 */
class PermissionResolverUserScopeTest extends TestCase
{

    /**
     * Mocked group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Resolver under test (real, with mocked group manager + logger).
     *
     * @var PermissionResolver
     */
    private PermissionResolver $resolver;

    /**
     * Wire the resolver.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $logger         = $this->createMock(originalClassName: LoggerInterface::class);
        $this->resolver = new PermissionResolver(groupManager: $this->groupManager, logger: $logger);
    }//end setUp()

    /**
     * Build a mocked caller with a UID and admin flag.
     *
     * @param string $uid     The caller UID.
     * @param bool   $isAdmin Whether the caller is an NC admin.
     *
     * @return IUser&MockObject
     */
    private function caller(string $uid, bool $isAdmin=false): IUser&MockObject
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->groupManager->method('isAdmin')->willReturn($isAdmin);
        return $user;
    }//end caller()

    /**
     * The owner of a user-scoped row is authorised.
     *
     * @return void
     */
    public function testOwnerIsMatched(): void
    {
        $caller = $this->caller(uid: 'alice');
        $result = $this->resolver->matchesUserScopeOwner(
            version: ['scope' => 'user', 'owner' => 'alice'],
            caller: $caller
        );
        self::assertTrue(condition: $result);
    }//end testOwnerIsMatched()

    /**
     * A non-owner, non-admin caller is denied a foreign user delta (no-admin-idor).
     *
     * @return void
     */
    public function testForeignUserDenied(): void
    {
        $caller = $this->caller(uid: 'bob', isAdmin: false);
        $result = $this->resolver->matchesUserScopeOwner(
            version: ['scope' => 'user', 'owner' => 'alice'],
            caller: $caller
        );
        self::assertFalse(condition: $result);
    }//end testForeignUserDenied()

    /**
     * An NC admin is granted via the audited bypass (default on).
     *
     * @return void
     */
    public function testAdminBypassGrantsForeignDelta(): void
    {
        $caller = $this->caller(uid: 'admin', isAdmin: true);
        $result = $this->resolver->matchesUserScopeOwner(
            version: ['scope' => 'user', 'owner' => 'alice'],
            caller: $caller
        );
        self::assertTrue(condition: $result);
    }//end testAdminBypassGrantsForeignDelta()

    /**
     * With the bypass explicitly disabled, even an admin is denied a foreign delta.
     *
     * @return void
     */
    public function testAdminDeniedWhenBypassDisabled(): void
    {
        $caller = $this->caller(uid: 'admin', isAdmin: true);
        $result = $this->resolver->matchesUserScopeOwner(
            version: ['scope' => 'user', 'owner' => 'alice'],
            caller: $caller,
            allowAdminBypass: false
        );
        self::assertFalse(condition: $result);
    }//end testAdminDeniedWhenBypassDisabled()

    /**
     * A user-scoped row with no resolvable owner is never matchable (fail-closed).
     *
     * @return void
     */
    public function testMissingOwnerFailsClosed(): void
    {
        $caller = $this->caller(uid: 'alice', isAdmin: true);
        $result = $this->resolver->matchesUserScopeOwner(
            version: ['scope' => 'user'],
            caller: $caller
        );
        self::assertFalse(condition: $result);
    }//end testMissingOwnerFailsClosed()
}//end class
