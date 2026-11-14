<?php

/**
 * Golden test for the descriptor answers of buildiq's two leaf providers.
 *
 * Both leaves are app-local, need buildiq installed, have no OpenConnector
 * source, are always enabled and ask for no permission or credentials of their
 * own. This pins every one of those answers, plus each leaf's own id, label,
 * icon and group, so the shared base cannot change what OpenRegister is told.
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

use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the descriptor of each leaf.
 */
final class LeafProviderDescriptorTest extends TestCase {
	/**
	 * Each leaf and its own descriptor answers.
	 *
	 * @return array<string, array{0: class-string, 1: array<int, string>}>
	 */
	public static function leaves(): array {
		return [
			'page layout'       => [PageLayoutLeafProvider::class, ['buildiq-page-layout', 'Page layout', 'ViewDashboardOutline', 'Design']],
			'registration form' => [RegistrationFormLeafProvider::class, ['buildiq-registration-form', 'Registration forms', 'FormSelect', 'Forms']],
		];
	}//end leaves()

	/**
	 * The leaf describes itself the same way it always did.
	 *
	 * @param class-string         $class The provider class.
	 * @param array<int, string>   $own   Its id, label, icon and group.
	 *
	 * @return void
	 */
	#[DataProvider('leaves')]
	public function testDescriptor(string $class, array $own): void {
		$leaf = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertInstanceOf(IntegrationProvider::class, $leaf);
		self::assertSame($own, [$leaf->getId(), $leaf->getLabel(), $leaf->getIcon(), $leaf->getGroup()]);
		$shared = [$leaf->getRequiredApp(), $leaf->getStorageStrategy(), $leaf->getOpenConnectorSource(), $leaf->isEnabled()];
		self::assertSame(['buildiq', 'app-local', null, true], $shared);
	}//end testDescriptor()

	/**
	 * Neither leaf asks for a permission or credentials of its own.
	 *
	 * @param class-string $class The provider class.
	 *
	 * @return void
	 */
	#[DataProvider('leaves')]
	public function testAccessRequirements(string $class): void {
		$leaf = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		self::assertNull($leaf->requiresPermission());
		self::assertSame([], $leaf->authRequirements());
	}//end testAccessRequirements()
}//end class
