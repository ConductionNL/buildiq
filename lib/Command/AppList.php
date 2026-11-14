<?php

/**
 * Buildiq AppList Command
 *
 * Occ command that lists every buildiq app with its slug, name and versions with their status, as a table or as JSON for scripts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\Buildiq\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

use OCA\Buildiq\Kernel\Contracts\AppCatalogueInterface;
use OCA\Buildiq\Support\ObjectRecord;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ buildiq:app:list`.
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
 */
class AppList extends Command {
	/**
	 * Constructor.
	 *
	 * @param AppCatalogueInterface $catalogue Reads the apps and their versions.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppCatalogueInterface $catalogue,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
	 */
	protected function configure(): void {
		$this->setName(name: 'buildiq:app:list')
			->setDescription(description: 'List every buildiq app with its slug, name and versions with their status.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON, for scripts.');
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Command exit code.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = [];
		foreach ($this->catalogue->apps() as $app) {
			$rows[] = [
				'slug' => (string)($app['slug'] ?? ''),
				'name' => (string)($app['name'] ?? ''),
				'versions' => $this->catalogue->versions(appUuid: (string)ObjectRecord::uuid(record: $app)),
			];
		}

		if ((bool)$input->getOption('json') === true) {
			$output->writeln((string)json_encode($rows, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
			return Command::SUCCESS;
		}

		$table = new Table($output);
		$table->setHeaders(['Slug', 'Name', 'Versions']);
		foreach ($rows as $row) {
			$table->addRow([$row['slug'], $row['name'], self::versionsText(versions: $row['versions'])]);
		}

		$table->render();
		return Command::SUCCESS;
	}//end execute()

	/**
	 * The versions as one cell: `slug (status)` each, comma-separated.
	 *
	 * @param array<int,array{slug:string,semver:string,status:string}> $versions The versions.
	 *
	 * @return string The cell text.
	 */
	private static function versionsText(array $versions): string {
		$parts = [];
		foreach ($versions as $version) {
			$parts[] = $version['slug'] . ' (' . $version['status'] . ')';
		}

		return implode(', ', $parts);
	}//end versionsText()
}//end class
