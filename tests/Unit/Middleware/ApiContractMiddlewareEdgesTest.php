<?php

/**
 * Edge-case unit tests for the API contract middleware.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Middleware;

use OCA\Buildiq\Middleware\ApiContractMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the successor link without a front controller, the untouched success
 * bodies of the versioned API, which member becomes `detail`, which body
 * members count as a conflict, and the title of each error status.
 *
 * @covers \OCA\Buildiq\Middleware\ApiContractMiddleware
 * @uses   \OCA\Buildiq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Buildiq\Middleware\Support\StandardProtocolPaths
 */
class ApiContractMiddlewareEdgesTest extends TestCase {

	/**
	 * A versioned API path.
	 *
	 * @var string
	 */
	private const VERSIONED = '/apps/buildiq/api/v1/things/7';

	/**
	 * Run the middleware for a request on the given path and request URI.
	 *
	 * @param string   $path     The request path info.
	 * @param Response $response The controller response.
	 * @param string   $uri      The request URI.
	 *
	 * @return Response The decorated response.
	 */
	private function decorate(string $path, Response $response, string $uri = ''): Response {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn($uri === '' ? '/index.php'.$path : $uri);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppVersion')->with('buildiq')->willReturn('2.0.0');

		return (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $response);
	}//end decorate()

	/**
	 * The headers a response set, read without the server container.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		return (new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * A request URI without a front controller, or one that does not hold the path, links the bare successor.
	 *
	 * @return void
	 */
	public function testTheSuccessorLinkWithoutAFrontController(): void {
		$path = '/apps/buildiq/api/things/7/parts';

		$bare = $this->decorate(path: $path, response: new JSONResponse(data: []), uri: $path);
		$this->assertSame(
			expected: '</apps/buildiq/api/v1/things/7/parts>; rel="successor-version"',
			actual: $this->headersOf(response: $bare)['Link']
		);

		$elsewhere = $this->decorate(path: $path, response: new JSONResponse(data: []), uri: '/rewritten/target');
		$this->assertSame(
			expected: '</apps/buildiq/api/v1/things/7/parts>; rel="successor-version"',
			actual: $this->headersOf(response: $elsewhere)['Link']
		);
	}//end testTheSuccessorLinkWithoutAFrontController()

	/**
	 * A versioned response below 400 keeps its body and JSON content type, even when it names an error.
	 *
	 * @return void
	 */
	public function testAVersionedSuccessOrRedirectIsNotAProblem(): void {
		foreach ([200, 302, 399] as $status) {
			$response = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: ['error' => 'none'], statusCode: $status));

			$this->assertSame(expected: ['error' => 'none'], actual: $response->getData(), message: (string) $status);
			$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $response)['Content-Type']);
			$this->assertSame(expected: '2.0.0', actual: $this->headersOf(response: $response)['API-Version']);
		}
	}//end testAVersionedSuccessOrRedirectIsNotAProblem()

	/**
	 * `detail` comes from `detail`, then `message`, then `error`; empty or non-text values are skipped.
	 *
	 * @return void
	 */
	public function testWhichMemberBecomesTheDetail(): void {
		$cases = [
			[['error' => 'E', 'message' => 'M', 'detail' => 'D'], 'D'],
			[['error' => 'E', 'message' => 'M'], 'M'],
			[['error' => 'E', 'message' => ''], 'E'],
			[['error' => 'E', 'message' => ['nested']], 'E'],
			[['error' => 42], null],
			[['code' => 'x'], null],
		];
		foreach ($cases as [$body, $detail]) {
			$data = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: $body, statusCode: 422))->getData();

			$this->assertSame(expected: 'Unprocessable Content', actual: $data['title']);
			if ($detail === null) {
				$this->assertArrayNotHasKey(key: 'detail', array: $data, message: json_encode($body));
			} else {
				$this->assertSame(expected: $detail, actual: $data['detail'], message: json_encode($body));
			}
		}
	}//end testWhichMemberBecomesTheDetail()

	/**
	 * A body whose problem members already agree is merged, problem members first; one that disagrees on any is left alone.
	 *
	 * @return void
	 */
	public function testAgreeingMembersMergeAndDisagreeingOnesAreKept(): void {
		$agreeing = $this->decorate(
			path: self::VERSIONED,
			response: new JSONResponse(data: ['reason' => 'gone', 'status' => 404, 'type' => 'about:blank'], statusCode: 404)
		);
		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'reason' => 'gone'],
			actual: $agreeing->getData()
		);
		$this->assertSame(expected: ApiContractMiddleware::PROBLEM_CONTENT_TYPE, actual: $this->headersOf(response: $agreeing)['Content-Type']);

		foreach ([['status' => '404'], ['title' => 'Missing'], ['type' => 'https://example.org/gone']] as $body) {
			$kept = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: $body, statusCode: 404));

			$this->assertSame(expected: $body, actual: $kept->getData());
			$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $kept)['Content-Type']);
		}
	}//end testAgreeingMembersMergeAndDisagreeingOnesAreKept()

	/**
	 * Each error status gets its own reason phrase as title.
	 *
	 * @return void
	 */
	public function testEachStatusHasItsTitle(): void {
		$titles = [
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			409 => 'Conflict',
			413 => 'Content Too Large',
			423 => 'Locked',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
			418 => 'Error',
		];
		foreach ($titles as $status => $title) {
			$data = $this->decorate(path: self::VERSIONED, response: new JSONResponse(data: [], statusCode: $status))->getData();

			$this->assertSame(expected: ['type' => 'about:blank', 'title' => $title, 'status' => $status], actual: $data);
		}
	}//end testEachStatusHasItsTitle()
}//end class
