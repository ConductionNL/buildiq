<?php

/**
 * Unit test for the shared descriptor of buildiq's app-local leaves.
 *
 * Pins every answer the abstract base gives OpenRegister on behalf of a leaf:
 * the required app, the storage strategy, the absent OpenConnector source, the
 * always-on switch and the absence of a permission or credentials of its own.
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
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the descriptor answers shared by every app-local leaf.
 */
#[CoversClass(AppLocalLeafProvider::class)]
final class AppLocalLeafProviderTest extends TestCase {
	/**
	 * A minimal concrete leaf, so only the base's own answers are under test.
	 *
	 * @return AppLocalLeafProvider The leaf.
	 */
	private function leaf(): AppLocalLeafProvider {
		return new class extends AppLocalLeafProvider {
			public function getId(): string {
				return 'test-leaf';
			}

			public function getLabel(): string {
				return 'Test leaf';
			}

			public function getIcon(): string {
				return 'Test';
			}

			public function getGroup(): ?string {
				return null;
			}

			public function list(string $register, string $schema, string $objectId, array $filters = []): array {
				return [];
			}

			public function get(string $register, string $schema, string $objectId, string $entityId): array {
				return [];
			}

			public function create(string $register, string $schema, string $objectId, array $payload): array {
				return [];
			}

			public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
				return [];
			}

			public function delete(string $register, string $schema, string $objectId, string $entityId): void {
			}

			public function health(): array {
				return [];
			}
		};
	}//end leaf()

	/**
	 * The base is an OpenRegister integration provider.
	 *
	 * @return void
	 */
	public function testIsAnIntegrationProvider(): void {
		self::assertInstanceOf(IntegrationProvider::class, $this->leaf());
	}//end testIsAnIntegrationProvider()

	/**
	 * A leaf answers only when buildiq itself is installed.
	 *
	 * @return void
	 */
	public function testRequiresBuildiq(): void {
		self::assertSame('buildiq', $this->leaf()->getRequiredApp());
	}//end testRequiresBuildiq()

	/**
	 * A leaf's objects live in buildiq's own register, with no OpenConnector source.
	 *
	 * @return void
	 */
	public function testIsServedFromTheAppsOwnRegister(): void {
		$leaf = $this->leaf();

		self::assertSame('app-local', $leaf->getStorageStrategy());
		self::assertNull($leaf->getOpenConnectorSource());
	}//end testIsServedFromTheAppsOwnRegister()

	/**
	 * A leaf is always enabled.
	 *
	 * @return void
	 */
	public function testIsAlwaysEnabled(): void {
		self::assertTrue($this->leaf()->isEnabled());
	}//end testIsAlwaysEnabled()

	/**
	 * A leaf asks for no permission and no credentials of its own.
	 *
	 * @return void
	 */
	public function testAsksForNoPermissionOrCredentials(): void {
		$leaf = $this->leaf();

		self::assertNull($leaf->requiresPermission());
		self::assertSame([], $leaf->authRequirements());
	}//end testAsksForNoPermissionOrCredentials()
}//end class
