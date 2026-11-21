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
		'lib/Controller/ApplicationsController.php' => 'visibleToOrganisation(',
		'lib/Controller/FlowBindingsController.php' => 'seesApplication(',
		'lib/Mcp/Handler/ListAppsHandler.php' => 'requireAnyRoleOnApp(',
		'lib/Mcp/Handler/GetAppManifestHandler.php' => 'requireAnyRoleOnApp(',
		'lib/Controller/AgentsController.php' => 'seesApplication(',
		'lib/Controller/ExportsController.php' => 'visibleToOrganisation(',
		'lib/Service/AgentPlanService.php' => 'visibleToOrganisation(',
		'lib/Service/ApplicationInsightsService.php' => 'seesApplication(',
		'lib/Service/AutomationWriteService.php' => 'seesApplication(',
		'lib/Service/CopilotService.php' => 'seesApplication(',
		'lib/Controller/GitHubSyncController.php' => 'seesApplication(',
		// The icon routes hand the caller's guard to the service, which serves an
		// app the caller may not see as an unknown slug (IconController::mayServe()).
		'lib/Service/IconService.php' => '$mayServe($application)',
		'lib/Controller/IconController.php' => 'seesApplication(',
	];

	/**
	 * Files that name the Application schema but are no door for a user, so the
	 * organisation scope does not apply: contracts that only declare the slug as
	 * a constant, listener wiring that filters events by schema, the repair step
	 * that runs during an upgrade, the occ commands' catalogue, which the
	 * instance administrator runs from the command line, and the automation
	 * flow backend, which only writes a flow binding into `Application.flows`
	 * while compiling an automation whose caller already passed the app's
	 * role check, and returns nothing of the Application to anyone.
	 *
	 * Reviewed for operate-organisation-scoped-apps T04: document generation
	 * resolves the app of an automation that is already running and runs as the
	 * app's owner; the export job service only names the schema in a comment;
	 * the flow channel provisioner writes bindings into the app an install just
	 * created; the GitHub sync service loads an app for GitHubSyncController,
	 * whose requireRole() is the door and consults the guard; and the published
	 * application provider is the shared read behind the navigation (guarded)
	 * and the dashboard, which reads only a served app's device features.
	 *
	 * @var array<int, string>
	 */
	private const NO_USER_DOOR = [
		'lib/Kernel/Contracts/AppOverrideServiceInterface.php',
		'lib/Kernel/Contracts/ApplicationVersionServiceInterface.php',
		'lib/Service/Automation/AutomationFlowBackend.php',
		'lib/Listener/ListenerRegistrar.php',
		'lib/Repair/PopulateApplicationPermissions.php',
		'lib/Service/AppCatalogue.php',
		'lib/Service/DocumentGenerationService.php',
		'lib/Service/ExportJobService.php',
		'lib/Service/FlowChannelProvisioner.php',
		'lib/Service/GitHubAppSyncService.php',
		'lib/Service/PublishedApplicationProvider.php',
	];

	/**
	 * Readers of the Application schema not yet reviewed for the organisation
	 * scope (operate-organisation-scoped-apps, T04 follow-up). Shrink this list;
	 * never grow it. Empty since the icon service takes the caller's guard from
	 * its controller (21 Nov).
	 *
	 * @var array<int, string>
	 */
	private const NOT_YET_REVIEWED = [];

	/**
	 * Each named door consults the guard.
	 *
	 * @return void
	 */
	public function testEveryNamedDoorConsultsTheGuard(): void {
		foreach (self::GUARDED as $path => $call) {
			$source = (string)file_get_contents($this->root() . '/' . $path);
			$this->assertStringContainsString(needle: $call, haystack: $source, message: $path . ' resolves an Application without the organisation guard');
		}
	}//end testEveryNamedDoorConsultsTheGuard()

	/**
	 * No reader of the Application schema is on neither list.
	 *
	 * @return void
	 */
	public function testNoNewReaderSkipsTheDecision(): void {
		$known = array_merge(array_keys(self::GUARDED), self::NO_USER_DOOR, self::NOT_YET_REVIEWED);
		$unknown = [];
		foreach ($this->readers() as $path) {
			if (in_array($path, $known, true) === false) {
				$unknown[] = $path;
			}
		}

		$this->assertSame(expected: [], actual: $unknown, message: 'New readers of the Application schema must consult AppOrganisationGuard');
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
