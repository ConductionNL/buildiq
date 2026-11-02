<?php

/**
 * Golden test for the composition root's registrations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\AppInfo;

use OCA\Buildiq\AppInfo\Application;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Pins every registration Application::register() makes, in order.
 *
 * Order matters: Nextcloud's container keeps the LAST registration of a name,
 * and the concrete Buildiq bindings must follow the generic AppHost plumbing.
 * Calls made from inside OpenRegister's Bootstrap, and the registrations
 * guarded on an OpenRegister event class, depend on which OpenRegister is
 * loaded, so they are asserted separately against class_exists().
 *
 * Set BUILDIQ_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\Buildiq\AppInfo\Application
 * @uses   \OCA\Buildiq\AppInfo\OpenRegisterAutoloader
 */
class ApplicationRegisterTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/application-register.golden.json';

	/**
	 * Event classes whose listener registration is guarded on class_exists().
	 *
	 * @var array<int,string>
	 */
	private const GUARDED_EVENTS = [
		'OCA\\OpenRegister\\Event\\RegisterLeafProvidersEvent',
		'OCA\\OpenRegister\\Event\\FormDestinationDependentsEvent',
	];

	/**
	 * Registrations recorded from the context mock.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * The recorded sequence equals the golden fixture.
	 *
	 * @return void
	 */
	public function testRegisterMatchesGolden(): void {
		$this->runRegister();

		$stable = array_values(
			array_filter(
				$this->calls,
				fn (array $call): bool => in_array(($call['args']['event'] ?? null), self::GUARDED_EVENTS, true) === false
			)
		);
		$json = json_encode($stable, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		if (getenv('BUILDIQ_GOLDEN_RECORD') === '1') {
			if (is_dir(dirname(self::FIXTURE)) === false) {
				mkdir(dirname(self::FIXTURE), 0777, true);
			}

			file_put_contents(self::FIXTURE, $json);
		}

		self::assertSame(expected: file_get_contents(self::FIXTURE), actual: $json);
	}//end testRegisterMatchesGolden()

	/**
	 * Each guarded listener is registered exactly when its event class exists.
	 *
	 * @return void
	 */
	public function testGuardedListenersFollowTheirEventClass(): void {
		$this->runRegister();

		foreach (self::GUARDED_EVENTS as $event) {
			$found = array_filter(
				$this->calls,
				static fn (array $call): bool => ($call['args']['event'] ?? null) === $event
			);
			self::assertCount(expectedCount: (class_exists($event) === true) ? 1 : 0, haystack: $found, message: $event);
		}
	}//end testGuardedListenersFollowTheirEventClass()

	/**
	 * Run register() on an Application built without its container.
	 *
	 * @return void
	 */
	private function runRegister(): void {
		$this->calls = [];

		$context = $this->createMock(IRegistrationContext::class);
		foreach ((new ReflectionClass(IRegistrationContext::class))->getMethods() as $method) {
			$name = $method->getName();
			$params = $method->getParameters();
			$context->method($name)->willReturnCallback(
				function (...$args) use ($name, $params): void {
					$this->record(method: $name, named: $this->passedArguments(params: $params, args: $args));
				}
			);
		}

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);
	}//end runRegister()

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
			$param = $params[$index];
			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $value) {
				continue;
			}

			$named[$param->getName()] = $value;
		}

		return $named;
	}//end passedArguments()

	/**
	 * Record one context call by its passed arguments.
	 *
	 * @param string $method The context method.
	 * @param array<string,mixed> $named The passed arguments keyed by parameter name.
	 *
	 * @return void
	 */
	private function record(string $method, array $named): void {
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
			if (($frame['class'] ?? '') === Bootstrap::class) {
				return;
			}
		}

		$args = [];
		foreach ($named as $key => $value) {
			$args[$key] = $this->describe(value: $value);
		}

		$this->calls[] = ['method' => $method, 'args' => $args];
	}//end record()

	/**
	 * Describe one argument; a factory closure is described by its return type.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return mixed
	 */
	private function describe(mixed $value): mixed {
		if ($value instanceof \Closure) {
			$type = (new ReflectionFunction($value))->getReturnType();
			return 'factory:' . (($type instanceof ReflectionNamedType) ? $type->getName() : 'untyped');
		}

		return $value;
	}//end describe()
}//end class
