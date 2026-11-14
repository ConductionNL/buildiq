<?php

/**
 * Coverage test for the organisation guard: which doors resolve an Application.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Every class under lib/ that reads the Application schema (`built-app`) is
 * either a guarded door or on the explicit list of readers not yet reviewed
 * for the scope. A new reader on neither list fails this test, so a door
 * cannot be added without deciding about the organisation scope.
 *
 * @coversNothing
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class AppOrganisationGuardCoverageTest extends TestCase {

	/**
	 * The doors the spec names, each of which must consult the guard: the manifest
	 * endpoint and runtime, the navigation, and the MCP tools (list_apps and
	 * get_app_manifest go through AbstractToolHandler::requireAnyRoleOnApp()).
	 *
	 * @var array<string, string> Path => the call that proves the guard is consulted.
	 */
	private const GUARDED = [
		'lib/Service/ManifestResolverService.php' => 'visibleToCaller(',
		'lib/Service/AppNavigationService.php' => 'visibleToOrganisation(',
		'lib/Mcp/Handler/AbstractToolHandler.php' => 'visibleToOrganisation(',
		'lib/Mcp/Handler/ListAppsHandler.php' => 'requireAnyRoleOnApp(',
		'lib/Mcp/Handler/GetAppManifestHandler.php' => 'requireAnyRoleOnApp(',
	];

	/**
	 * Readers of the Application schema not yet reviewed for the organisation
	 * scope (operate-organisation-scoped-apps, T04 follow-up). Shrink this list;
	 * never grow it.
	 *
	 * @var array<int, string>
	 */
	private const NOT_YET_REVIEWED = [
		'lib/Controller/AgentsController.php',
		'lib/Controller/ApplicationsController.php',
		'lib/Controller/ExportsController.php',
		'lib/Kernel/Contracts/AppOverrideServiceInterface.php',
		'lib/Kernel/Contracts/ApplicationVersionServiceInterface.php',
		'lib/Listener/ListenerRegistrar.php',
		'lib/Repair/PopulateApplicationPermissions.php',
		'lib/Service/ApplicationInsightsService.php',
		'lib/Service/AutomationWriteService.php',
		'lib/Service/CopilotService.php',
		'lib/Service/DocumentGenerationService.php',
		'lib/Service/ExportJobService.php',
		'lib/Service/FlowChannelProvisioner.php',
		'lib/Service/GitHubAppSyncService.php',
		'lib/Service/IconService.php',
		'lib/Service/PublishedApplicationProvider.php',
	];

	/**
	 * Each named door consults the guard.
	 *
	 * @return void
	 */
	public function testEveryNamedDoorConsultsTheGuard(): void {
		foreach (self::GUARDED as $path => $call) {
			$source = (string)file_get_contents($this->root() . '/' . $path);
			$this->assertStringContainsString($call, $source, $path . ' resolves an Application without the organisation guard');
		}
	}//end testEveryNamedDoorConsultsTheGuard()

	/**
	 * No reader of the Application schema is on neither list.
	 *
	 * @return void
	 */
	public function testNoNewReaderSkipsTheDecision(): void {
		$known = array_merge(array_keys(self::GUARDED), self::NOT_YET_REVIEWED);
		$unknown = [];
		foreach ($this->readers() as $path) {
			if (in_array($path, $known, true) === false) {
				$unknown[] = $path;
			}
		}

		$this->assertSame([], $unknown, 'New readers of the Application schema must consult AppOrganisationGuard');
	}//end testNoNewReaderSkipsTheDecision()

	/**
	 * Every lib/ file that names the Application schema slug.
	 *
	 * @return array<int, string> Paths relative to the app root.
	 */
	private function readers(): array {
		$root = $this->root();
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS));
		$paths = [];
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			if (str_contains((string)file_get_contents($file->getPathname()), "'built-app'") === true) {
				$paths[] = substr($file->getPathname(), strlen($root) + 1);
			}
		}

		sort($paths);
		return $paths;
	}//end readers()

	/**
	 * The app root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()
}//end class
