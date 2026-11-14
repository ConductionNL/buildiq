<?php

/**
 * Unit tests for the app template's OpenRegister autoloader prelude.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file.
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

use OCA\AppTemplate\AppInfo\OpenRegisterAutoloader;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the template's OpenRegisterAutoloader.
 */
#[CoversClass(OpenRegisterAutoloader::class)]
final class AppTemplateOpenRegisterAutoloaderTest extends TestCase {
	/**
	 * Temporary fake openregister app directory.
	 *
	 * @var string
	 */
	private string $appPath;

	/**
	 * Unique class name of the probe class in the fake app.
	 *
	 * @var string
	 */
	private string $probeClass;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Build a fake openregister app with one probe class under lib/.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		OpenRegisterAutoloader::unregister();
		$suffix = bin2hex(random_bytes(4));
		$this->appPath = sys_get_temp_dir() . '/apptemplate-or-' . $suffix;
		mkdir($this->appPath . '/lib/TemplateProbe', 0777, true);
		file_put_contents(
			$this->appPath . '/lib/TemplateProbe/Probe' . $suffix . '.php',
			"<?php\nnamespace OCA\\OpenRegister\\TemplateProbe;\nfinal class Probe" . $suffix . " {}\n"
		);
		$this->probeClass = 'OCA\\OpenRegister\\TemplateProbe\\Probe' . $suffix;
		$this->appManager = $this->createMock(IAppManager::class);
	}//end setUp()

	/**
	 * Remove the loader and the fake app.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		OpenRegisterAutoloader::unregister();
		$files = glob($this->appPath . '/lib/TemplateProbe/*');
		if ($files !== false) {
			foreach ($files as $file) {
				unlink($file);
			}
		}
		@rmdir($this->appPath . '/lib/TemplateProbe');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
		parent::tearDown();
	}//end tearDown()

	/**
	 * A class in the OpenRegister namespace maps onto the app's lib/ tree.
	 *
	 * @return void
	 */
	public function testClassFileMapsNamespaceOntoLib(): void {
		$this->assertSame(
			'/apps/openregister/lib/Db/ObjectEntity.php',
			OpenRegisterAutoloader::classFile(appPath: '/apps/openregister', class: 'OCA\\OpenRegister\\Db\\ObjectEntity')
		);
	}//end testClassFileMapsNamespaceOntoLib()

	/**
	 * A class outside the OpenRegister namespace is not ours to load.
	 *
	 * @return void
	 */
	public function testClassFileIgnoresForeignNamespace(): void {
		$this->assertNull(OpenRegisterAutoloader::classFile(appPath: '/apps/openregister', class: 'OCA\\Other\\Db\\Thing'));
		$this->assertNull(OpenRegisterAutoloader::classFile(appPath: '/apps/openregister', class: 'OCA\\OpenRegisterX\\Thing'));
	}//end testClassFileIgnoresForeignNamespace()

	/**
	 * The bare namespace prefix names no class.
	 *
	 * @return void
	 */
	public function testClassFileRejectsBarePrefix(): void {
		$this->assertNull(OpenRegisterAutoloader::classFile(appPath: '/apps/openregister', class: 'OCA\\OpenRegister\\'));
	}//end testClassFileRejectsBarePrefix()

	/**
	 * A disabled OpenRegister is not wired and its path is never asked for.
	 *
	 * @return void
	 */
	public function testRegisterReturnsFalseWhenOpenRegisterIsDisabled(): void {
		$this->appManager->expects($this->once())
			->method('isEnabledForAnyone')
			->with('openregister')
			->willReturn(false);
		$this->appManager->expects($this->never())->method('getAppPath');
		$before = count(spl_autoload_functions());

		$this->assertFalse(OpenRegisterAutoloader::register($this->appManager));
		$this->assertCount($before, spl_autoload_functions());
	}//end testRegisterReturnsFalseWhenOpenRegisterIsDisabled()

	/**
	 * An app path without a lib/ directory is refused.
	 *
	 * @return void
	 */
	public function testRegisterReturnsFalseWithoutLibDirectory(): void {
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$this->appManager->expects($this->once())
			->method('getAppPath')
			->with('openregister')
			->willReturn($this->appPath . '/lib/TemplateProbe');
		$before = count(spl_autoload_functions());

		$this->assertFalse(OpenRegisterAutoloader::register($this->appManager));
		$this->assertCount($before, spl_autoload_functions());
	}//end testRegisterReturnsFalseWithoutLibDirectory()

	/**
	 * An enabled OpenRegister gets one loader that resolves its classes.
	 *
	 * @return void
	 */
	public function testRegisterWiresLoaderThatResolvesOpenRegisterClasses(): void {
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$this->appManager->expects($this->once())
			->method('getAppPath')
			->willReturn($this->appPath . '/');
		$before = count(spl_autoload_functions());

		$this->assertTrue(OpenRegisterAutoloader::register($this->appManager));
		$this->assertCount($before + 1, spl_autoload_functions());
		$this->assertTrue(class_exists($this->probeClass));
		$this->assertFalse(class_exists('OCA\\OpenRegister\\TemplateProbe\\Missing' . bin2hex(random_bytes(4))));
	}//end testRegisterWiresLoaderThatResolvesOpenRegisterClasses()

	/**
	 * A second call short-circuits without stacking a loader or asking the path.
	 *
	 * @return void
	 */
	public function testSecondRegisterShortCircuits(): void {
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$this->appManager->expects($this->once())->method('getAppPath')->willReturn($this->appPath);

		$this->assertTrue(OpenRegisterAutoloader::register($this->appManager));
		$after = count(spl_autoload_functions());
		$this->assertTrue(OpenRegisterAutoloader::register($this->appManager));
		$this->assertCount($after, spl_autoload_functions());
	}//end testSecondRegisterShortCircuits()

	/**
	 * OpenRegister disabled after wiring takes the loader off the chain.
	 *
	 * @return void
	 */
	public function testRegisterUnwiresWhenOpenRegisterBecomesDisabled(): void {
		$this->appManager->method('isEnabledForAnyone')->willReturnOnConsecutiveCalls(true, false);
		$this->appManager->method('getAppPath')->willReturn($this->appPath);
		$before = count(spl_autoload_functions());

		$this->assertTrue(OpenRegisterAutoloader::register($this->appManager));
		$this->assertFalse(OpenRegisterAutoloader::register($this->appManager));
		$this->assertCount($before, spl_autoload_functions());
	}//end testRegisterUnwiresWhenOpenRegisterBecomesDisabled()

	/**
	 * A throwing app manager is swallowed, never rethrown.
	 *
	 * @return void
	 */
	public function testRegisterSwallowsExceptions(): void {
		$this->appManager->method('isEnabledForAnyone')->willReturn(true);
		$this->appManager->method('getAppPath')->willThrowException(new RuntimeException('not installed'));
		$before = count(spl_autoload_functions());

		$this->assertFalse(OpenRegisterAutoloader::register($this->appManager));
		$this->assertCount($before, spl_autoload_functions());
	}//end testRegisterSwallowsExceptions()

	/**
	 * Unregistering when nothing is wired is a no-op.
	 *
	 * @return void
	 */
	public function testUnregisterWithoutLoaderIsNoOp(): void {
		$before = count(spl_autoload_functions());
		OpenRegisterAutoloader::unregister();
		$this->assertCount($before, spl_autoload_functions());
	}//end testUnregisterWithoutLoaderIsNoOp()
}//end class
