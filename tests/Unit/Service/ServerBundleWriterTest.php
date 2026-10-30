<?php

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ServerBundleWriter;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ServerBundleWriter::requirements()}.
 *
 * The deploy/ tree it writes is asserted through the export in ExportServiceTest.
 */
final class ServerBundleWriterTest extends TestCase {
	/**
	 * REQ-BQSD-001, REQ-BQSD-004: the requirements come from the app template's
	 * info.xml and the installed OpenRegister, not from fixed numbers.
	 *
	 * @return void
	 */
	public function testRequirementsReadTheTemplateAndTheInstalledOpenRegister(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('openregister')->willReturn('1.14.2');

		$xml = simplexml_load_file(dirname(__DIR__, 3) . '/lib/Resources/template/appinfo/info.xml');
		self::assertNotFalse($xml);

		$requirements = (new ServerBundleWriter($appManager))->requirements();

		self::assertSame((string)$xml->dependencies->nextcloud['min-version'], $requirements['nextcloud']);
		self::assertSame((string)$xml->dependencies->php['min-version'], $requirements['php']);
		self::assertNotSame('', $requirements['nextcloud']);
		self::assertSame('1.14.2', $requirements['openregister']);
		self::assertSame('PostgreSQL 16', $requirements['database']);
	}//end testRequirementsReadTheTemplateAndTheInstalledOpenRegister()
}//end class
