<?php

/**
 * Minimal stubs for OpenRegister's leaf-provider contract.
 *
 * Mirrors openregister/lib/Service/Integration/{IntegrationProvider,LeafDescriptor}.php
 * and lib/Event/RegisterLeafProvidersEvent.php, so buildiq's leaf providers can
 * be unit-tested out of container. Every declaration is guarded, so this file is
 * a no-op when the real OpenRegister is on the autoload path.
 *
 * Keep the method set in step with the real interface: a stub that omits a
 * method lets a provider compile here and fatal in production.
 *
 * @category Stub
 * @package  OCA\Buildiq\Tests
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

namespace OCA\OpenRegister\Service\Integration;

if (interface_exists(IntegrationProvider::class) === false) {
	interface IntegrationProvider {
		public function getId(): string;

		public function getLabel(): string;

		public function getIcon(): string;

		public function getGroup(): ?string;

		public function getRequiredApp(): ?string;

		public function getStorageStrategy(): string;

		public function getOpenConnectorSource(): ?string;

		public function isEnabled(): bool;

		public function requiresPermission(): ?string;

		/**
		 * @return array<string, mixed>
		 */
		public function authRequirements(): array;

		/**
		 * @param array<string, mixed> $filters Filters.
		 *
		 * @return array<int|string, mixed>
		 */
		public function list(string $register, string $schema, string $objectId, array $filters = []): array;

		/**
		 * @return array<string, mixed>
		 */
		public function get(string $register, string $schema, string $objectId, string $entityId): array;

		/**
		 * @param array<string, mixed> $payload Payload.
		 *
		 * @return array<string, mixed>
		 */
		public function create(string $register, string $schema, string $objectId, array $payload): array;

		/**
		 * @param array<string, mixed> $payload Payload.
		 *
		 * @return array<string, mixed>
		 */
		public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array;

		public function delete(string $register, string $schema, string $objectId, string $entityId): void;

		/**
		 * @return array<string, mixed>
		 */
		public function health(): array;
	}
}

if (class_exists(LeafDescriptor::class) === false) {
	final class LeafDescriptor {
		public const KIND_RENDER_SURFACE = 'render-surface';

		public const KIND_DATA_PROVIDER = 'data-provider';

		public const KIND_AGENT_RUNNER = 'agent-runner';

		public const RENDER_MODE_COMPONENT = 'component';

		/**
		 * @param array<int, string> $kinds Kinds.
		 * @param array<int, string> $surfaces Surfaces.
		 */
		public function __construct(
			private string $id,
			private string $label,
			private string $icon,
			private array $kinds,
			private ?string $requiredApp = null,
			private ?string $group = null,
			private array $surfaces = [],
			private ?string $referenceType = null,
			private ?string $requiresPermission = null,
			private string $renderMode = self::RENDER_MODE_COMPONENT,
		) {
		}

		public function getId(): string {
			return $this->id;
		}

		public function getLabel(): string {
			return $this->label;
		}

		public function getIcon(): string {
			return $this->icon;
		}

		/**
		 * @return array<int, string>
		 */
		public function getKinds(): array {
			return $this->kinds;
		}

		public function getRequiredApp(): ?string {
			return $this->requiredApp;
		}

		public function getGroup(): ?string {
			return $this->group;
		}

		/**
		 * @return array<int, string>
		 */
		public function getSurfaces(): array {
			return $this->surfaces;
		}

		public function getReferenceType(): ?string {
			return $this->referenceType;
		}

		public function getRequiresPermission(): ?string {
			return $this->requiresPermission;
		}

		public function getRenderMode(): string {
			return $this->renderMode;
		}
	}
}

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;

if (class_exists(RegisterLeafProvidersEvent::class) === false) {
	class RegisterLeafProvidersEvent extends Event {
		/**
		 * @var array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}>
		 */
		private array $leaves = [];

		public function registerLeaf(LeafDescriptor $descriptor, ?IntegrationProvider $provider = null): void {
			$this->leaves[] = ['descriptor' => $descriptor, 'provider' => $provider];
		}

		/**
		 * @return array<int, array{descriptor: LeafDescriptor, provider: ?IntegrationProvider}>
		 */
		public function getLeaves(): array {
			return $this->leaves;
		}
	}
}
