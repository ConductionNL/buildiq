<?php

/**
 * Unit tests for IconController::touchIcon — the installed app's PNG touch
 * icon (pages-installable-offline-and-mobile T02).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
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

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\IconController;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\Icon\SvgRasteriser;
use OCA\Buildiq\Service\IconService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see IconController::touchIcon()}.
 *
 * @covers \OCA\Buildiq\Controller\IconController
 */
class IconControllerTouchIconTest extends TestCase {

	/**
	 * Mock icon service.
	 *
	 * @var IconService&MockObject
	 */
	private IconService&MockObject $iconService;

	/**
	 * Mock rasteriser.
	 *
	 * @var SvgRasteriser&MockObject
	 */
	private SvgRasteriser&MockObject $rasteriser;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->iconService = $this->createMock(IconService::class);
		$this->rasteriser  = $this->createMock(SvgRasteriser::class);
	}//end setUp()

	/**
	 * The controller under test, for a signed-in user unless told otherwise.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return IconController
	 */
	private function controller(bool $signedIn=true): IconController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new IconController(
			$this->createMock(IRequest::class),
			$this->iconService,
			$this->caller(session: $session),
			$this->createMock(LoggerInterface::class),
			$this->rasteriser
		);
	}//end controller()

	/**
	 * A stream holding the given bytes.
	 *
	 * @param string $bytes The content.
	 *
	 * @return resource
	 */
	private static function streamOf(string $bytes) {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $bytes);
		rewind($stream);

		return $stream;
	}//end streamOf()

	/**
	 * An SVG icon is rasterised at 180 pixels and served as PNG.
	 *
	 * @return void
	 */
	public function testAnSvgIconIsServedAsAPng(): void {
		$this->iconService->expects(self::once())->method('getIconStream')
			->with('hello-world', false)
			->willReturn(['stream' => self::streamOf('<svg/>'), 'mimeType' => 'image/svg+xml']);
		$this->rasteriser->expects(self::once())->method('toPng')->with('<svg/>')->willReturn('PNGBYTES');

		$response = $this->controller()->touchIcon('hello-world');

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('PNGBYTES', $response->getData());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('image/png', $headers['Content-Type']);
	}//end testAnSvgIconIsServedAsAPng()

	/**
	 * A PNG icon is served as it is, without rasterising.
	 *
	 * @return void
	 */
	public function testAPngIconIsServedAsItIs(): void {
		$this->iconService->method('getIconStream')->willReturn(['stream' => self::streamOf('RAWPNG'), 'mimeType' => 'image/png']);
		$this->rasteriser->expects(self::never())->method('toPng');

		self::assertSame('RAWPNG', $this->controller()->touchIcon('hello-world')->getData());
	}//end testAPngIconIsServedAsItIs()

	/**
	 * No rasteriser output, no icon or a failing lookup are 404, so the SVG stays the icon.
	 *
	 * @return void
	 */
	public function testNoPngIs404(): void {
		$this->iconService->method('getIconStream')->willReturnOnConsecutiveCalls(
			['stream' => self::streamOf('<svg/>'), 'mimeType' => 'image/svg+xml'],
			['stream' => null, 'mimeType' => 'image/svg+xml'],
			$this->throwException(new \RuntimeException('down'))
		);
		$this->rasteriser->method('toPng')->willReturn(null);
		$controller = $this->controller();

		self::assertSame(
			[Http::STATUS_NOT_FOUND, Http::STATUS_NOT_FOUND, Http::STATUS_NOT_FOUND],
			[
				$controller->touchIcon('hello-world')->getStatus(),
				$controller->touchIcon('hello-world')->getStatus(),
				$controller->touchIcon('hello-world')->getStatus(),
			]
		);
	}//end testNoPngIs404()

	/**
	 * An anonymous caller gets 401 and the icon is never read.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerGets401(): void {
		$this->iconService->expects(self::never())->method('getIconStream');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->touchIcon('hello-world')->getStatus());
	}//end testAnAnonymousCallerGets401()

	/**
	 * The caller context over a session, without an organisation guard.
	 *
	 * @param IUserSession $session The session.
	 *
	 * @return CallerContext
	 */
	private function caller(IUserSession $session): CallerContext {
		return new CallerContext(
			userSession: $session,
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			permissionResolver: $this->createMock(originalClassName: PermissionResolver::class)
		);
	}//end caller()
}//end class
