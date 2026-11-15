<?php

/**
 * Unit tests for the app template's DashboardController.
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
require_once __DIR__ . '/../../../lib/Resources/template/lib/Controller/DashboardController.php';

use OCA\AppTemplate\Controller\DashboardController;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the template's DashboardController.
 */
#[CoversClass(DashboardController::class)]
final class AppTemplateDashboardControllerTest extends TestCase {
	/**
	 * Subject.
	 *
	 * @var DashboardController
	 */
	private DashboardController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->controller = new DashboardController($this->createMock(IRequest::class));
	}//end setUp()

	/**
	 * The page renders the template's index under its app id.
	 *
	 * @return void
	 */
	public function testPageRendersIndexTemplate(): void {
		$response = $this->controller->page();

		$this->assertSame('app-template', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
		$this->assertSame([], $response->getParams());
	}//end testPageRendersIndexTemplate()

	/**
	 * Every SPA sub-route renders the same index page.
	 *
	 * @return void
	 */
	public function testCatchAllRendersThePage(): void {
		$response = $this->controller->catchAll();

		$this->assertSame('app-template', $response->getApp());
		$this->assertSame('index', $response->getTemplateName());
	}//end testCatchAllRendersThePage()
}//end class
