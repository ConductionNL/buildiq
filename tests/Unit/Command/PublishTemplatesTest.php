<?php

/**
 * Unit tests for the buildiq:templates:publish command.
 *
 * Covers the store-seed command's template resolution and publish loop: seeded
 * `application-template` objects are read via the real ObjectService
 * searchObjectsBySlug API, non-seeded rows are filtered out, an optional slug
 * narrows the set, dry-run lists without calling GitHub, and a single template
 * failure is reported without aborting the rest (non-zero exit on any failure).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Command
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

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\PublishTemplates;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the PublishTemplates occ command.
 */
class PublishTemplatesTest extends TestCase {
	/**
	 * Mock GitHub sync service.
	 *
	 * @var GitHubAppSyncService&MockObject
	 */
	private GitHubAppSyncService&MockObject $syncService;

	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The command under test.
	 *
	 * @var PublishTemplates
	 */
	private PublishTemplates $command;

	/**
	 * Set up the command with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->syncService = $this->createMock(GitHubAppSyncService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$this->command = new PublishTemplates(
			$this->syncService,
			$this->objectService,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * --credential is required.
	 *
	 * @return void
	 */
	public function testMissingCredentialIsInvalid(): void {
		$tester = new CommandTester($this->command);
		$exit = $tester->execute([]);

		$this->assertSame(Command::INVALID, $exit);
		$this->assertStringContainsString('--credential is required', $tester->getDisplay());
	}//end testMissingCredentialIsInvalid()

	/**
	 * A dry-run resolves + lists only seeded templates and calls no GitHub path.
	 *
	 * @return void
	 */
	public function testDryRunListsOnlySeededTemplates(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturn(
			[
				['slug' => 'permit-tracker', 'isSeeded' => true],
				['slug' => 'my-draft', 'isSeeded' => false],
				['slug' => 'incident-reporter', 'isSeeded' => true],
				['slug' => 'no-flag'],
			]
		);
		$this->syncService->expects($this->never())->method('publishTemplate');

		$tester = new CommandTester($this->command);
		$exit = $tester->execute(['--credential' => 'cred-1', '--dry-run' => true]);

		$display = $tester->getDisplay();
		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('permit-tracker', $display);
		$this->assertStringContainsString('incident-reporter', $display);
		$this->assertStringNotContainsString('my-draft', $display);
		$this->assertStringNotContainsString('no-flag', $display);
		$this->assertStringContainsString('Published: 2  Failed: 0', $display);
	}//end testDryRunListsOnlySeededTemplates()

	/**
	 * The slug option is forwarded to the OR query filter.
	 *
	 * @return void
	 */
	public function testSlugNarrowsTheOrQuery(): void {
		$this->objectService->expects($this->once())
			->method('searchObjectsBySlug')
			->with(
				'buildiq',
				'application-template',
				['slug' => 'incident-reporter'],
				false,
				false
			)
			->willReturn(
				[
					['slug' => 'incident-reporter', 'isSeeded' => true],
				]
			);

		$tester = new CommandTester($this->command);
		$exit = $tester->execute(['--credential' => 'cred-1', '--slug' => 'incident-reporter', '--dry-run' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
	}//end testSlugNarrowsTheOrQuery()

	/**
	 * A single template failure is reported and yields a non-zero exit, but the
	 * rest still publish.
	 *
	 * @return void
	 */
	public function testPartialFailureReportsAndExitsNonZero(): void {
		$this->syncService->method('isBrokerAvailable')->willReturn(true);
		$this->objectService->method('searchObjectsBySlug')->willReturn(
			[
				['slug' => 'good', 'isSeeded' => true],
				['slug' => 'bad', 'isSeeded' => true],
			]
		);

		$this->syncService->method('publishTemplate')->willReturnCallback(
			static function (array $template): array {
				if (($template['slug'] ?? '') === 'good') {
					return ['outcome' => GitHubAppSyncService::OUTCOME_OK, 'repoUrl' => 'https://github.com/acme/buildiq-good'];
				}

				return ['outcome' => GitHubAppSyncService::OUTCOME_BROKER_DENIED];
			}
		);

		$tester = new CommandTester($this->command);
		$exit = $tester->execute(['--credential' => 'cred-1', '--user' => 'alice']);

		$display = $tester->getDisplay();
		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('https://github.com/acme/buildiq-good', $display);
		$this->assertStringContainsString('broker_denied', $display);
		$this->assertStringContainsString('Published: 1  Failed: 1', $display);
	}//end testPartialFailureReportsAndExitsNonZero()

	/**
	 * With no seeded templates, the command succeeds with a friendly notice.
	 *
	 * @return void
	 */
	public function testNoSeededTemplatesSucceeds(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturn([]);

		$tester = new CommandTester($this->command);
		$exit = $tester->execute(['--credential' => 'cred-1', '--dry-run' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('No seeded application-templates found', $tester->getDisplay());
	}//end testNoSeededTemplatesSucceeds()
	/**
	 * REQ-BQIC-008 "An administrator publishes the seeded templates": each seeded template is published once
	 * and the command prints one line per template with its outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-publish-templates-from-the-command-line-req-bqic-008
	 */
	public function testEverySeededTemplateIsPublishedWithOneLineEach(): void {
		$this->syncService->method('isBrokerAvailable')->willReturn(true);
		$this->objectService->method('searchObjectsBySlug')->willReturn(
			[
				['slug' => 'vergunningen', 'isSeeded' => true],
				['slug' => 'meldingen', 'isSeeded' => true],
			]
		);
		$published = [];
		$this->syncService->expects($this->exactly(2))->method('publishTemplate')->willReturnCallback(
			static function (array $template) use (&$published): array {
				$published[] = $template['slug'];
				return ['outcome' => GitHubAppSyncService::OUTCOME_OK, 'repoUrl' => 'https://github.com/acme/buildiq-' . $template['slug']];
			}
		);

		$tester = new CommandTester($this->command);
		$exit = $tester->execute(['--credential' => 'cred-1', '--user' => 'alice']);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertSame(['vergunningen', 'meldingen'], $published);
		$lines = array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())), static fn (string $line): bool => str_starts_with($line, 'OK')));
		$this->assertSame(
			[
				'OK    vergunningen -> https://github.com/acme/buildiq-vergunningen',
				'OK    meldingen -> https://github.com/acme/buildiq-meldingen',
			],
			$lines
		);
		$this->assertStringContainsString('Published: 2  Failed: 0', $tester->getDisplay());
	}//end testEverySeededTemplateIsPublishedWithOneLineEach()

	/**
	 * REQ-BQIC-008 "No credential": the command exits with an error naming the missing credential and publishes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-publish-templates-from-the-command-line-req-bqic-008
	 */
	public function testWithoutACredentialNothingIsPublished(): void {
		$this->syncService->expects($this->never())->method('publishTemplate');
		$this->syncService->expects($this->never())->method('isBrokerAvailable');
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$tester = new CommandTester($this->command);
		$exit = $tester->execute(['--credential' => '  ', '--user' => 'alice']);

		$this->assertNotSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('--credential is required (the github broker credential UUID).', $tester->getDisplay());
	}//end testWithoutACredentialNothingIsPublished()
}//end class
