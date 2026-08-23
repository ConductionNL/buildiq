<?php

/**
 * Buildiq PublishTemplates Command
 *
 * Occ command that seeds the GitHub-only store by publishing the seeded
 * `application-template` objects to GitHub as `buildiq-app` repos. Each
 * targeted template is serialized (AppRepoSerializer::serializeTemplate) and
 * published through GitHubAppSyncService::publishTemplate — every GitHub write
 * routes through OpenRegister's credential broker, so the credential token
 * never reaches Buildiq.
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
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Occ buildiq:templates:publish.
 *
 * Publishes seeded `application-template` objects to GitHub as `buildiq-app`
 * repos (the GitHub-only store's seed operation). A single template failure is
 * collected and reported without aborting the rest; the command exits non-zero
 * when any template fails.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
class PublishTemplates extends Command {
	/**
	 * The shared Buildiq register slug.
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * The ApplicationTemplate schema slug (seeded store templates).
	 */
	private const TEMPLATE_SCHEMA = 'application-template';

	/**
	 * Constructor.
	 *
	 * @param GitHubAppSyncService $syncService The broker-routed GitHub sync service.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (template lookup).
	 * @param LoggerInterface $logger PSR logger (secret-free diagnostics only).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GitHubAppSyncService $syncService,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'buildiq:templates:publish')
			->setDescription(description: 'Publish seeded Buildiq application-templates to GitHub as buildiq-app repos.')
			->addOption('credential', null, InputOption::VALUE_REQUIRED, 'The github broker credential UUID to publish with (required).')
			->addOption('org', null, InputOption::VALUE_REQUIRED, 'Org to publish the repos under (omit = the credential user\'s account).')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'The credential owner / acting user UID (broker identity).')
			->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Publish only this template slug (omit = all seeded templates).')
			->addOption('visibility', null, InputOption::VALUE_REQUIRED, 'Fresh-repo visibility: public|private (default public).', 'public')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the templates that would be published without calling GitHub.');
	}//end configure()

	/**
	 * Execute the command.
	 *
	 * @param InputInterface $input The command input.
	 * @param OutputInterface $output The command output.
	 *
	 * @return int Command exit code.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$credentialId = trim((string)$input->getOption('credential'));
		if ($credentialId === '') {
			$output->writeln('<error>--credential is required (the github broker credential UUID).</error>');
			return Command::INVALID;
		}

		$visibility = (string)$input->getOption('visibility');
		if (in_array($visibility, ['public', 'private'], true) === false) {
			$output->writeln('<error>--visibility must be "public" or "private".</error>');
			return Command::INVALID;
		}

		$slug = $this->optionOrNull(value: $input->getOption('slug'));
		$dryRun = (bool)$input->getOption('dry-run');

		if ($dryRun === false && $this->syncService->isBrokerAvailable() === false) {
			$output->writeln('<error>The OpenRegister credential broker is not available on this instance — cannot publish.</error>');
			return Command::FAILURE;
		}

		$templates = $this->resolveTemplates(slug: $slug);
		if ($templates === []) {
			$scope = '';
			if ($slug !== null) {
				$scope = ' for slug "' . $slug . '"';
			}

			$output->writeln('<comment>No seeded application-templates found' . $scope . '.</comment>');
			return Command::SUCCESS;
		}

		$failed = $this->publishAll(
			templates: $templates,
			credentialId: $credentialId,
			org: $this->optionOrNull(value: $input->getOption('org')),
			user: $this->optionOrNull(value: $input->getOption('user')),
			visibility: $visibility,
			dryRun: $dryRun,
			output: $output
		);

		if ($failed > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Publish each template, printing a per-template result line and a summary.
	 *
	 * @param array<int,array<string,mixed>> $templates The seeded templates to publish.
	 * @param string $credentialId The github credential UUID.
	 * @param string|null $org Optional org.
	 * @param string|null $user The acting user UID.
	 * @param string $visibility Repo visibility.
	 * @param bool $dryRun When true, list only (no GitHub calls).
	 * @param OutputInterface $output The command output.
	 *
	 * @return int The count of hard failures.
	 */
	private function publishAll(
		array $templates,
		string $credentialId,
		?string $org,
		?string $user,
		string $visibility,
		bool $dryRun,
		OutputInterface $output,
	): int {
		$published = 0;
		$failed = 0;
		foreach ($templates as $template) {
			$templateSlug = (string)($template['slug'] ?? '(unknown)');

			if ($dryRun === true) {
				$output->writeln('  <info>[dry-run]</info> ' . $templateSlug . ' -> buildiq-' . $templateSlug);
				++$published;
				continue;
			}

			$result = $this->publishOne(
				template: $template,
				credentialId: $credentialId,
				org: $org,
				user: $user,
				visibility: $visibility
			);
			$outcome = (string)($result['outcome'] ?? 'unknown_error');

			if ($outcome === GitHubAppSyncService::OUTCOME_OK) {
				$output->writeln('  <info>OK</info>    ' . $templateSlug . ' -> ' . ((string)($result['repoUrl'] ?? '')));
				++$published;
				continue;
			}

			$output->writeln('  <error>FAIL</error>  ' . $templateSlug . ' -> ' . $outcome);
			++$failed;
		}//end foreach

		$output->writeln('');
		$output->writeln('Published: ' . $published . '  Failed: ' . $failed);

		return $failed;
	}//end publishAll()

	/**
	 * Publish a single template (never-throw — a thrown error is an outcome).
	 *
	 * @param array<string,mixed> $template The template object.
	 * @param string $credentialId The github credential UUID.
	 * @param string|null $org Optional org.
	 * @param string|null $user The acting user UID.
	 * @param string $visibility Repo visibility.
	 *
	 * @return array<string,mixed> The publishTemplate outcome array.
	 */
	private function publishOne(
		array $template,
		string $credentialId,
		?string $org,
		?string $user,
		string $visibility,
	): array {
		try {
			return $this->syncService->publishTemplate(
				template: $template,
				credentialId: $credentialId,
				org: $org,
				repoName: null,
				actingUserId: $user,
				visibility: $visibility
			);
		} catch (Throwable $e) {
			return ['outcome' => 'error: ' . $e->getMessage()];
		}
	}//end publishOne()

	/**
	 * Resolve seeded `application-template` objects from the shared buildiq
	 * register (optionally narrowed to one slug) via the real ObjectService
	 * searchObjectsBySlug API, filtering to the seeded set client-side.
	 *
	 * @param string|null $slug Optional single template slug (null = all seeded templates).
	 *
	 * @return array<int,array<string,mixed>> The seeded template objects (jsonSerialize shape).
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	private function resolveTemplates(?string $slug): array {
		$filters = [];
		if ($slug !== null) {
			$filters['slug'] = $slug;
		}

		try {
			// System context (occ command): seeded templates are Conduction-
			// curated store content, not per-user data.
			$results = $this->objectService->searchObjectsBySlug(
				self::REGISTER_SLUG,
				self::TEMPLATE_SCHEMA,
				$filters,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: template lookup failed: ' . $e->getMessage());
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		$templates = [];
		foreach ($results as $result) {
			$data = $this->normalise(object: $result);
			if ($this->acceptsTemplate(template: $data, slug: $slug) === true) {
				$templates[] = $data;
			}
		}

		return $templates;
	}//end resolveTemplates()

	/**
	 * Whether a resolved row is a seeded template matching the optional slug
	 * filter (client-side re-check; OR slug filtering is not always exact).
	 *
	 * @param array<string,mixed> $template The normalised template object.
	 * @param string|null $slug Optional slug filter (null = any).
	 *
	 * @return bool
	 */
	private function acceptsTemplate(array $template, ?string $slug): bool {
		$flag = ($template['isSeeded'] ?? false);
		$seeded = ($flag === true || in_array((string)$flag, ['1', 'true'], true) === true);
		if ($seeded === false) {
			return false;
		}

		if ($slug !== null && (string)($template['slug'] ?? '') !== $slug) {
			return false;
		}

		return true;
	}//end acceptsTemplate()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end normalise()

	/**
	 * Normalise an option value to a non-empty string or null.
	 *
	 * @param mixed $value The raw option value.
	 *
	 * @return string|null
	 */
	private function optionOrNull(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end optionOrNull()
}//end class
