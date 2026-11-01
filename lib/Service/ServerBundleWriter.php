<?php

/**
 * Writes the files that run an exported app on a server of its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\App\IAppManager;
use RuntimeException;

/**
 * Copies `lib/Resources/deploy/` into an export tree as `deploy/` and says what that server needs.
 *
 * The versions come from the exported app's own `appinfo/info.xml` and from the
 * OpenRegister installed here, so the export dialog and the bundle name the same numbers.
 *
 * @spec openspec/specs/openbuild-exporter/spec.md
 */
class ServerBundleWriter {

	/**
	 * The database the bundle's compose file runs.
	 */
	private const DATABASE = 'PostgreSQL 16';

	/**
	 * The deploy template folder.
	 *
	 * @var string
	 */
	private string $deployRoot;

	/**
	 * The app template's info.xml, which names the Nextcloud and PHP versions.
	 *
	 * @var string
	 */
	private string $templateInfoXml;

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Reads the installed OpenRegister version.
	 * @param string|null $resourcesRoot The `lib/Resources` folder; null uses the app's own.
	 */
	public function __construct(
		private IAppManager $appManager,
		?string $resourcesRoot = null,
	) {
		$root = $resourcesRoot ?? dirname(__DIR__) . '/Resources';
		$this->deployRoot = $root . '/deploy';
		$this->templateInfoXml = $root . '/template/appinfo/info.xml';
	}//end __construct()

	/**
	 * What a server needs to run an exported app.
	 *
	 * @return array{nextcloud: string, openregister: string, php: string, database: string} Minimum versions.
	 *
	 * @spec openspec/changes/apps-standalone-deploy/specs/standalone-server-bundle/spec.md#requirement-the-export-offers-its-own-server-as-a-target-req-bqsd-001
	 */
	public function requirements(): array {
		$nextcloud = '';
		$php = '';
		$xml = false;
		if (is_file($this->templateInfoXml) === true) {
			$xml = simplexml_load_file($this->templateInfoXml);
		}

		if ($xml !== false) {
			$nextcloud = (string)($xml->dependencies->nextcloud['min-version'] ?? '');
			$php = (string)($xml->dependencies->php['min-version'] ?? '');
		}

		return [
			'nextcloud' => $nextcloud,
			'openregister' => $this->appManager->getAppVersion('openregister'),
			'php' => $php,
			'database' => self::DATABASE,
		];
	}//end requirements()

	/**
	 * Write `deploy/` into an export tree.
	 *
	 * The app's own `{{tokens}}` stay for the export's placeholder pass; the
	 * versions are filled in here.
	 *
	 * @param string $rootDir The export tree root.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the deploy template is missing.
	 *
	 * @spec openspec/changes/apps-standalone-deploy/specs/standalone-server-bundle/spec.md#requirement-the-export-offers-its-own-server-as-a-target-req-bqsd-001
	 * @spec openspec/changes/apps-standalone-deploy/specs/standalone-server-bundle/spec.md#requirement-openregister-matches-the-builder-s-version-req-bqsd-004
	 */
	public function write(string $rootDir): void {
		if (is_dir($this->deployRoot) === false) {
			throw new RuntimeException('Deploy template is missing: ' . $this->deployRoot);
		}

		$requirements = $this->requirements();
		$tokens = [
			'{{openregisterVersion}}' => $requirements['openregister'],
			'{{nextcloudVersion}}' => $requirements['nextcloud'],
		];

		$target = $rootDir . '/deploy';
		if (is_dir($target) === false) {
			mkdir($target, 0o755, true);
		}

		foreach ((array)scandir($this->deployRoot) as $name) {
			$source = $this->deployRoot . '/' . (string)$name;
			if (is_file($source) === false) {
				continue;
			}

			file_put_contents($target . '/' . (string)$name, strtr((string)file_get_contents($source), $tokens));
		}
	}//end write()

	/**
	 * Point the exported app's README at `deploy/README.md`.
	 *
	 * @param string $rootDir The export tree root, after the README was written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apps-standalone-deploy/specs/standalone-server-bundle/spec.md#requirement-the-export-offers-its-own-server-as-a-target-req-bqsd-001
	 */
	public function pointReadme(string $rootDir): void {
		$readme = $rootDir . '/README.md';
		$pointer = "\n## Run it on its own server\n\nSee [deploy/README.md](deploy/README.md).\n";
		file_put_contents($readme, $pointer, FILE_APPEND);
	}//end pointReadme()
}//end class
