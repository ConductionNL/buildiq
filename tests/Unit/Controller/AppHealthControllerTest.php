<?php

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AppHealthController;
use OCA\Buildiq\Service\AppHealthService;
use OCA\Buildiq\Service\ApplicationInsightsService;
use OCA\Buildiq\Service\ManifestResolverService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BQDM-005, REQ-BQDM-006: samples only from someone who may open the version, health only for insight readers.
 */
final class AppHealthControllerTest extends TestCase {
	public function testACallerWhoMayNotOpenTheVersionStoresNothing(): void {
		$resolver = $this->createMock(ManifestResolverService::class);
		$resolver->method('resolveVersionForCaller')->willReturn(null);
		$health = $this->createMock(AppHealthService::class);
		$health->expects($this->never())->method('record');

		$response = $this->controller(params: ['_version' => 'development', 'pages' => []], resolver: $resolver, health: $health)->record('vergunningen');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testACallerWhoMayNotOpenTheVersionStoresNothing()

	public function testSamplesAreStoredUnderTheServersIds(): void {
		$resolver = $this->createMock(ManifestResolverService::class);
		$resolver->expects($this->once())->method('resolveVersionForCaller')->with('vergunningen', null)->willReturn(['applicationUuid' => 'app-1', 'versionUuid' => 'ver-1']);
		$health = $this->createMock(AppHealthService::class);
		$health->expects($this->once())->method('record')->with('app-1', 'ver-1', [['pageId' => 'permits']])->willReturn(1);

		$response = $this->controller(params: ['pages' => [['pageId' => 'permits']]], resolver: $resolver, health: $health)->record('vergunningen');

		self::assertSame(['stored' => 1], $response->getData());
	}//end testSamplesAreStoredUnderTheServersIds()

	public function testHealthNeedsTheInsightsRightAndAKnownWindow(): void {
		$insights = $this->createMock(ApplicationInsightsService::class);
		$insights->method('requireAuthorisedCaller')->willReturn(null);
		$health = $this->createMock(AppHealthService::class);
		$health->expects($this->never())->method('computeHealth');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller(params: ['window' => '7d'], insights: $insights, health: $health)->show('app-1', 'ver-1')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(params: ['window' => '1y'], insights: $insights, health: $health)->show('app-1', 'ver-1')->getStatus());
	}//end testHealthNeedsTheInsightsRightAndAKnownWindow()

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function controller(array $params, ?ManifestResolverService $resolver = null, ?ApplicationInsightsService $insights = null, ?AppHealthService $health = null): AppHealthController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new AppHealthController(
			$request,
			$session,
			$resolver ?? $this->createMock(ManifestResolverService::class),
			$insights ?? $this->createMock(ApplicationInsightsService::class),
			$health ?? $this->createMock(AppHealthService::class)
		);
	}//end controller()
}//end class
