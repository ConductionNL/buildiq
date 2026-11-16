<?php

/**
 * Buildiq ExportJobPipeline unit tests
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\BackgroundJob
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

namespace OCA\Buildiq\Tests\Unit\BackgroundJob;

use OCA\Buildiq\BackgroundJob\ExportJobPipeline;
use OCA\Buildiq\Kernel\Contracts\ExportAppContentBundlerInterface;
use OCA\Buildiq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ExportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubPushServiceInterface;
use OCA\Buildiq\Kernel\Values\BrokerAccess;
use OCA\Buildiq\Kernel\Values\ExportContents;
use OCA\Buildiq\Kernel\Values\ExportTarget;
use OCA\Buildiq\Kernel\Values\PushTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see ExportJobPipeline}.
 */
#[CoversClass(ExportJobPipeline::class)]
#[UsesClass(ExportTarget::class)]
#[UsesClass(ExportContents::class)]
#[UsesClass(BrokerAccess::class)]
#[UsesClass(PushTarget::class)]
final class ExportJobPipelineTest extends TestCase {
	/**
	 * File-generation mock.
	 *
	 * @var ExportServiceInterface&MockObject
	 */
	private ExportServiceInterface&MockObject $exportService;

	/**
	 * Job record mock.
	 *
	 * @var ExportJobServiceInterface&MockObject
	 */
	private ExportJobServiceInterface&MockObject $exportJobService;

	/**
	 * GitHub delivery mock.
	 *
	 * @var GitHubPushServiceInterface&MockObject
	 */
	private GitHubPushServiceInterface&MockObject $githubPushService;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->exportService = $this->createMock(ExportServiceInterface::class);
		$this->exportJobService = $this->createMock(ExportJobServiceInterface::class);
		$this->githubPushService = $this->createMock(GitHubPushServiceInterface::class);
	}//end setUp()

	/**
	 * The pipeline under test.
	 *
	 * @param ExportAppContentBundlerInterface|null $bundler Optional bundler.
	 *
	 * @return ExportJobPipeline
	 */
	private function pipeline(?ExportAppContentBundlerInterface $bundler = null): ExportJobPipeline {
		return new ExportJobPipeline(
			exportService: $this->exportService,
			exportJobService: $this->exportJobService,
			githubPushService: $this->githubPushService,
			logger: new NullLogger(),
			contentBundler: $bundler
		);
	}//end pipeline()

	/**
	 * A ZIP export builds the target and context from the record and succeeds with a download URL.
	 *
	 * @return void
	 */
	public function testZipExportGeneratesAndSucceeds(): void {
		$this->exportJobService->method('loadJob')->willReturn([
			'applicationUuid' => 'app-1',
			'applicationVersion' => '1.2.0',
			'applicationSlug' => 'my-virtual-app',
			'flows' => ['f1'],
			'dataRegisters' => 'not-a-list',
		]);
		$this->exportService->expects($this->once())
			->method('generateAppZip')
			->with(
				$this->callback(
					static fn (ExportTarget $t): bool => $t->applicationUuid === 'app-1'
						&& $t->versionSlug === '1.2.0'
						&& $t->jobUuid === 'job-1'
						&& $t->applicationSlug === 'my-virtual-app'
				),
				$this->callback(
					static fn (ExportContents $c): bool => $c->context['appNamespace'] === 'MyVirtualApp'
						&& $c->context['appName'] === 'My Virtual App'
						&& $c->context['license'] === 'EUPL-1.2'
						&& $c->flows === ['f1']
						&& $c->dataRegisters === []
						&& $c->source === null
				),
				false
			)
			->willReturn('/tmp/x.zip');
		$this->exportService->method('lastSkipped')->willReturn(['schema-x']);
		$this->exportService->method('lastContent')->willReturn(null);
		$this->githubPushService->expects($this->never())->method('push');
		$this->exportJobService->expects($this->once())
			->method('transitionJob')
			->with(
				'job-1',
				'succeed',
				[
					'downloadUrl' => '/index.php/apps/buildiq/api/exports/job-1/download',
					'skipped' => ['schema-x'],
				]
			)
			->willReturn(true);

		$this->pipeline()->execute(jobUuid: 'job-1');
	}//end testZipExportGeneratesAndSucceeds()

	/**
	 * A record that cannot be loaded fails before anything is generated.
	 *
	 * @return void
	 */
	public function testMissingRecordThrows(): void {
		$this->exportJobService->method('loadJob')->willReturn(null);
		$this->exportService->expects($this->never())->method('generateAppZip');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not load ExportJob record for UUID job-2');
		$this->pipeline()->execute(jobUuid: 'job-2');
	}//end testMissingRecordThrows()

	/**
	 * A GitHub export without a broker credential fails and never succeeds.
	 *
	 * @return void
	 */
	public function testGitHubExportWithoutCredentialThrows(): void {
		$this->exportJobService->method('loadJob')->willReturn(['applicationUuid' => 'app-1', 'target' => 'github']);
		$this->exportService->method('lastSkipped')->willReturn([]);
		$this->exportJobService->expects($this->never())->method('transitionJob');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('needs a broker credential');
		$this->pipeline()->execute(jobUuid: 'job-3');
	}//end testGitHubExportWithoutCredentialThrows()

	/**
	 * A GitHub export pushes as the requesting user and records the repository and pull request.
	 *
	 * @return void
	 */
	public function testGitHubExportPushesAndRecordsUrls(): void {
		$this->exportJobService->method('loadJob')->willReturn([
			'applicationUuid' => 'app-1',
			'target' => 'github',
			'githubCredentialId' => 'cred-1',
			'owner' => 'alice',
			'githubOrg' => 'acme',
			'githubRepo' => 'demo',
		]);
		$this->exportService->method('lastSkipped')->willReturn([]);
		$this->exportService->method('lastContent')->willReturn(['pages' => 2, 'menu' => 1, 'schemas' => 3, 'records' => 4]);
		$this->exportService->method('scratchTreeDir')->with('job-4')->willReturn('/tmp/tree');
		$this->githubPushService->expects($this->once())
			->method('push')
			->with(
				'job-4',
				'/tmp/tree',
				$this->callback(static fn (BrokerAccess $a): bool => $a->credentialId === 'cred-1' && $a->actingUserId === 'alice'),
				$this->callback(static fn (PushTarget $t): bool => $t->org === 'acme' && $t->repo === 'demo' && $t->visibility === 'private')
			)
			->willReturn(['repoUrl' => 'https://github.com/acme/demo', 'pullRequestUrl' => '']);
		$this->exportJobService->expects($this->once())
			->method('transitionJob')
			->with(
				'job-4',
				'succeed',
				[
					'downloadUrl' => '/index.php/apps/buildiq/api/exports/job-4/download',
					'githubRepoUrl' => 'https://github.com/acme/demo',
					'log' => ['Exported 2 pages, 1 menu items, 3 schemas and 4 records.'],
				]
			)
			->willReturn(true);

		$this->pipeline()->execute(jobUuid: 'job-4');
	}//end testGitHubExportPushesAndRecordsUrls()

	/**
	 * With a bundler, an application without a version fails the export.
	 *
	 * @return void
	 */
	public function testSourceWithoutVersionThrows(): void {
		$bundler = $this->createMock(ExportAppContentBundlerInterface::class);
		$bundler->method('resolveSource')->willReturn(['application' => ['name' => 'X'], 'version' => []]);
		$this->exportJobService->method('loadJob')->willReturn(['applicationUuid' => 'app-1']);
		$this->exportService->expects($this->never())->method('generateAppZip');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The application has no version to export.');
		$this->pipeline(bundler: $bundler)->execute(jobUuid: 'job-5');
	}//end testSourceWithoutVersionThrows()
}//end class
