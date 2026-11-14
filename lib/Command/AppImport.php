<?php

/**
 * Buildiq AppImport Command
 *
 * Occ command that imports an exported app archive as a new app, through the same import service as the browser, owned by the given user.
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
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-import-an-app-from-a-file-req-bqic-006
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ buildiq:app:import <file> --owner=<uid>`.
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-import-an-app-from-a-file-req-bqic-006
 */
class AppImport extends Command {
	/**
	 * Constructor.
	 *
	 * @param AppPackageImportServiceInterface $importer The import service the browser uses.
	 * @param IUserManager                     $users    Checks that the owner exists.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppPackageImportServiceInterface $importer,
		private readonly IUserManager $users,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description, argument and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-import-an-app-from-a-file-req-bqic-006
	 */
	protected function configure(): void {
		$this->setName(name: 'buildiq:app:import')
			->setDescription(description: 'Import an exported app archive as a new app owned by the given user.')
			->addArgument('file', InputArgument::REQUIRED, 'The archive to import.')
			->addOption('owner', null, InputOption::VALUE_REQUIRED, 'The UID of the user who will own the new app (required).')
			->addOption('slug', null, InputOption::VALUE_REQUIRED, 'The new app\'s slug (default: the archive\'s own slug).')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'The new app\'s name (default: the archive\'s own name).');
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Command exit code: 0 on success, 1 for a refused package or slug, 2 for bad input.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-import-an-app-from-a-file-req-bqic-006
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$owner = trim((string)$input->getOption('owner'));
		if ($owner === '') {
			$output->writeln('<error>--owner is required: the UID of the user who will own the new app.</error>');
			return Command::INVALID;
		}

		if ($this->users->userExists($owner) === false) {
			$output->writeln('<error>The user "' . $owner . '" does not exist; nothing was imported.</error>');
			return Command::INVALID;
		}

		$file = (string)$input->getArgument('file');
		if (is_file($file) === false || is_readable($file) === false) {
			$output->writeln('<error>The file "' . $file . '" cannot be read; nothing was imported.</error>');
			return Command::INVALID;
		}

		$result = $this->importer->import(
			archivePath: $file,
			name: (string)$input->getOption('name'),
			slug: (string)$input->getOption('slug'),
			ownerUid: $owner
		);

		return self::report(result: $result, owner: $owner, output: $output);
	}//end execute()

	/**
	 * Print the import's outcome and answer its exit code.
	 *
	 * @param array{status:int,data:array<string,mixed>} $result The import result.
	 * @param string                                     $owner  The owner.
	 * @param OutputInterface                            $output The command output.
	 *
	 * @return int The exit code.
	 */
	private static function report(array $result, string $owner, OutputInterface $output): int {
		$data = $result['data'];
		if ($result['status'] === 201) {
			$output->writeln('<info>Imported the app "' . (string)($data['slug'] ?? '') . '" for ' . $owner . '.</info>');
			foreach ((array)($data['warnings'] ?? []) as $warning) {
				if (is_string($warning) === false) {
					$warning = (string)json_encode($warning);
				}

				$output->writeln('<comment>Warning: ' . $warning . '</comment>');
			}

			return Command::SUCCESS;
		}

		$line = 'Import refused: ' . (string)($data['error'] ?? 'error');
		if (isset($data['path']) === true && is_string($data['path']) === true && $data['path'] !== '') {
			$line .= ' in ' . $data['path'];
		}

		if (isset($data['detail']) === true && is_scalar($data['detail']) === true) {
			$line .= ': ' . (string)$data['detail'];
		}

		$output->writeln('<error>' . $line . '</error>');
		return Command::FAILURE;
	}//end report()
}//end class
