<?php

/**
 * Golden test for the wizard's creation flow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\WizardCreationException;
use OCA\Buildiq\Service\ApplicationCreationService;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\SlugValidator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\LockRequest;
use OCA\OpenRegister\Service\Object\UnlockRequest;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Pins every collaborator call createApplication() makes, the result, and the
 * thrown WizardCreationException, for the success paths and a failure at each
 * step (including the rollback it triggers).
 *
 * Calls are recorded by parameter name, leaving out arguments at their default.
 * Set BUILDIQ_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\Buildiq\Service\ApplicationCreationService
 * @uses   \OCA\Buildiq\Service\SlugValidator
 * @uses   \OCA\Buildiq\Exception\WizardCreationException
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 * @uses   \OCA\Buildiq\Service\RegisterStore
 */
class ApplicationCreationGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/application-creation.golden.json';

	/**
	 * Recorded collaborator calls of the running scenario.
	 *
	 * @var array<int,mixed>
	 */
	private array $calls = [];

	/**
	 * Faults of the running scenario: "<collaborator>.<method>#<nth call>" => message.
	 *
	 * @var array<string,string>
	 */
	private array $faults = [];

	/**
	 * Calls seen so far per "<collaborator>.<method>".
	 *
	 * @var array<string,int>
	 */
	private array $counts = [];

	/**
	 * Scenario overrides of the running scenario.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = [];

	/**
	 * Scenarios: payload, faults and options.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,string>,2:array<string,mixed>}>
	 */
	public static function scenarios(): array {
		$single = ['name' => 'Permit tracker', 'slug' => 'permit-tracker', 'description' => 'Tracks permits', 'preset' => 'single'];
		$devProd = ['name' => 'Permit tracker', 'slug' => 'permit-tracker', 'preset' => 'dev-prod'];
		$three = ['name' => 'Permit tracker', 'slug' => 'permit-tracker', 'preset' => 'dev-staging-prod'];
		return [
			'success single' => [$single, [], []],
			'success dev-prod' => [$devProd, [], []],
			'success dev-staging-prod' => [$three, [], []],
			'success custom versions' => [
				['name' => 'Custom', 'slug' => 'custom-app', 'versions' => [['name' => 'Alpha', 'slug' => 'alpha'], ['name' => 'Beta', 'slug' => 'beta']]],
				[],
				[],
			],
			'no user session' => [$single, [], ['noUser' => true]],
			'lock taken' => [$single, ['objectService.lockObject#1' => 'locked'], []],
			'invalid slug' => [['name' => 'X', 'slug' => 'Not A Slug', 'preset' => 'single'], [], []],
			'empty slug' => [['name' => 'X', 'slug' => '', 'preset' => 'single'], [], []],
			'slug already exists' => [$single, [], ['existingRows' => [['id' => 'other']]]],
			'slug check fails' => [$single, ['objectService.searchObjects#1' => 'db down'], []],
			'app save duplicate' => [$single, ['objectService.saveObject#1' => 'SQLSTATE: Duplicate entry'], []],
			'app save unique constraint' => [$single, ['objectService.saveObject#1' => 'violates unique constraint'], []],
			'app save UNIQUE' => [$single, ['objectService.saveObject#1' => 'UNIQUE failed'], []],
			'app save duplicate lower' => [$single, ['objectService.saveObject#1' => 'duplicate key'], []],
			'app save other error' => [$single, ['objectService.saveObject#1' => 'DB unavailable'], []],
			'app save without uuid' => [$single, [], ['appWithoutUuid' => true]],
			'second version save fails' => [$devProd, ['objectService.saveObject#3' => 'version write failed'], []],
			'first register provision fails' => [$devProd, ['registerMapper.createFromArray#1' => 'register failed'], []],
			'second register provision fails' => [$three, ['registerMapper.createFromArray#2' => 'register failed'], []],
			'first chain wiring fails' => [$three, ['objectService.saveObject#5' => 'wire failed'], []],
			'chain wiring fails' => [$three, ['objectService.saveObject#6' => 'wire failed'], []],
			'production version fails' => [$devProd, ['objectService.saveObject#5' => 'pointer failed'], []],
			'route publish fails' => [$devProd, ['objectService.saveObject#6' => 'route failed'], []],
			'route without uuid' => [$single, [], ['routeWithoutUuid' => true]],
			'rollback with orphans' => [
				$devProd,
				[
					'objectService.saveObject#6' => 'route failed',
					'objectService.deleteObject#1' => 'delete failed',
					'registerService.delete#2' => 'delete failed',
				],
				[],
			],
			'unlock fails after success' => [$single, ['objectService.unlockObject#1' => 'unlock failed'], []],
			'unlock fails after failure' => [
				$single,
				['objectService.saveObject#1' => 'DB unavailable', 'objectService.unlockObject#1' => 'unlock failed'],
				[],
			],
		];
	}//end scenarios()

	/**
	 * The recorded run of every scenario equals the golden fixture.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesGolden(): void {
		$runs = [];
		foreach (self::scenarios() as $name => [$payload, $faults, $options]) {
			$runs[$name] = $this->runScenario(payload: $payload, setup: ['faults' => $faults, 'options' => $options]);
		}

		$json = json_encode($runs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('BUILDIQ_GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, $json);
		}

		self::assertSame(expected: file_get_contents(self::FIXTURE), actual: $json);
	}//end testEveryScenarioMatchesGolden()

	/**
	 * Run one scenario and describe what happened.
	 *
	 * @param array<string,mixed> $payload The wizard payload.
	 * @param array<string,array<string,mixed>> $setup The faults and options.
	 *
	 * @return array<string,mixed>
	 */
	private function runScenario(array $payload, array $setup): array {
		$this->calls = [];
		$this->counts = [];
		$this->faults = $setup['faults'];
		$this->options = $setup['options'];

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if (($this->options['noUser'] ?? false) === false) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$userSession->method('getUser')->willReturn($user);

		$service = new ApplicationCreationService(
			logger: $this->recorder(class: LoggerInterface::class, label: 'logger'),
			store: new RegisterStore(
				objectService: $this->recorder(class: ObjectServiceInterface::class, label: 'objectService'),
				registerMapper: $this->recorder(class: RegisterMapper::class, label: 'registerMapper'),
				schemaMapper: $this->recorder(class: SchemaMapper::class, label: 'schemaMapper')
			),
			registerService: $this->recorder(class: RegisterService::class, label: 'registerService'),
			userSession: $userSession,
			slugValidator: new SlugValidator(),
		);

		$outcome = [];
		try {
			$outcome['result'] = $service->createApplication($payload);
		} catch (WizardCreationException $e) {
			$outcome['exception'] = [
				'errorCode' => $e->getErrorCode(),
				'failedAtStep' => $e->getFailedAtStep(),
				'message' => $e->getMessage(),
				'rollbackStatus' => $e->getRollbackStatus(),
				'orphaned' => $e->getOrphanedResources(),
				'previous' => $this->describeThrowable(error: $e->getPrevious()),
			];
		} catch (Throwable $e) {
			$outcome['otherException'] = $this->describeThrowable(error: $e);
		}

		$outcome['calls'] = $this->calls;
		return $outcome;
	}//end runScenario()

	/**
	 * A mock whose every public method records its call and answers from respond().
	 *
	 * @param string $class The class or interface to mock.
	 * @param string $label The collaborator label used in the recording.
	 *
	 * @return mixed
	 */
	private function recorder(string $class, string $label): mixed {
		$mock = $this->createMock(originalClassName: $class);
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isStatic() === true || $method->isConstructor() === true || $method->isFinal() === true) {
				continue;
			}

			$name = $method->getName();
			if (str_starts_with($name, '__') === true) {
				continue;
			}

			$params = $method->getParameters();
			$mock->method($name)->willReturnCallback(
				fn (...$args) => $this->respond(call: $label . '.' . $name, named: $this->passedArguments(params: $params, args: $args))
			);
		}

		return $mock;
	}//end recorder()

	/**
	 * Record one call, apply its fault, and answer it.
	 *
	 * @param string $call "<collaborator>.<method>".
	 * @param array<string,mixed> $named The passed arguments.
	 *
	 * @return mixed
	 */
	private function respond(string $call, array $named): mixed {
		$this->counts[$call] = ($this->counts[$call] ?? 0) + 1;
		$nth = $this->counts[$call];
		$this->calls[] = [$call . '#' . $nth => $this->describe(value: $named)];

		$fault = ($this->faults[$call . '#' . $nth] ?? null);
		if ($fault !== null) {
			throw new RuntimeException($fault);
		}

		return $this->answer(call: $call, named: $named);
	}//end respond()

	/**
	 * The scripted answer of one collaborator call.
	 *
	 * @param string $call "<collaborator>.<method>".
	 * @param array<string,mixed> $named The passed arguments.
	 *
	 * @return mixed
	 */
	private function answer(string $call, array $named): mixed {
		$nth = $this->counts[$call];
		switch ($call) {
			case 'objectService.searchObjects':
				return ($this->options['existingRows'] ?? []);
			case 'objectService.lockObject':
				return [];
			case 'objectService.unlockObject':
			case 'objectService.deleteObject':
				return true;
			case 'objectService.saveObject':
				return $this->savedEntity(object: $named['object'], nth: $nth);
			case 'registerMapper.find':
			case 'registerMapper.createFromArray':
			case 'registerMapper.update':
				return $this->entity(class: Register::class, id: 10 + $nth);
			case 'registerService.delete':
				return $named['register'];
			case 'schemaMapper.find':
			case 'schemaMapper.createFromArray':
				return $this->entity(class: Schema::class, id: 100 + $nth);
			default:
				return null;
		}
	}//end answer()

	/**
	 * The entity OpenRegister answers a save with.
	 *
	 * @param array<string,mixed> $object The saved object.
	 * @param int $nth Which save this is.
	 *
	 * @return ObjectEntity
	 */
	private function savedEntity(array $object, int $nth): ObjectEntity {
		$uuid = 'uuid-' . $nth;
		if ($nth === 1 && ($this->options['appWithoutUuid'] ?? false) === true) {
			$uuid = '';
		}

		if (array_key_exists('applicationUuid', $object) === true && ($this->options['routeWithoutUuid'] ?? false) === true) {
			$uuid = '';
		}

		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(array_merge($object, ['id' => $uuid]));
		return $entity;
	}//end savedEntity()

	/**
	 * A Register or Schema mock with an id and no schemas.
	 *
	 * @param string $class Register or Schema.
	 * @param int $id The id.
	 *
	 * @return mixed
	 */
	private function entity(string $class, int $id): mixed {
		$methods = ['getId'];
		if ($class === Register::class) {
			$methods = ['getId', 'getSchemas', 'setSchemas'];
		}

		$mock = $this->getMockBuilder(className: $class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
		$mock->method('getId')->willReturn($id);
		if ($class === Register::class) {
			$mock->method('getSchemas')->willReturn([]);
			$mock->method('setSchemas')->willReturnCallback(
				function (array $schemas) use ($id, &$mock): mixed {
					$this->calls[] = ['register-' . $id . '.setSchemas' => $schemas];
					return $mock;
				}
			);
		}

		return $mock;
	}//end entity()

	/**
	 * Key the arguments by parameter name, leaving out those left at their default.
	 *
	 * @param array<int,\ReflectionParameter> $params The method's parameters.
	 * @param array<int,mixed> $args The arguments the mock received.
	 *
	 * @return array<string,mixed>
	 */
	private function passedArguments(array $params, array $args): array {
		$named = [];
		foreach ($args as $index => $value) {
			$param = ($params[$index] ?? null);
			if ($param === null) {
				$named['#' . $index] = $value;
				continue;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $value) {
				continue;
			}

			$named[$param->getName()] = $value;
		}

		return $named;
	}//end passedArguments()

	/**
	 * A JSON-safe description; large values are reduced to a hash.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function describe(mixed $value): mixed {
		if ($value instanceof Throwable) {
			return $this->describeThrowable(error: $value);
		}

		if (is_object($value) === true) {
			$class = get_class($value);
			if ($value instanceof MockObject) {
				$class = get_parent_class($value);
			}

			if (method_exists($value, 'getId') === true) {
				return $class . '#' . $value->getId();
			}

			if ($value instanceof LockRequest || $value instanceof UnlockRequest) {
				return ['object:' . $class => $this->describe(value: get_object_vars($value))];
			}

			return 'object:' . $class;
		}

		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = $this->describe(value: $item);
			$encoded = json_encode($out[$key], JSON_UNESCAPED_SLASHES);
			if (is_array($out[$key]) === true && is_string($encoded) === true && strlen($encoded) > 400) {
				$out[$key] = 'sha1:' . sha1($encoded);
			}
		}

		return $out;
	}//end describe()

	/**
	 * Class and message of a throwable, or null.
	 *
	 * @param Throwable|null $error The throwable.
	 *
	 * @return array<string,string>|null
	 */
	private function describeThrowable(?Throwable $error): ?array {
		if ($error === null) {
			return null;
		}

		return ['class' => get_class($error), 'message' => $error->getMessage()];
	}//end describeThrowable()
}//end class
