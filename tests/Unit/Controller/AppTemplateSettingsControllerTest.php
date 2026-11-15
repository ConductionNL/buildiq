<?php

/**
 * Unit tests for the app template's SettingsController.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

require_once __DIR__ . '/../../../lib/Resources/template/lib/AppInfo/Application.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/SettingsService.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Controller/SettingsController.php';

use OCA\AppTemplate\Controller\SettingsController;
use OCA\AppTemplate\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the template's SettingsController.
 */
#[CoversClass(SettingsController::class)]
final class AppTemplateSettingsControllerTest extends TestCase {
	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Subject.
	 *
	 * @var SettingsController
	 */
	private SettingsController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->controller = new SettingsController(request: $this->request, settingsService: $this->settingsService);
	}//end setUp()

	/**
	 * Index returns the service's settings verbatim.
	 *
	 * @return void
	 */
	public function testIndexReturnsSettings(): void {
		$settings = ['register' => 'app-template', 'isAdmin' => true];
		$this->settingsService->expects($this->once())->method('getSettings')->willReturn($settings);

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($settings, $response->getData());
	}//end testIndexReturnsSettings()

	/**
	 * Create passes the request params to the service and wraps the result.
	 *
	 * @return void
	 */
	public function testCreateUpdatesSettingsFromRequestParams(): void {
		$params = ['register' => 'r1', 'schema' => 's1'];
		$this->request->expects($this->once())->method('getParams')->willReturn($params);
		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($params)
			->willReturn(['register' => 'r1']);

		$response = $this->controller->create();

		$this->assertSame(['success' => true, 'config' => ['register' => 'r1']], $response->getData());
	}//end testCreateUpdatesSettingsFromRequestParams()

	/**
	 * Load forces a configuration import and returns its result.
	 *
	 * @return void
	 */
	public function testLoadForcesConfigurationImport(): void {
		$result = ['success' => false, 'message' => 'OpenRegister missing'];
		$this->settingsService->expects($this->once())
			->method('loadConfiguration')
			->with(true)
			->willReturn($result);

		$this->assertSame($result, $this->controller->load()->getData());
	}//end testLoadForcesConfigurationImport()
}//end class
