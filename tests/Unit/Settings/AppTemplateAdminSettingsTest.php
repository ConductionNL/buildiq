<?php

/**
 * Unit tests for the app template's AdminSettings.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
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

namespace OCA\Buildiq\Tests\Unit\Settings;

require_once __DIR__ . '/../../../lib/Resources/template/lib/AppInfo/Application.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Settings/AdminSettings.php';

use OCA\AppTemplate\Settings\AdminSettings;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the template's AdminSettings.
 */
#[CoversClass(AdminSettings::class)]
final class AppTemplateAdminSettingsTest extends TestCase {
	/**
	 * The form renders the admin template with the installed version.
	 *
	 * @return void
	 */
	public function testGetFormPassesInstalledVersion(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('getAppVersion')->with('app-template')->willReturn('0.3.1');
		$settings = new AdminSettings($appManager);

		$response = $settings->getForm();

		$this->assertSame('app-template', $response->getApp());
		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertSame(['version' => '0.3.1'], $response->getParams());
	}//end testGetFormPassesInstalledVersion()

	/**
	 * The form lives in the app's own section at priority 10.
	 *
	 * @return void
	 */
	public function testSectionAndPriority(): void {
		$settings = new AdminSettings($this->createMock(IAppManager::class));

		$this->assertSame('app-template', $settings->getSection());
		$this->assertSame(10, $settings->getPriority());
	}//end testSectionAndPriority()
}//end class
