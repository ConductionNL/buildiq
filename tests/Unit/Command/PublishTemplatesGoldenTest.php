<?php

/**
 * Golden test for the buildiq:templates:publish command.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\PublishTemplates;
use OCA\Buildiq\Service\GitHub\BrokeredRepo;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins the command over option sets, template lookups and publish outcomes:
 * the exit code, the printed lines, every publishTemplate call (by parameter
 * name) and the lookup filters. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Command\PublishTemplates
 * @uses   \OCA\Buildiq\Command\PublishTarget
 * @uses   \OCA\Buildiq\Service\GitHub\BrokerAccess
 * @uses   \OCA\Buildiq\Service\GitHub\BrokeredRepo
 * @uses   \OCA\Buildiq\Service\GitHub\PushTarget
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class PublishTemplatesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/publish-templates.golden.json';

	/**
	 * Option sets, by name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const OPTIONS = [
		'no credential' => [],
		'blank credential' => ['--credential' => '  '],
		'bad visibility' => ['--credential' => 'cred-1', '--visibility' => 'internal'],
		'credential only' => ['--credential' => 'cred-1'],
		'padded credential' => ['--credential' => ' cred-2 '],
		'all options' => ['--credential' => 'cred-1', '--org' => 'acme', '--user' => 'alice', '--visibility' => 'private'],
		'blank org and user' => ['--credential' => 'cred-1', '--org' => ' ', '--user' => ''],
		'padded org and user' => ['--credential' => 'cred-1', '--org' => ' acme ', '--user' => ' bob '],
		'slug' => ['--credential' => 'cred-1', '--slug' => 'good', '--user' => 'alice'],
		'blank slug' => ['--credential' => 'cred-1', '--slug' => '  '],
		'dry run' => ['--credential' => 'cred-1', '--dry-run' => true, '--org' => 'acme'],
		'dry run with slug' => ['--credential' => 'cred-1', '--dry-run' => true, '--slug' => 'bad'],
	];

	/**
	 * Template lookups, by name.
	 *
	 * @var array<string,string>
	 */
	private const LOOKUPS = [
		'mixed' => 'mixed',
		'none' => 'none',
		'throws' => 'throws',
		'not a list' => 'not a list',
	];

	/**
	 * Every case of the golden.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::OPTIONS) as $options) {
			foreach ([true, false] as $broker) {
				$cases["$options / broker " . ($broker ? 'on' : 'off')] = [
					['options' => $options, 'broker' => $broker, 'lookup' => 'mixed'],
				];
			}
		}

		foreach (array_keys(self::LOOKUPS) as $lookup) {
			foreach (['credential only', 'dry run', 'slug'] as $options) {
				if ($lookup !== 'mixed') {
					$cases["lookup $lookup / $options"] = [['options' => $options, 'broker' => true, 'lookup' => $lookup]];
				}
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Each case matches its recorded output.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testPublishTemplatesGolden(array $case): void {
		$key = (string)json_encode($case);
		$actual = $this->runCase(case: $case);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (array_key_exists($key, $recorded) === false && getenv('GOLDEN_RECORD') === '1') {
			$recorded[$key] = $actual;
			ksort($recorded);
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE));
			}

			file_put_contents(
				self::FIXTURE,
				json_encode($recorded, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$this->assertArrayHasKey($key, $recorded);
		$this->assertSame($recorded[$key], $actual);
	}//end testPublishTemplatesGolden()

	/**
	 * What one publish was asked to do, under the fixture's names.
	 *
	 * @param array<string,mixed> $template The template.
	 * @param BrokeredRepo        $repo     The repo and access.
	 *
	 * @return array<string,mixed>
	 */
	private static function publishArguments(array $template, BrokeredRepo $repo): array {
		return [
			'template' => $template,
			'credentialId' => $repo->access->credentialId,
			'org' => ($repo->target->org === '' ? null : $repo->target->org),
			'repoName' => ($repo->target->repo === '' ? null : $repo->target->repo),
			'actingUserId' => $repo->access->actingUserId,
			'visibility' => $repo->target->visibility,
		];
	}//end publishArguments()

	/**
	 * Run the command for one case and describe what happened.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return array<string,mixed>
	 */
	private function runCase(array $case): array {
		$trace = (object)['publishes' => [], 'lookups' => [], 'logs' => []];

		$sync = $this->createMock(GitHubAppSyncService::class);
		$sync->method('isBrokerAvailable')->willReturn($case['broker']);
		$sync->method('publishTemplate')->willReturnCallback(
			static function (...$args) use ($trace): array {
				$trace->publishes[] = self::publishArguments(template: $args[0], repo: $args[1]);
				$slug = (string)($args[0]['slug'] ?? '');
				return match ($slug) {
					'good' => ['outcome' => GitHubAppSyncService::OUTCOME_OK, 'repoUrl' => 'https://github.com/acme/buildiq-good'],
					'no-url' => ['outcome' => GitHubAppSyncService::OUTCOME_OK],
					'bad' => ['outcome' => GitHubAppSyncService::OUTCOME_BROKER_DENIED],
					'no-outcome' => [],
					default => throw new RuntimeException('boom ' . $slug),
				};
			}
		);

		$entity = new class implements \JsonSerializable {
			/**
			 * A serialisable OR row.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['slug' => 'no-url', 'isSeeded' => 'true'];
			}
		};

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjectsBySlug')->willReturnCallback(
			static function (...$args) use ($case, $trace, $entity): mixed {
				$trace->lookups[] = [$args[0], $args[1], $args[2]];
				return match ($case['lookup']) {
					'none' => [],
					'throws' => throw new RuntimeException('lookup down'),
					'not a list' => 7,
					default => [
						['slug' => 'good', 'isSeeded' => true],
						['slug' => 'bad', 'isSeeded' => '1'],
						['slug' => 'draft', 'isSeeded' => false],
						$entity,
						['slug' => 'no-outcome', 'isSeeded' => true],
						['slug' => 'explodes', 'isSeeded' => true],
						['isSeeded' => true],
						'not-a-row',
						['slug' => 'flag-zero', 'isSeeded' => 0],
					],
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use ($trace): void {
				$trace->logs[] = (string)preg_replace('/MockObject_\w+/', 'Mock', $message);
			}
		);

		$tester = new CommandTester(new PublishTemplates($sync, $objectService, $logger));
		$exit = $tester->execute(self::OPTIONS[$case['options']]);

		return [
			'exit' => $exit,
			'display' => explode("\n", $tester->getDisplay()),
			'publishes' => $trace->publishes,
			'lookups' => $trace->lookups,
			'logs' => $trace->logs,
		];
	}//end runCase()
}//end class
