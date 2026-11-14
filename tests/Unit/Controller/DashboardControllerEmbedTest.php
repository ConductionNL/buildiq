<?php

/**
 * Unit tests for the embed answer of DashboardController::builder().
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
 *
 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-only-listed-sites-can-frame-the-app-req-bqem-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\DashboardController;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\EmbedOrigins;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only an embed request for an app with embedding on is framed, and only by
 * its listed sites.
 */
#[CoversClass(DashboardController::class)]
#[UsesClass(EmbedOrigins::class)]
final class DashboardControllerEmbedTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string,string>
	 */
	private array $params = [];

	/**
	 * The initial state the controller published.
	 *
	 * @var array<string,mixed>
	 */
	private array $published = [];

	/**
	 * The resolver double.
	 *
	 * @var ManifestResolverServiceInterface&MockObject
	 */
	private ManifestResolverServiceInterface&MockObject $resolver;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->params = [];
		$this->published = [];
		$this->resolver = $this->createMock(ManifestResolverServiceInterface::class);

	}//end setUp()

	/**
	 * Embedding on: no Nextcloud header, and the policy names exactly the
	 * app's listed sites as frame ancestors.
	 *
	 * @return void
	 */
	public function testAnEmbedRequestForAnAppWithEmbeddingOnIsFramedByItsSites(): void {
		$this->params = ['embed' => '1'];
		$this->resolver->expects($this->once())->method('findApplication')->with('room-bookings')->willReturn(
			['slug' => 'room-bookings', 'embed' => ['enabled' => true, 'origins' => ['https://intranet.example.nl', 'https://*.example.nl']]]
		);

		$response = $this->controller()->builder('room-bookings');

		$this->assertSame('builder', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_BASE, $response->getRenderAs());
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString("frame-ancestors 'self' https://intranet.example.nl;", $policy);
		$this->assertStringNotContainsString('*.example.nl', $policy);
		$this->assertTrue($this->published['builderEmbed']);
		$this->assertSame('room-bookings', $this->published['builderSlug']);

	}//end testAnEmbedRequestForAnAppWithEmbeddingOnIsFramedByItsSites()

	/**
	 * Embedding off: answered as without `embed=1`, framing refused.
	 *
	 * @return void
	 */
	public function testAnEmbedRequestForAnAppWithEmbeddingOffIsAnsweredAsToday(): void {
		$this->params = ['embed' => '1'];
		$this->resolver->method('findApplication')->willReturn(
			['slug' => 'room-bookings', 'embed' => ['enabled' => false, 'origins' => ['https://intranet.example.nl']]]
		);

		$this->assertAnsweredAsToday($this->controller()->builder('room-bookings'));

	}//end testAnEmbedRequestForAnAppWithEmbeddingOffIsAnsweredAsToday()

	/**
	 * An unknown app is answered as today.
	 *
	 * @return void
	 */
	public function testAnEmbedRequestForAnUnknownAppIsAnsweredAsToday(): void {
		$this->params = ['embed' => '1'];
		$this->resolver->method('findApplication')->willReturn(null);

		$this->assertAnsweredAsToday($this->controller()->builder('nope'));

	}//end testAnEmbedRequestForAnUnknownAppIsAnsweredAsToday()

	/**
	 * Without `embed=1` the app's setting is not even read.
	 *
	 * @return void
	 */
	public function testARequestWithoutEmbedDoesNotReadTheSetting(): void {
		$this->resolver->expects($this->never())->method('findApplication');

		$this->assertAnsweredAsToday($this->controller()->builder('room-bookings'));

	}//end testARequestWithoutEmbedDoesNotReadTheSetting()

	/**
	 * The user layout, the default policy (framed by itself only), and the
	 * runtime told it is not embedded.
	 *
	 * @param TemplateResponse $response The answer.
	 *
	 * @return void
	 */
	private function assertAnsweredAsToday(TemplateResponse $response): void {
		$this->assertSame('builder', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_USER, $response->getRenderAs());
		$this->assertStringContainsString("frame-ancestors 'self';", $response->getContentSecurityPolicy()->buildPolicy());
		$this->assertFalse($this->published['builderEmbed']);

	}//end assertAnsweredAsToday()

	/**
	 * The controller over the doubles.
	 *
	 * @return DashboardController
	 */
	private function controller(): DashboardController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $value): void {
				$this->published[$key] = $value;
			}
		);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->createMock(IUser::class));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);

		return new DashboardController(
			$request,
			$initialState,
			$userSession,
			$groupManager,
			$this->createMock(INavigationManager::class),
			$this->resolver,
			new EmbedOrigins()
		);

	}//end controller()
}//end class
