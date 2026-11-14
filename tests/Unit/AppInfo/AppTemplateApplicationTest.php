<?php

/**
 * Unit tests for the app template's Application bootstrap.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file. Its constructor needs the server
 * container, so the instance is made without it.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\AppInfo
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

namespace OCA\Buildiq\Tests\Unit\AppInfo;

require_once __DIR__ . '/../../../lib/Resources/template/lib/AppInfo/OpenRegisterAutoloader.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/AppInfo/Application.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Listener/DeepLinkRegistrationListener.php';

use OCA\AppTemplate\AppInfo\Application;
use OCA\AppTemplate\AppInfo\OpenRegisterAutoloader;
use OCA\AppTemplate\Listener\DeepLinkRegistrationListener;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Registration wires exactly the deep-link listener; boot does nothing.
 */
#[CoversClass(Application::class)]
#[UsesClass(OpenRegisterAutoloader::class)]
final class AppTemplateApplicationTest extends TestCase {
	/**
	 * The application, made without the container-bound constructor.
	 *
	 * @return Application
	 */
	private function application(): Application {
		return (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
	}//end application()

	/**
	 * Register adds the deep-link listener for OpenRegister's event, and nothing else.
	 *
	 * @return void
	 */
	public function testRegisterWiresTheDeepLinkListener(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerEventListener')
			->with(DeepLinkRegistrationEvent::class, DeepLinkRegistrationListener::class);
		foreach (['registerService', 'registerMiddleware', 'registerCapability', 'registerSearchProvider', 'registerDashboardWidget'] as $other) {
			$context->expects($this->never())->method($other);
		}

		$this->application()->register($context);
	}//end testRegisterWiresTheDeepLinkListener()

	/**
	 * Boot leaves the boot context alone.
	 *
	 * @return void
	 */
	public function testBootTouchesNothing(): void {
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->never())->method($this->anything());

		$this->application()->boot($context);
	}//end testBootTouchesNothing()
}//end class
