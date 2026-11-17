<?php

/**
 * Edge-case unit tests for the `/api/v1/` aliases of the API routes.
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

use OCA\Buildiq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the alias de-duplication by verb and path, the insert position after
 * the last declared versioned route, declarations without routes, the
 * fallback detection and the version pattern.
 *
 * @covers \OCA\Buildiq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Buildiq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesEdgesTest extends TestCase {

	/**
	 * A route whose `/api/v1/` form is already declared for the same verb gets
	 * no alias; the same path under another verb still does.
	 *
	 * @return void
	 */
	public function testAnAlreadyDeclaredVersionedFormIsNotDuplicated(): void {
		$routes = [
			'routes' => [
				['name' => 'things#indexV1', 'url' => '/api/v1/things', 'verb' => 'GET'],
				['name' => 'things#index', 'url' => '/api/things', 'verb' => 'GET'],
				['name' => 'things#create', 'url' => '/api/things', 'verb' => 'POST'],
			],
		];

		$declared = ApiPathAliases::withVersionedAliases(routes: $routes);

		$this->assertSame(
			expected: [
				$routes['routes'][0],
				['name' => 'things#create', 'url' => '/api/v1/things', 'verb' => 'POST', 'postfix' => 'v1'],
				$routes['routes'][1],
				$routes['routes'][2],
			],
			actual: $declared['routes']
		);
	}//end testAnAlreadyDeclaredVersionedFormIsNotDuplicated()

	/**
	 * A route without a verb counts as GET, case-insensitively, when matching
	 * declared routes, so its versioned twin is not added twice.
	 *
	 * @return void
	 */
	public function testAMissingVerbCountsAsGet(): void {
		$routes = [
			'routes' => [
				['name' => 'things#indexV1', 'url' => 'api/v1/things'],
				['name' => 'things#index', 'url' => '/api/things', 'verb' => 'get'],
			],
		];

		$this->assertSame(expected: $routes, actual: ApiPathAliases::withVersionedAliases(routes: $routes));
	}//end testAMissingVerbCountsAsGet()

	/**
	 * The aliases go after the last declared versioned route, even when other
	 * routes sit between the versioned ones.
	 *
	 * @return void
	 */
	public function testAliasesGoAfterTheLastVersionedRoute(): void {
		$routes = [
			'routes' => [
				['name' => 'a#one', 'url' => '/api/v1/a', 'verb' => 'GET'],
				['name' => 'b#list', 'url' => '/api/b', 'verb' => 'GET'],
				['name' => 'c#one', 'url' => '/api/v1/c', 'verb' => 'GET'],
				['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
			],
		];

		$this->assertSame(
			expected: ['/api/v1/a', '/api/b', '/api/v1/c', '/api/v1/b', '/'],
			actual: array_column(ApiPathAliases::withVersionedAliases(routes: $routes)['routes'], 'url')
		);
	}//end testAliasesGoAfterTheLastVersionedRoute()

	/**
	 * A declaration without routes keeps its other sections and gains an empty route list.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutRoutesKeepsItsOtherSections(): void {
		$ocs = [['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET']];

		$this->assertSame(
			expected: ['ocs' => $ocs, 'routes' => []],
			actual: ApiPathAliases::withVersionedAliases(routes: ['ocs' => $ocs])
		);
	}//end testADeclarationWithoutRoutesKeepsItsOtherSections()

	/**
	 * Standard protocol routes and routes without a url get no alias.
	 *
	 * @return void
	 */
	public function testProtocolAndUrlLessRoutesGetNoAlias(): void {
		$routes = [
			'routes' => [
				['name' => 'health#check', 'url' => '/api/health', 'verb' => 'GET'],
				['name' => 'metrics#show', 'url' => 'api/metrics', 'verb' => 'GET'],
				['name' => 'page#index', 'verb' => 'GET'],
			],
		];

		$this->assertSame(expected: $routes, actual: ApiPathAliases::withVersionedAliases(routes: $routes));
	}//end testProtocolAndUrlLessRoutesGetNoAlias()

	/**
	 * Routes and whether they are the API fallback.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function fallbacks(): array {
		return [
			'spanning requirement'     => [['url' => 'api/{path}', 'requirements' => ['path' => '.*']], true],
			'segment requirement'      => [['url' => '/api/{path}', 'requirements' => ['path' => '[a-z]+']], false],
			'other placeholder named'  => [['url' => '/api/{path}', 'requirements' => ['other' => '.+']], false],
			'two placeholders'         => [['url' => '/api/{a}/{b}', 'requirements' => ['a' => '.+', 'b' => '.+']], false],
			'versioned fallback shape' => [['url' => '/api/v1/{path}', 'requirements' => ['path' => '.+']], false],
			'no url'                   => [['requirements' => ['path' => '.+']], false],
		];
	}//end fallbacks()

	/**
	 * Only a single segment-spanning placeholder right after `/api/` is the fallback.
	 *
	 * @param array<string,mixed> $route    The route.
	 * @param bool                $fallback Whether it is the fallback.
	 *
	 * @return void
	 */
	#[DataProvider('fallbacks')]
	public function testIsApiFallback(array $route, bool $fallback): void {
		$this->assertSame(expected: $fallback, actual: ApiPathAliases::isApiFallback(route: $route));
	}//end testIsApiFallback()

	/**
	 * Paths and whether they carry a major version.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function versions(): array {
		return [
			'upper case'           => ['/api/V2/things', true],
			'three digits'         => ['/api/v123', true],
			'four digits'          => ['/api/v1234/things', false],
			'nested family'        => ['/apps/buildiq/api/zgw/zaken/v1', true],
			'no leading slash'     => ['api/v1/things', true],
			'version not under api' => ['/v1/things', false],
			'version as later part' => ['/api/things/v1', true],
		];
	}//end versions()

	/**
	 * The version may follow `/api/` or any lowercase family segment, and has at most three digits.
	 *
	 * @param string $path      The path.
	 * @param bool   $versioned Whether it is versioned.
	 *
	 * @return void
	 */
	#[DataProvider('versions')]
	public function testIsVersioned(string $path, bool $versioned): void {
		$this->assertSame(expected: $versioned, actual: ApiPathAliases::isVersioned(path: $path));
	}//end testIsVersioned()

	/**
	 * A versioned path is not deprecated and the bare `/api` root and other
	 * apps' API paths are not either; the successor keeps the request prefix.
	 *
	 * @return void
	 */
	public function testDeprecationAndSuccessorEdges(): void {
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/api'));
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/apps/other/api/things'));
		$this->assertFalse(condition: ApiPathAliases::isDeprecated(path: '/apps/buildiq/api/metrics'));
		$this->assertNull(actual: ApiPathAliases::successorOf(path: '/apps/buildiq/api/V1/things'));
		$this->assertSame(
			expected: 'https://host/index.php/apps/buildiq/api/v1/things/{id}',
			actual: ApiPathAliases::successorOf(path: 'https://host/index.php/apps/buildiq/api/things/{id}')
		);
	}//end testDeprecationAndSuccessorEdges()
}//end class
