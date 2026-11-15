<?php

/**
 * Characterisation tests for SettingsService's register configuration import.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins what loadConfiguration() / reloadConfiguration() hand to
 * OpenRegister's importFromApp (the base register merged with every
 * fragment, the fragment-signed version, the force flag), the three import
 * outcomes with their log lines, and the fragment merge rules.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 * @uses \OCA\Buildiq\Service\CallerContext
 * @uses \OCA\Buildiq\Service\RegisterConfigImport
 */
final class SettingsServiceConfigurationImportTest extends TestCase {

	private const SETTINGS_DIR = __DIR__ . '/../../../lib/Settings';

	/**
	 * Log lines written by the service.
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * Arguments importFromApp received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $imports = [];

	/**
	 * Build the service with OpenRegister present and a stub ConfigurationService.
	 *
	 * @param mixed $importResult What importFromApp returns, or a Throwable it throws.
	 *
	 * @return SettingsService
	 */
	private function sut(mixed $importResult): SettingsService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$imports = &$this->imports;
		$configService = new class($imports, $importResult) {
			/**
			 * @param array<int,array<string,mixed>> $imports Collects the arguments.
			 * @param mixed                          $result  The canned outcome.
			 */
			public function __construct(
				private array &$imports,
				private mixed $result,
			) {
			}//end __construct()

			/**
			 * @param string              $appId   App identifier.
			 * @param array<string,mixed> $data    Config data.
			 * @param string              $version Version string.
			 * @param bool                $force   Force flag.
			 *
			 * @return mixed
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): mixed {
				$this->imports[] = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}//end importFromApp()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ConfigurationService')->willReturn($configService);

		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * @param array<int,array{0:string,1:string,2:array<string,mixed>}> $logs Collects log lines.
			 */
			public function __construct(
				private array &$logs,
			) {
			}//end __construct()

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string,mixed>  $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [(string)$level, (string)$message, $context];
			}//end log()
		};

		return new SettingsService(
			appConfig: $this->createMock(IAppConfig::class),
			appManager: $appManager,
			container: $container,
			caller: new CallerContext(
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class),
				permissionResolver: $this->createMock(PermissionResolver::class)
			),
			logger: $logger,
		);
	}//end sut()

	/**
	 * The fragment files in load order.
	 *
	 * @return array<int,string>
	 */
	private function fragmentFiles(): array {
		$files = glob(self::SETTINGS_DIR . '/register.d/*.json');
		sort($files);
		return $files;
	}//end fragmentFiles()

	/**
	 * The version importFromApp must receive: the base version signed with
	 * every fragment's name and content hash.
	 *
	 * @return string
	 */
	private function expectedVersion(): string {
		$base = json_decode((string)file_get_contents(self::SETTINGS_DIR . '/openbuild_register.json'), true);
		$signature = '';
		foreach ($this->fragmentFiles() as $file) {
			$signature .= basename($file) . ':' . md5((string)file_get_contents($file)) . ';';
		}

		return ($base['info']['version'] ?? '0.0.0') . '+frag.' . substr(md5($signature), 0, 8);
	}//end expectedVersion()

	/**
	 * A successful import hands over the merged register and signed version.
	 *
	 * @return void
	 */
	public function testSuccessfulImportPassesMergedRegisterAndSignedVersion(): void {
		$result = $this->sut(importResult: ['imported' => 3])->loadConfiguration();

		$this->assertSame(
			['success' => true, 'message' => 'Configuration imported successfully.', 'version' => $this->expectedVersion()],
			$result
		);
		$this->assertCount(1, $this->imports);
		$this->assertSame('buildiq', $this->imports[0]['appId']);
		$this->assertFalse($this->imports[0]['force']);
		$this->assertSame($this->expectedVersion(), $this->imports[0]['version']);

		$data = $this->imports[0]['data'];
		foreach ($this->fragmentFiles() as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			foreach (array_keys($fragment['components']['schemas'] ?? []) as $schema) {
				$this->assertArrayHasKey($schema, $data['components']['schemas'], basename($file));
			}
		}

		$this->assertSame([['info', 'Buildiq: register configuration imported successfully', []]], $this->logs);
	}//end testSuccessfulImportPassesMergedRegisterAndSignedVersion()

	/**
	 * The last fragment's scalar wins and the base keys survive the merge.
	 *
	 * @return void
	 */
	public function testMergedRegisterKeepsBaseKeysAndLastScalar(): void {
		$this->sut(importResult: ['version' => 'x'])->reloadConfiguration();

		$base = json_decode((string)file_get_contents(self::SETTINGS_DIR . '/openbuild_register.json'), true);
		$files = $this->fragmentFiles();
		$last = json_decode((string)file_get_contents(end($files)), true);
		$data = $this->imports[0]['data'];

		$this->assertTrue($this->imports[0]['force']);
		$this->assertSame($last['_comment'], $data['_comment']);
		foreach (array_keys($base) as $key) {
			$this->assertArrayHasKey($key, $data);
		}

		$this->assertSame($base['info'], $data['info']);
	}//end testMergedRegisterKeepsBaseKeysAndLastScalar()

	/**
	 * A result carrying its own version reports that version.
	 *
	 * @return void
	 */
	public function testResultVersionWinsOverConfigVersion(): void {
		$result = $this->sut(importResult: ['version' => '9.9.9'])->reloadConfiguration();

		$this->assertSame(['success' => true, 'message' => 'Configuration imported successfully.', 'version' => '9.9.9'], $result);
	}//end testResultVersionWinsOverConfigVersion()

	/**
	 * An empty import result is a failure without a log line.
	 *
	 * @return void
	 */
	public function testEmptyImportResultIsAFailure(): void {
		$result = $this->sut(importResult: [])->loadConfiguration();

		$this->assertSame(['success' => false, 'message' => 'Import returned an empty result.'], $result);
		$this->assertSame([], $this->logs);
	}//end testEmptyImportResultIsAFailure()

	/**
	 * A throwing import is a failure carrying the exception message, logged as an error.
	 *
	 * @return void
	 */
	public function testThrowingImportIsALoggedFailure(): void {
		$result = $this->sut(importResult: new RuntimeException('boom'))->loadConfiguration();

		$this->assertSame(['success' => false, 'message' => 'boom'], $result);
		$this->assertSame([['error', 'Buildiq: configuration import failed', ['exception' => 'boom']]], $this->logs);
	}//end testThrowingImportIsALoggedFailure()

	/**
	 * Fragment merge rules: keyed objects union and recurse, two lists
	 * concatenate, a list meeting an object recurses by key, scalars and
	 * non-array sides overwrite, null base values are overwritten.
	 *
	 * @return void
	 */
	public function testDeepMergeRules(): void {
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');

		$base = [
			'paths' => ['/a' => ['get' => 1], '/b' => 2],
			'list' => [1, 2],
			'emptyList' => [],
			'mixed' => [1, 2],
			'scalar' => 'old',
			'toScalar' => ['x' => 1],
			'fromScalar' => 'old',
			'nullBase' => null,
			'sparse' => [1 => 'a'],
		];
		$overlay = [
			'paths' => ['/a' => ['post' => 3], '/c' => 4],
			'list' => [3],
			'emptyList' => ['k' => 'v'],
			'mixed' => ['k' => 'v'],
			'scalar' => 'new',
			'toScalar' => 'flat',
			'fromScalar' => ['y' => 2],
			'nullBase' => ['z' => 1],
			'sparse' => [0 => 'b'],
			'added' => true,
		];

		$this->assertSame(
			[
				'paths' => ['/a' => ['get' => 1, 'post' => 3], '/b' => 2, '/c' => 4],
				'list' => [1, 2, 3],
				'emptyList' => ['k' => 'v'],
				'mixed' => [1, 2, 'k' => 'v'],
				'scalar' => 'new',
				'toScalar' => 'flat',
				'fromScalar' => ['y' => 2],
				'nullBase' => ['z' => 1],
				'sparse' => [1 => 'a', 0 => 'b'],
				'added' => true,
			],
			$merge->invoke(null, $base, $overlay)
		);
	}//end testDeepMergeRules()
}//end class
