<?php

/**
 * Golden test for the skills channel of AppChannelApplier.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AgentChannelProvisioner;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\ChannelProvisioners;
use OCA\Buildiq\Service\ConnectorRegisterAvailability;
use OCA\Buildiq\Service\ContainerLocator;
use OCA\Buildiq\Service\DataRegisterProvisioner;
use OCA\Buildiq\Service\FlowChannelProvisioner;
use OCA\Buildiq\Service\RepoSource;
use OCA\Buildiq\Service\SkillChannelDelegate;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the skills channel over every combination of repo coordinates,
 * template origin, credential scope, hermiq availability and installer
 * outcome: the arguments hermiq's installer receives, how often the
 * installer and the credential are looked up, the skills report, the
 * warnings and the log lines. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\AppChannelApplier
 * @covers \OCA\Buildiq\Service\SkillChannelDelegate
 * @uses   \OCA\Buildiq\Service\RepoSource
 * @uses   \OCA\Buildiq\Service\AgentChannelProvisioner
 * @uses   \OCA\Buildiq\Service\ChannelApplyReport
 * @uses   \OCA\Buildiq\Service\ConnectorRegisterAvailability
 * @uses   \OCA\Buildiq\Service\DataRegisterProvisioner
 * @uses   \OCA\Buildiq\Service\FlowChannelProvisioner
 * @uses   \OCA\Buildiq\Service\ChannelProvisioners
 */
class SkillsChannelGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/skills-channel.golden.json';

	private const SKILLS = [
		'alpha' => ['SKILL.md' => '# alpha'],
		'beta' => ['SKILL.md' => '# beta'],
	];

	/**
	 * Template origins, by name.
	 *
	 * @var array<string,mixed>
	 */
	private const ORIGINS = [
		'origin ok' => ['repo' => 'ConductionNL/example-app'],
		'origin one part' => ['repo' => 'example-app'],
		'origin three parts' => ['repo' => 'a/b/c'],
		'origin empty owner' => ['repo' => '/b'],
		'origin empty repo' => ['repo' => 'a/'],
		'origin not an array' => 'ConductionNL/example-app',
		'no origin' => null,
	];

	/**
	 * Caller-supplied coordinates, by name: [owner, repo].
	 *
	 * @var array<string,array{0:?string,1:?string}>
	 */
	private const COORDINATES = [
		'no coordinates' => [null, null],
		'both' => ['own', 'rep'],
		'owner only' => ['own', null],
		'empty owner' => ['', 'rep'],
		'empty repo' => ['own', ''],
	];

	/**
	 * Credential states, by name: [credentialId, lookup outcome].
	 *
	 * @var array<string,array{0:?string,1:string}>
	 */
	private const CREDENTIALS = [
		'no credential' => [null, 'unused'],
		'empty credential' => ['', 'unused'],
		'scoped for hermiq' => ['cred-1', 'hermiq'],
		'not scoped for hermiq' => ['cred-1', 'buildiq'],
		'credential not found' => ['cred-1', 'null'],
		'lookup throws' => ['cred-1', 'throws'],
		'allowedApps not a list' => ['cred-1', 'string'],
	];

	/**
	 * Installer outcomes, by name.
	 *
	 * @var array<string,mixed>
	 */
	private const INSTALLS = [
		'installs' => ['installed' => 1, 'updated' => 1, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'truncated' => false],
		'partial result' => ['unchanged' => '3', 'failed' => 1, 'truncated' => 1],
		'truncated short' => ['installed' => 1, 'truncated' => true],
		'empty result' => [],
		'throws' => 'throws',
	];

	/**
	 * Every case of the golden.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::ORIGINS as $origin => $_o) {
			foreach (self::COORDINATES as $coords => $_c) {
				foreach (self::CREDENTIALS as $credential => $_cr) {
					$cases["$origin / $coords / $credential"] = [
						[
							'origin' => $origin,
							'coords' => $coords,
							'credential' => $credential,
							'skills' => true,
							'hermiq' => true,
							'install' => 'installs',
							'ref' => 'main',
							'actingUserId' => 'alice',
						],
					];
				}
			}
		}

		$base = [
			'origin' => 'origin ok',
			'coords' => 'no coordinates',
			'credential' => 'scoped for hermiq',
			'skills' => true,
			'hermiq' => true,
			'install' => 'installs',
			'ref' => null,
			'actingUserId' => null,
		];
		foreach (array_keys(self::INSTALLS) as $install) {
			foreach (['scoped for hermiq', 'no credential', 'not scoped for hermiq'] as $credential) {
				$cases["install $install / $credential"] = [array_merge($base, ['install' => $install, 'credential' => $credential])];
			}
		}

		foreach (array_keys(self::CREDENTIALS) as $credential) {
			$cases["hermiq disabled / $credential"] = [array_merge($base, ['hermiq' => false, 'credential' => $credential])];
			$cases["no skills / $credential"] = [array_merge($base, ['skills' => false, 'credential' => $credential])];
			$cases["no skills, no coordinates / $credential"] = [array_merge($base, ['skills' => false, 'origin' => 'no origin', 'credential' => $credential])];
		}

		$cases['ref and user only'] = [array_merge($base, ['ref' => 'v1.2', 'actingUserId' => 'bob', 'credential' => 'no credential'])];
		$cases['user only, caller coordinates'] = [array_merge($base, ['actingUserId' => 'carol', 'coords' => 'both'])];

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
	public function testSkillsChannelGolden(array $case): void {
		$key = $this->caseKey(case: $case);
		$actual = $this->applyCase(case: $case);

		$recorded = [];
		if (file_exists(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (array_key_exists($key, $recorded) === false && getenv('GOLDEN_RECORD') === '1') {
			$recorded[$key] = $actual;
			ksort($recorded);
			file_put_contents(
				self::FIXTURE,
				json_encode($recorded, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$this->assertArrayHasKey($key, $recorded);
		$this->assertSame($recorded[$key], $actual);
	}//end testSkillsChannelGolden()

	/**
	 * The fixture key of a case.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return string
	 */
	private function caseKey(array $case): string {
		return (string)json_encode($case);
	}//end caseKey()

	/**
	 * Apply one case and describe what happened.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return array<string,mixed>
	 */
	private function applyCase(array $case): array {
		$trace = (object)['installs' => [], 'locatorGets' => 0, 'finds' => [], 'logs' => [], 'enabledChecks' => []];

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static function (string $app) use ($case, $trace): bool {
				$trace->enabledChecks[] = $app;
				return $app !== 'hermiq' || $case['hermiq'] === true;
			}
		);

		$installer = new class($trace, self::INSTALLS[$case['install']]) {
			/**
			 * Recording installer double.
			 *
			 * @param object $trace The trace to write into.
			 * @param mixed $outcome The scripted outcome.
			 */
			public function __construct(
				private object $trace,
				private mixed $outcome,
			) {
			}

			/**
			 * Hermiq's installFromRepo, recorded by parameter name.
			 *
			 * @param string $owner Repo owner.
			 * @param string $repo Repo name.
			 * @param string|null $ref Git ref.
			 * @param string|null $actingUserId Session UID.
			 * @param string|null $credentialId Credential UUID.
			 *
			 * @return array<string,mixed>
			 */
			public function installFromRepo(
				string $owner,
				string $repo,
				?string $ref = null,
				?string $actingUserId = null,
				?string $credentialId = null,
			): array {
				$this->trace->installs[] = compact('owner', 'repo', 'ref', 'actingUserId', 'credentialId');
				if ($this->outcome === 'throws') {
					throw new RuntimeException('install failed');
				}

				return $this->outcome;
			}
		};

		$locator = $this->createMock(ContainerLocator::class);
		$locator->method('get')->willReturnCallback(
			static function (string $className) use ($trace, $installer): object {
				$trace->locatorGets++;
				$trace->logs[] = 'get ' . $className;
				return $installer;
			}
		);

		[$credentialId, $lookup] = self::CREDENTIALS[$case['credential']];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (...$args) use ($lookup, $trace): ?ObjectEntityInterface {
				$trace->finds[] = $args[0];
				if ($lookup === 'throws') {
					throw new RuntimeException('broker unavailable');
				}

				if ($lookup === 'null') {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$allowed = ['buildiq', $lookup];
				if ($lookup === 'string') {
					$allowed = 'hermiq';
				}

				$entity->method('getObject')->willReturn(['allowedApps' => $allowed]);
				return $entity;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message) use ($level, $trace): void {
					$trace->logs[] = $level . ' ' . $message;
				}
			);
		}

		$applier = new AppChannelApplier(
			objectService: $objectService,
			connectorRegister: new ConnectorRegisterAvailability(new FakeSlugResolver(['integriq']), $appManager, $logger),
			provisioners: new ChannelProvisioners(
				registerProvisioner: new DataRegisterProvisioner(
					$this->createMock(RegisterMapper::class),
					$this->createMock(SchemaMapper::class),
					$logger
				),
				skillDelegate: new SkillChannelDelegate($appManager, $locator, $logger),
				flowProvisioner: new FlowChannelProvisioner($this->createMock(FlowService::class), $objectService, $logger),
				agentProvisioner: new AgentChannelProvisioner($objectService, $logger)
			),
			logger: $logger,
		);

		$template = ['channels' => []];
		$origin = self::ORIGINS[$case['origin']];
		if ($origin !== null) {
			$template['templateOrigin'] = $origin;
		}

		if ($case['skills'] === true) {
			$template['channels']['skills'] = self::SKILLS;
		}

		[$owner, $repo] = self::COORDINATES[$case['coords']];
		$error = null;
		$report = [];
		try {
			$report = $applier->apply(
				template: $template,
				source: new RepoSource(owner: (string)$owner, repo: (string)$repo, ref: $case['ref'], credentialId: $credentialId),
				actingUserId: $case['actingUserId'],
			);
		} catch (RuntimeException $e) {
			$error = $e->getMessage();
		}

		return [
			'error' => $error,
			'skills' => ($report['channels']['skills'] ?? null),
			'warnings' => ($report['warnings'] ?? null),
			'reportKeys' => array_keys($report),
			'installs' => $trace->installs,
			'locatorGets' => $trace->locatorGets,
			'finds' => $trace->finds,
			'enabledChecks' => $trace->enabledChecks,
			'logs' => $trace->logs,
		];
	}//end applyCase()
}//end class
