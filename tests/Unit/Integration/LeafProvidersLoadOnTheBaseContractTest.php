<?php

/**
 * Unit test that buildiq's app-local leaves load on OpenRegister's base contract.
 *
 * OpenRegister's IntegrationProvider no longer declares list(), get(), create(),
 * update() or delete(). A leaf that marked those #[\Override] fataled when the
 * class loaded ("has #[\Override] attribute, but no matching parent method"),
 * which broke `occ maintenance:repair` on an instance running OpenRegister main.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\AppLocalLeafProvider;
use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every #[\Override] on a leaf names a method its parents really declare.
 */
#[CoversClass(PageLayoutLeafProvider::class)]
#[CoversClass(RegistrationFormLeafProvider::class)]
final class LeafProvidersLoadOnTheBaseContractTest extends TestCase {
	/**
	 * The app-local leaves buildiq registers.
	 *
	 * @return array<string, array{0: class-string<AppLocalLeafProvider>}>
	 */
	public static function leaves(): array {
		return [
			'page layouts'       => [PageLayoutLeafProvider::class],
			'registration forms' => [RegistrationFormLeafProvider::class],
		];
	}//end leaves()

	/**
	 * The leaf loads, and each #[\Override] method exists on a parent class or interface.
	 *
	 * @param class-string<AppLocalLeafProvider> $leaf The leaf class.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/tasks.md
	 */
	#[DataProvider('leaves')]
	public function testEveryOverrideNamesADeclaredParentMethod(string $leaf): void {
		$class   = new ReflectionClass(objectOrClass: $leaf);
		$parents = array_values($class->getInterfaces());
		if ($class->getParentClass() !== false) {
			$parents[] = $class->getParentClass();
		}

		foreach ($class->getMethods(filter: ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== $leaf || $method->getAttributes(name: \Override::class) === []) {
				continue;
			}

			$name     = $method->getName();
			$declared = array_filter(
				array: $parents,
				callback: static fn (ReflectionClass $parent): bool => $parent->hasMethod(name: $name)
			);
			self::assertNotSame(
				expected: [],
				actual: $declared,
				message: $leaf . '::' . $name . '() is marked #[\Override] but no parent declares it.'
			);
		}
	}//end testEveryOverrideNamesADeclaredParentMethod()

	/**
	 * The leaf still serves the legacy operations OpenRegister's invoker calls by name.
	 *
	 * @param class-string<AppLocalLeafProvider> $leaf The leaf class.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/tasks.md
	 */
	#[DataProvider('leaves')]
	public function testTheLeafKeepsItsLegacyOperations(string $leaf): void {
		self::assertTrue(condition: is_a(object_or_class: $leaf, class: IntegrationProvider::class, allow_string: true));
		foreach (['list', 'get', 'create', 'update', 'delete'] as $operation) {
			$method = new ReflectionMethod(objectOrMethod: $leaf, method: $operation);
			self::assertSame(expected: 'register', actual: $method->getParameters()[0]->getName(), message: $operation . '() takes the register first.');
		}
	}//end testTheLeafKeepsItsLegacyOperations()
}//end class
