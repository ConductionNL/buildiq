<?php

/**
 * Sanity tests for the OpenBuilt namespace / Application class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit
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

namespace OCA\OpenBuilt\Tests\Unit;

use OCA\OpenBuilt\AppInfo\Application;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the autoload + namespace wiring is healthy and that the canonical
 * APP_ID constant is intact — both invariants other tests (and the route
 * registration) depend on.
 */
class OpenBuiltTest extends TestCase
{

    /**
     * Application::APP_ID must equal the registered Nextcloud app slug. Any
     * downstream code that builds URLs or DI namespaces off APP_ID breaks if
     * this changes silently.
     *
     * @return void
     */
    public function testAppIdConstantMatchesNextcloudSlug(): void
    {
        self::assertSame(expected: 'openbuilt', actual: Application::APP_ID);

    }//end testAppIdConstantMatchesNextcloudSlug()

    /**
     * The PSR-4 prefix `OCA\OpenBuilt\` must resolve to the worktree's lib/
     * directory. This protects against autoload regressions where a stale
     * sibling checkout's `vendor/composer/autoload_classmap.php` masks the
     * local sources (the exact failure mode hit during this branch's
     * bootstrap refactor).
     *
     * @return void
     */
    public function testAutoloadResolvesWorktreeLibDirectory(): void
    {
        $reflection = new \ReflectionClass(Application::class);
        $file       = $reflection->getFileName();
        self::assertIsString($file);
        self::assertStringContainsString(needle: 'lib/AppInfo/Application.php', haystack: $file);

    }//end testAutoloadResolvesWorktreeLibDirectory()

}//end class
