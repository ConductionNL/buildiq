<?php

/**
 * Unit tests for the API paths a standard or peer protocol defines.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Middleware\Support;

use OCA\Buildiq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The health and metrics paths are recognised whether given app-relative or
 * as a full request path, and nothing else is.
 *
 * @covers \OCA\Buildiq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relativePaths(): array {
		return [
			'app-relative'          => ['/api/health', '/api/health'],
			'no leading slash'      => ['api/metrics', '/api/metrics'],
			'request path'          => ['/index.php/apps/buildiq/api/health', '/api/health'],
			'request without index' => ['/apps/buildiq/api/v1/things', '/api/v1/things'],
			'other app'             => ['/apps/other/api/health', '/apps/other/api/health'],
			'app root without path' => ['/apps/buildiq', '/apps/buildiq'],
		];
	}//end relativePaths()

	/**
	 * Each path reduces to the expected app-relative path.
	 *
	 * @param string $path     The path.
	 * @param string $relative The expected app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testAppRelative(string $path, string $relative): void {
		$this->assertSame(expected: $relative, actual: StandardProtocolPaths::appRelative(path: $path));
	}//end testAppRelative()

	/**
	 * Paths and whether they speak a standard protocol.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'health'                => ['/api/health', true],
			'metrics'               => ['/api/metrics', true],
			'health request path'   => ['/index.php/apps/buildiq/api/health', true],
			'metrics request path'  => ['/apps/buildiq/api/metrics', true],
			'health subpath'        => ['/api/health/deep', false],
			'versioned health'      => ['/api/v1/health', false],
			'health of another app' => ['/apps/other/api/health', false],
			'resource'              => ['/api/things', false],
			'empty'                 => ['', false],
		];
	}//end protocolPaths()

	/**
	 * Only the health and metrics paths match.
	 *
	 * @param string $path    The path.
	 * @param bool   $matches Whether it should match.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testMatches(string $path, bool $matches): void {
		$this->assertSame(expected: $matches, actual: StandardProtocolPaths::matches(path: $path));
	}//end testMatches()
}//end class
