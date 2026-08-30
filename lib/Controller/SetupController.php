<?php

/**
 * Buildiq first-time-setup contract (ADR-042).
 *
 * Backs the shared CnSetupWizard renderer for Buildiq's own manifest `setup`
 * block: reports per-step completion (`GET /api/setup/status`), persists config
 * values from `config-fields` steps (`POST /api/setup/config`), and runs
 * privileged server-side actions from `run-action` steps
 * (`POST /api/setup/action/{actionId}`). The wizard NEVER writes OpenRegister
 * objects from the browser — the `seed-templates` action runs here, in an
 * admin request context, so OpenRegister's admin-only create check on the
 * ApplicationTemplate schema is satisfied.
 *
 * Every method is admin-only and says so TWICE, at two different layers.
 *
 * These methods used to carry `#[NoAdminRequired]` with the stated rationale
 * that the body's `IGroupManager::isAdmin` gate meant we did "not rely on the
 * SecurityMiddleware default alone". That reasoning was inverted:
 * `#[NoAdminRequired]` does not ADD a layer, it REMOVES one — it tells NC's
 * SecurityMiddleware to stop requiring admin, leaving the body check as the
 * only thing standing between a non-admin and a wizard that writes app config
 * and seeds OpenRegister objects. Hydra gate-9 (semantic-auth) flags exactly
 * this shape: an annotation that contradicts the method body.
 *
 * The attribute is now `#[AuthorizedAdminSetting]`, so the middleware enforces
 * admin BEFORE dispatch, AND `requireAdmin()` stays in each body as the
 * defence-in-depth layer the original comment was reaching for. CSRF is
 * enforced (no `#[NoCSRFRequired]`): the SPA posts via `@nextcloud/axios`,
 * which sends the `requesttoken`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\DemoDataService;
use OCA\Buildiq\Service\SettingsService;
use OCA\Buildiq\Service\TemplateSeedService;
use OCA\Buildiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * First-time-setup status + config + actions for the abstract setup wizard.
 *
 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-2.1
 */
class SetupController extends Controller {

	/**
	 * Setup contract version; MUST match `manifest.setup.version`.
	 *
	 * @var int
	 */
	private const SETUP_VERSION = 1;
	/**
	 * App-config key recording that the optional demo-data step has been dealt with.
	 *
	 * Records a DECISION, not a state: "installed" and "declined" both set it.
	 * A step that reports itself undone until demo objects exist can never be
	 * completed by an operator who does not want them.
	 *
	 * @var string
	 */
	private const DEMO_DATA_DECIDED_KEY = 'demo_data_decided';

	/**
	 * App-config key stamped when setup completes (`manifest.setup.completionConfigKey`).
	 *
	 * @var string
	 */
	private const COMPLETION_KEY = 'setup_completed_version';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param IAppConfig $appConfig App-config reader/writer
	 * @param IUserSession $userSession Current Nextcloud user session
	 * @param IGroupManager $groupManager Group membership resolver (admin gate)
	 * @param DemoDataService $demoDataService Demo dataset import (ADR-111 rule 4)
	 * @param SettingsService $settings Settings write path (registry_* + secret token)
	 * @param TemplateSeedService $seedService Shared idempotent seeding service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly DemoDataService $demoDataService,
		private readonly SettingsService $settings,
		private readonly TemplateSeedService $seedService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Report per-step setup status for the wizard, and stamp the completion
	 * key when the required step (templates seeded) is satisfied — so an
	 * already-seeded instance is pre-satisfied on first boot after upgrade
	 * without showing the wizard.
	 *
	 * @return JSONResponse `{ version, completed, steps: { <id>: { done } } }`.
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-4.1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): JSONResponse {
		$denied = $this->requireAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$seedDone = $this->seedService->countSeeded() > 0;
		$storeDone = $this->appConfig->getValueString(Application::APP_ID, 'registry_url', '') !== '';
		// DEALT WITH, not "demo objects exist". An operator who declines demo
		// data has finished the step; re-offering it every visit would make
		// "no thanks" impossible to express.
		$demoDecided = $this->appConfig->getValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, '') !== '';
		$completed = $seedDone;

		if ($completed === true) {
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::COMPLETION_KEY,
				(string)self::SETUP_VERSION
			);
		}

		return new JSONResponse(
			[
				'version' => self::SETUP_VERSION,
				'completed' => $completed,
				'steps' => [
					'demo-data' => ['done' => $demoDecided],
					'seed' => ['done' => $seedDone],
					'store' => ['done' => $storeDone],
				],
			]
		);
	}//end status()

	/**
	 * Persist app-config values from a `config-fields` step (the remote
	 * template store: `registry_url`, `registry_register`, `registry_token`).
	 * Routed through SettingsService so the write-only secret semantics on
	 * `registry_token` are preserved.
	 *
	 * @return JSONResponse `{ success }`.
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-3.1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): JSONResponse {
		$denied = $this->requireAdmin();
		if ($denied !== null) {
			return $denied;
		}

		$params = $this->request->getParams();
		unset($params['_route']);

		$this->settings->updateSettings($params);

		return new JSONResponse(['success' => true]);
	}//end saveConfig()

	/**
	 * Run a privileged server-side setup action.
	 *
	 * @param string $actionId The action id; `seed-templates` is the only one.
	 *
	 * @return JSONResponse `{ success, message, detail }`.
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-2.1
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): JSONResponse {
		$denied = $this->requireAdmin();
		if ($denied !== null) {
			return $denied;
		}

		if ($actionId === 'install-demo-data') {
			return $this->installDemoData();
		}

		if ($actionId === 'skip-demo-data') {
			return $this->skipDemoData();
		}

		if ($actionId !== 'seed-templates') {
			return new JSONResponse(
				['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
				Http::STATUS_NOT_FOUND
			);
		}

		try {
			$result = $this->seedService->seed();
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: setup seed-templates action failed',
				['exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['success' => false, 'message' => 'Template seeding failed unexpectedly.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if (empty($result['errors']) === false) {
			return new JSONResponse(
				[
					'success' => false,
					'message' => 'Seeded ' . $result['seeded'] . ' template(s) with errors: ' . implode('; ', $result['errors']),
					'detail' => $result,
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			[
				'success' => true,
				'message' => 'Seeded ' . $result['seeded'] . ' template(s), updated ' . $result['updated']
					. ', skipped ' . $result['skipped'] . ' already present.',
				'detail' => $result,
			]
		);
	}//end runAction()

	/**
	 * Install the shipped demo dataset (ADR-111 rule 4).
	 *
	 * @return JSONResponse The outcome, carrying the counts.
	 *
	 * @spec exclude Demo-data install action (ADR-111 rule 4); no per-app openspec change yet.
	 */
	private function installDemoData(): JSONResponse {
		try {
			$imported = $this->demoDataService->install();
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: setup install-demo-data failed', ['exception' => $e->getMessage()]);

			return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
		}

		// Recorded only after the import actually returned. Marking it first
		// would let a failed install present as a finished step.
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'installed');

		// 🔴 THE COUNTS, ALWAYS. "Demo data installed" with no numbers cannot be
		// told apart from an import that wrote nothing.
		return new JSONResponse(
			[
				'success' => true,
				'message' => sprintf(
					'Demo data installed: %d objects across %d schemas.',
					$imported['objects'],
					$imported['schemas']
				),
				'detail'  => $imported,
			]
		);
	}//end installDemoData()

	/**
	 * Record that the operator declined the demo dataset.
	 *
	 * Its own action so "no thanks" is a decision the wizard can record. Without
	 * it the only way past the step would be to install demo data, which is
	 * wrong on a production instance.
	 *
	 * @return JSONResponse The outcome.
	 *
	 * @spec exclude Demo-data skip action (ADR-111 rule 4); no per-app openspec change yet.
	 */
	private function skipDemoData(): JSONResponse {
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

		return new JSONResponse(['success' => true, 'message' => 'Demo data skipped.']);
	}//end skipDemoData()

	/**
	 * Enforce the explicit admin gate (ADR-005): setup actions provision
	 * admin-only records / write global config, so a non-admin authenticated
	 * caller must be rejected in-body, not merely by the framework default.
	 *
	 * @return JSONResponse|null A 401/403 response when denied, null when the caller is an admin.
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-2.1
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => 'unauthenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['error' => 'forbidden', 'message' => 'Setup requires Nextcloud admin privileges.'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireAdmin()
}//end class
