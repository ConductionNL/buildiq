<?php

/**
 * Buildiq AppDelete Command
 *
 * Occ command that deletes a buildiq app and its versions after confirmation.
 * The app's registers and records stay unless asked, and anything it tried and
 * failed to remove is listed.
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
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-delete-an-app-from-the-command-line-req-bqic-007
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

use OCA\Buildiq\Kernel\Contracts\AppCatalogueInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationDeletionServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `occ buildiq:app:delete <slug>`.
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-delete-an-app-from-the-command-line-req-bqic-007
 */
class AppDelete extends Command {
	/**
	 * Constructor.
	 *
	 * @param AppCatalogueInterface                        $catalogue Finds the app by slug.
	 * @param ApplicationDeletionServiceInterface $deletion  The deletion the browser uses.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppCatalogueInterface $catalogue,
		private readonly ApplicationDeletionServiceInterface $deletion,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description, argument and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-delete-an-app-from-the-command-line-req-bqic-007
	 */
	protected function configure(): void {
		$this->setName(name: 'buildiq:app:delete')
			->setDescription(description: 'Delete a buildiq app and its versions; its registers and records stay unless asked.')
			->addArgument('slug', InputArgument::REQUIRED, 'The slug of the app to delete.')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without asking for confirmation.')
			->addOption('delete-data', null, InputOption::VALUE_NONE, 'Also delete the app\'s registers and their records.');
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Command exit code: 0 deleted or declined, 1 when something was left, 2 for an unknown slug.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-delete-an-app-from-the-command-line-req-bqic-007
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$slug = (string)$input->getArgument('slug');
		$app = $this->catalogue->app(slug: $slug);
		$uuid = null;
		if ($app !== null) {
			$uuid = ObjectRecord::uuid(record: $app);
		}

		if ($uuid === null) {
			$output->writeln('<error>No app with the slug "' . $slug . '".</error>');
			return Command::INVALID;
		}

		$deleteData = (bool)$input->getOption('delete-data');
		$forced = (bool)$input->getOption('force');
		if ($forced === false && $this->confirmed(input: $input, output: $output, slug: $slug, deleteData: $deleteData) === false) {
			$output->writeln('Nothing was deleted.');
			return Command::SUCCESS;
		}

		$orphaned = $this->deletion->deleteApplication(appUuid: $uuid, appSlug: $slug, deleteData: $deleteData);
		if ($orphaned !== []) {
			$output->writeln('<error>Deleted "' . $slug . '", but these could not be removed:</error>');
			foreach ($orphaned as $resource) {
				$output->writeln('  - ' . $resource);
			}

			return Command::FAILURE;
		}

		$kept = ' Its registers and records were kept.';
		if ($deleteData === true) {
			$kept = ' Its registers and records were deleted too.';
		}

		$output->writeln('<info>Deleted the app "' . $slug . '" and its versions.' . $kept . '</info>');
		return Command::SUCCESS;
	}//end execute()

	/**
	 * Ask whether to go on; no answer (a non-interactive run) is no.
	 *
	 * @param InputInterface  $input      The command input.
	 * @param OutputInterface $output     The command output.
	 * @param string          $slug       The app slug.
	 * @param bool            $deleteData Whether the data goes too.
	 *
	 * @return bool True when confirmed.
	 */
	private function confirmed(InputInterface $input, OutputInterface $output, string $slug, bool $deleteData): bool {
		$what = 'Delete the app "' . $slug . '" and its versions';
		if ($deleteData === true) {
			$what .= ', AND its registers and records';
		}

		$helper = $this->getHelper(name: 'question');
		if (($helper instanceof QuestionHelper) === false) {
			return false;
		}

		return (bool)$helper->ask($input, $output, new ConfirmationQuestion($what . '? [y/N] ', false));
	}//end confirmed()
}//end class
