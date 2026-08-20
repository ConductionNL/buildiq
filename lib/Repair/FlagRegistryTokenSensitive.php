<?php

/**
 * OpenBuild Flag Registry Token Sensitive Repair Step
 *
 * Retro-flags an already-stored `registry_token` as sensitive.
 *
 * SettingsService always kept the token write-only — it is never returned to the
 * browser, only a `registry_token_set` boolean is. But it was written as an ordinary
 * appconfig string, so on every install that had already configured a remote template
 * store the token sat in cleartext in `occ config:app:get openbuild registry_token`,
 * in `occ config:list`, and in every support/status dump those feed.
 *
 * Flagging the WRITE path fixes new tokens only; a token stored before this release
 * stays unflagged until it is retyped, which nobody does. This step migrates it in
 * place: `updateSensitive()` re-encrypts the existing row at rest and redacts it from
 * that CLI output, without the admin having to touch anything.
 *
 * Idempotent, and a no-op when no token is configured.
 *
 * @category Repair
 * @package  OCA\OpenBuild\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Repair;

use OCA\OpenBuild\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Marks the stored remote-registry token as sensitive so Nextcloud encrypts it at
 * rest and redacts it from CLI/config output.
 */
class FlagRegistryTokenSensitive implements IRepairStep {
	/**
	 * The write-only remote-registry read token.
	 *
	 * @var string
	 */
	private const REGISTRY_TOKEN_KEY = 'registry_token';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config interface.
	 * @param LoggerInterface $logger The logger interface.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Flag the OpenBuild registry token as sensitive';
	}//end getName()

	/**
	 * Re-flag the stored registry token, if there is one.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/export-github-broker/tasks.md#task-6-registry-token-stored-sensitive
	 */
	public function run(IOutput $output): void {
		$token = $this->appConfig->getValueString(
			Application::APP_ID,
			self::REGISTRY_TOKEN_KEY,
			''
		);

		if ($token === '') {
			$output->info('No OpenBuild registry token configured; nothing to flag.');
			return;
		}

		try {
			$this->appConfig->updateSensitive(
				Application::APP_ID,
				self::REGISTRY_TOKEN_KEY,
				true
			);
			$output->info('OpenBuild registry token flagged as sensitive.');
		} catch (\Throwable $e) {
			// Never fatal: a failure here leaves the token exactly as it was, which is
			// the pre-existing state, not a regression.
			$output->warning('Could not flag the OpenBuild registry token: ' . $e->getMessage());
			$this->logger->warning(
				'OpenBuild: could not flag registry_token as sensitive',
				['exception' => $e->getMessage()]
			);
		}
	}//end run()
}//end class
