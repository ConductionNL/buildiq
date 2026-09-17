<?php

/**
 * Installs this app's demo dataset on request (ADR-111).
 *
 * An app installed from the App Store opens on an empty list, and the only
 * question its first reader has is whether they can see it work. Answering it
 * requires data they cannot author, against a schema they do not know yet.
 *
 * This service imports `lib/Settings/buildiq_mock_register.json` — a `type: mock` descriptor
 * whose every object was generated from the schema that validates it —
 * through the same OpenRegister importer the app already uses for its real
 * configuration.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. A mock register has no Repair step and
 * is not imported at boot: demo objects appearing unasked on a production
 * instance are indistinguishable from real records to everyone who did not
 * install it. The operator asks, through the setup walkthrough or `occ`.
 *
 * 🔴 AND `force: true`, DELIBERATELY. OpenRegister's importer version-gates a
 * non-forced import and SKIPS silently when the version has not moved. An
 * operator who clicks "install demo data" and is told it succeeded, on an
 * instance where nothing was written, has been lied to by a version compare.
 * The request is explicit, so the import is unconditional.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Imports the generated demo dataset into OpenRegister on request.
 *
 * @spec openspec/changes/openbuild-first-time-setup/specs/openbuild-first-time-setup/spec.md
 */
class DemoDataService {
	/**
	 * App-relative path to the generated mock descriptor.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/buildiq_mock_register.json';

	/**
	 * Configuration identity for the demo import.
	 *
	 * 🔴 ITS OWN NAMESPACE, not the app id. Sharing the app's identity would
	 * make the demo import and the real configuration import share one version
	 * gate, so installing demo data could mask a pending configuration update
	 * — or be masked by one.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this app ships a demo dataset at all.
	 *
	 * @return boolean True when the descriptor is present on disk.
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/specs/openbuild-first-time-setup/spec.md
	 */
	public function isAvailable(): bool {
		return is_file($this->descriptorPath()) === true;
	}//end isAvailable()

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app ships.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so the label, the
	 * description and the object count come from the descriptor that will
	 * actually be imported. A manifest that restated them could disagree with
	 * what lands, and nothing would notice.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is imported. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		$objects = $this->shippedObjectCount();
		if ($objects !== null) {
			$choices[] = [
				'id'    => self::DEMO_DATASET,
				'label' => 'Example data',
				// 🔴 NO NUMBER IN THIS SENTENCE. The wizard runs a card's
				// description through the app's translation function, which is a
				// literal lookup, so an interpolated count would make the string
				// untranslatable and leave a Dutch operator reading English. The
				// count travels as `objectCount` and the card renders it as a
				// stat, with a label the library translates.
				'description' => (
					'Sample values for every schema this app supplies, generated from the schemas '
					. 'themselves. It shows the lists, detail pages and dashboards working rather '
					. 'than telling a story. Safe to run more than once, and you can delete it '
					. 'afterwards.'
				),
				'objectCount' => $objects,
				'icon'        => 'DatabaseOutline',
			];
		}

		return $choices;

	}//end listChoices()

	/**
	 * How many objects the shipped descriptor carries, or null when it ships none.
	 *
	 * Counted from the FILE, so the card promises the number that will actually
	 * be imported. A missing or malformed descriptor returns null and the app
	 * then offers only "None" — honest, rather than an import that cannot run.
	 *
	 * @return integer|null The object count, or null when there is no usable descriptor.
	 */
	private function shippedObjectCount(): ?int {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			return null;
		}

		$components = ($data['components'] ?? []);
		if (is_array($components) === false || is_array(($components['objects'] ?? null)) === false) {
			return 0;
		}

		return count($components['objects']);

	}//end shippedObjectCount()

	/**
	 * Import the demo dataset.
	 *''
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. Every caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened"
	 * must not be presentable as success.
	 *
	 * 🔴 COUNTS WHAT LANDED, NOT WHAT WAS ASKED FOR. OpenRegister SKIPS an object
	 * whose schema it cannot resolve instead of failing the import, so a count
	 * taken from the file reports success for a run that seeded nothing — on
	 * 2026-09-15 the wizard said "Demo data installed: 18 objects" while all 18
	 * had been skipped, because the descriptor addressed schemas by NAME
	 * (`Application`) and OpenRegister resolves them by SLUG (`built-app`).
	 * `objects` is therefore the importer's own tally of what it wrote or found
	 * already present; the file's count travels as `declared` and the gap as
	 * `skipped`, so an operator sees the discrepancy rather than a number that
	 * merely repeats the request. Same rule as portaliq#499.
	 *
	 * @return array{objects: integer, declared: integer, skipped: integer, registers: integer, schemas: integer}
	 *   `objects` = what landed, `declared` = what the file holds, `skipped` =
	 *   what the importer refused, plus the register and schema tallies.
	 *
	 * @throws RuntimeException When the descriptor is missing, unreadable, or
	 *   OpenRegister is absent — or when it declares objects and none landed.
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/specs/openbuild-first-time-setup/spec.md
	 */
	public function install(): array {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			throw new RuntimeException('No demo dataset ships with this app (' . self::DESCRIPTOR . ' not found).');
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo dataset is not valid JSON: ' . $path);
		}

		// The number ASKED FOR comes from the file; the number that LANDED comes
		// from the importer. They differ whenever OpenRegister skips an object
		// whose schema it cannot resolve, and that gap is exactly the condition
		// an operator must be able to see.
		$declared = 0;
		$components = ($data['components'] ?? []);
		if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
			$declared = count($components['objects']);
		}

		$result = $this->configurationService()->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		// `objects` lists what the importer wrote; newer OpenRegister versions
		// also count what they deliberately left alone (`unchanged`, an object
		// already present and identical), which is landed data too — a re-run
		// of the demo import must not read as a failure.
		$landed  = count((array)($result['objects'] ?? []));
		$landed += (int)($result['unchanged']['objects'] ?? 0);
		$skipped = (int)($result['skipped']['objects'] ?? 0);
		if ($declared > 0 && $landed === 0) {
			throw new RuntimeException(
				'The demo dataset declares ' . $declared . ' object(s) but OpenRegister imported none of them'
				. ' (' . $skipped . ' skipped — their schema could not be resolved; see the Nextcloud log).'
			);
		}

		$imported = [
			'objects'   => $landed,
			'declared'  => $declared,
			'skipped'   => $skipped,
			'registers' => count((array)($result['registers'] ?? [])),
			'schemas'   => count((array)($result['schemas'] ?? [])),
		];

		$this->logger->info(
			'[DemoDataService] imported demo data: '
			. $imported['objects'] . ' of ' . $imported['declared'] . ' object(s) landed ('
			. $imported['skipped'] . ' skipped), '
			. $imported['registers'] . ' register(s), '
			. $imported['schemas'] . ' schema(s).',
			['app' => Application::APP_ID]
		);

		return $imported;
	}//end install()

	/**
	 * Absolute path to the shipped descriptor.
	 *
	 * @return string The path.
	 */
	private function descriptorPath(): string {
		return $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
	}//end descriptorPath()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP. OpenRegister may not be
	 * installed, and asking the container for a class from a missing app
	 * raises something the caller cannot act on. Check first and say which app
	 * is missing.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT.
	 * Naming a class from an OPTIONAL app in a native return type makes PHP
	 * resolve it whenever this method returns — so on an instance without
	 * OpenRegister the failure is a TypeError about a class nobody mentioned,
	 * instead of the RuntimeException above that names the missing app. It
	 * also makes the method impossible to exercise in a unit test, which is
	 * how this was found. The docblock keeps psalm and phpstan informed.
	 *
	 * @return object The importer — an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Demo data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class
