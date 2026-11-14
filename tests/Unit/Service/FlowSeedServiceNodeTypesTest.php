<?php

/**
 * Unit tests for how the app template's FlowSeedService reports node types
 * the engine does not know: known and type-less nodes are not reported, and an
 * unknown type is reported once across every shipped flow, without stopping
 * the flow from being seeded.
 *
 * The template is not on buildiq's autoloader (it is copied into a new app
 * under that app's namespace), so the subject is loaded from its file.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
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

namespace OCA\Buildiq\Tests\Unit\Service;

require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/FlowSeedService.php';

use OCA\AppTemplate\Service\FlowSeedService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unknown node type reporting of FlowSeedService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(FlowSeedService::class)]
final class FlowSeedServiceNodeTypesTest extends TestCase {

	/**
	 * The shipped flow directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * A fresh flow directory per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/buildiq-flow-types-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
	}//end setUp()

	/**
	 * Remove the flow directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$files = glob($this->dir . '/*');
		if ($files === false) {
			$files = [];
		}

		foreach ($files as $file) {
			unlink($file);
		}

		rmdir($this->dir);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Known, empty and missing types are not reported; an unknown type is reported once across flows, which still seed.
	 *
	 * @return void
	 */
	public function testOnlyUnknownTypesAreReportedOnce(): void {
		$this->ship(
			'a.json',
			['uuid' => 'u-a', 'nodes' => [['type' => 'start'], ['type' => ''], ['id' => 'untyped'], ['type' => 'ocr'], ['type' => 'ocr']]]
		);
		$this->ship('b.json', ['uuid' => 'u-b', 'nodes' => [['type' => 'sign'], ['type' => 'ocr'], ['type' => 'end']]]);
		$this->ship('c.json', ['uuid' => 'u-c', 'nodes' => [['type' => 'start'], ['type' => 'end']]]);

		$service = new FlowSeedService(
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			flowDir: $this->dir
		);
		$report = $service->seed(flowMapper: $this->mapper(), registeredNodeTypes: ['start' => true, 'end' => true]);

		$this->assertSame(['ocr', 'sign'], $report['unknownNodeTypes']);
		$this->assertSame(3, $report['seeded']);
		$this->assertSame([], $report['failed']);
	}//end testOnlyUnknownTypesAreReportedOnce()

	/**
	 * Write a shipped flow file.
	 *
	 * @param string               $name    The file name.
	 * @param array<string, mixed> $content The flow definition.
	 *
	 * @return void
	 */
	private function ship(string $name, array $content): void {
		file_put_contents($this->dir . '/' . $name, (string)json_encode($content));
	}//end ship()

	/**
	 * A flow mapper that knows no flow and accepts every insert.
	 *
	 * @return object The mapper.
	 */
	private function mapper(): object {
		return new class {
			/**
			 * No flow exists yet.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object|null
			 */
			public function findByUuid(string $uuid): ?object {
				return null;
			}

			/**
			 * Accept the flow.
			 *
			 * @param object $flow The flow.
			 *
			 * @return object
			 */
			public function insert(object $flow): object {
				return $flow;
			}
		};
	}//end mapper()
}//end class
