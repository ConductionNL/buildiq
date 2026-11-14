<?php

/**
 * Buildiq AppExport Command
 *
 * Occ command that writes the same archive the export dialog produces to a
 * file: it queues an export job, runs it at once and copies the archive to the
 * output path. An unknown slug, an unknown version or an unwritable path stops
 * before anything is queued.
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
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-export-an-app-to-a-file-req-bqic-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

use OCA\Buildiq\Kernel\Contracts\AppCatalogueInterface;
use OCA\Buildiq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ buildiq:app:export <slug> --output=<file>`.
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-export-an-app-to-a-file-req-bqic-005
 */
class AppExport extends Command {
	/**
	 * Constructor.
	 *
	 * @param AppCatalogueInterface              $catalogue Finds the app and its versions.
	 * @param ExportJobServiceInterface $exports   The export the dialog uses.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppCatalogueInterface $catalogue,
		private readonly ExportJobServiceInterface $exports,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description, argument and options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-export-an-app-to-a-file-req-bqic-005
	 */
	protected function configure(): void {
		$this->setName(name: 'buildiq:app:export')
			->setDescription(description: 'Export a buildiq app to an archive file, the same archive the export dialog produces.')
			->addArgument('slug', InputArgument::REQUIRED, 'The slug of the app to export.')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Where to write the archive (required).')
			->addOption('app-version', null, InputOption::VALUE_REQUIRED, 'The version slug to export (default: the newest published version).')
			->addOption('seed-data', null, InputOption::VALUE_NONE, 'Include the app\'s records as seed data.')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'The UID the export runs as (default: the app\'s owner).');
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * @param InputInterface  $input  The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Command exit code: 0 written, 1 when the export failed, 2 for bad input.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-export-an-app-to-a-file-req-bqic-005
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$slug = (string)$input->getArgument('slug');
		$target = trim((string)$input->getOption('output'));
		$refusal = self::outputRefusal(target: $target);
		if ($refusal !== null) {
			$output->writeln('<error>' . $refusal . ' Nothing was written.</error>');
			return Command::INVALID;
		}

		$app = $this->catalogue->app(slug: $slug);
		$appUuid = null;
		if ($app !== null) {
			$appUuid = ObjectRecord::uuid(record: $app);
		}

		if ($appUuid === null) {
			$output->writeln('<error>No app with the slug "' . $slug . '". Nothing was written.</error>');
			return Command::INVALID;
		}

		$version = $this->chosenVersion(appUuid: $appUuid, wanted: trim((string)$input->getOption('app-version')));
		if ($version === null) {
			$output->writeln('<error>The app "' . $slug . '" has no such version, or no published one. Nothing was written.</error>');
			return Command::INVALID;
		}

		return $this->export(
			slug: $slug,
			payload: [
				'applicationUuid' => $appUuid,
				'applicationVersion' => $version['semver'],
				'applicationVersionSlug' => $version['slug'],
				'target' => 'zip',
				'includeSeedData' => (bool)$input->getOption('seed-data'),
			],
			requestedBy: self::optionalString(value: $input->getOption('user')),
			target: $target,
			output: $output
		);
	}//end execute()

	/**
	 * Queue and run the export, then copy its archive to the target.
	 *
	 * @param string              $slug        The app slug.
	 * @param array<string,mixed> $payload     The export payload the dialog would send.
	 * @param string|null         $requestedBy The UID it runs as.
	 * @param string              $target      Where to write the archive.
	 * @param OutputInterface     $output      The command output.
	 *
	 * @return int The exit code.
	 */
	private function export(string $slug, array $payload, ?string $requestedBy, string $target, OutputInterface $output): int {
		try {
			$jobUuid = $this->exports->queue(applicationSlug: $slug, payload: $payload, requestedBy: $requestedBy);
			$this->exports->runNow(jobUuid: $jobUuid);
		} catch (Throwable $exception) {
			$output->writeln('<error>The export failed: ' . $exception->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$download = $this->exports->resolveDownload(uuid: $jobUuid);
		if ($download === null || @copy($download['path'], $target) === false) {
			$output->writeln('<error>The export of "' . $slug . '" produced no archive (job ' . $jobUuid . ').</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>Wrote "' . $slug . '" ' . (string)$payload['applicationVersionSlug'] . ' to ' . $target . '.</info>');
		return Command::SUCCESS;
	}//end export()

	/**
	 * The version to export: the named one, or the newest published one.
	 *
	 * @param string $appUuid The app uuid.
	 * @param string $wanted  The version slug asked for, or empty.
	 *
	 * @return array{slug:string,semver:string,status:string}|null The version, or null when there is none.
	 */
	private function chosenVersion(string $appUuid, string $wanted): ?array {
		$chosen = null;
		foreach ($this->catalogue->versions(appUuid: $appUuid) as $version) {
			if ($wanted !== '') {
				if ($version['slug'] === $wanted) {
					return $version;
				}

				continue;
			}

			if ($version['status'] === 'published' && ($chosen === null || version_compare($version['semver'], $chosen['semver'], '>') === true)) {
				$chosen = $version;
			}
		}

		return $chosen;
	}//end chosenVersion()

	/**
	 * Why the output path cannot be written, or null when it can.
	 *
	 * @param string $target The output path.
	 *
	 * @return string|null The reason.
	 */
	private static function outputRefusal(string $target): ?string {
		if ($target === '') {
			return '--output is required: where to write the archive.';
		}

		if (is_dir($target) === true) {
			return 'The output path "' . $target . '" is a folder; name the file.';
		}

		$folder = dirname($target);
		if (is_dir($folder) === false || is_writable($folder) === false) {
			return 'The folder "' . $folder . '" does not exist or cannot be written.';
		}

		return null;
	}//end outputRefusal()

	/**
	 * A trimmed option value, or null when it is empty.
	 *
	 * @param mixed $value The option value.
	 *
	 * @return string|null The value.
	 */
	private static function optionalString(mixed $value): ?string {
		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end optionalString()
}//end class
