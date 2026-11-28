<?php

/**
 * Integriq Object Store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Writes and deletes objects in the `integriq` register through OpenRegister's
 * object API (main: `ObjectSaveOptions`, `ObjectFindTarget`, `ObjectDeletion`).
 * The declarations an inbound trigger needs (a mail subscription, a webhook
 * endpoint, its rule and its consumer) are integriq objects, so buildiq writes
 * them and integriq serves them (logic-email-and-webhook-triggers D2, D3).
 *
 * OpenRegister is resolved at use time: a hard constructor type on a cross-app
 * class turns an absent or older OpenRegister into an outage of the whole app.
 * Writes run with the acting user's rights; nothing here widens them.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Saves and deletes objects in the integriq register.
 */
class IntegriqObjectStore {

	/**
	 * The register integriq keeps its subscriptions, endpoints, rules and consumers in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * OpenRegister's object service contract.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Contract\\ObjectServiceInterface';

	/**
	 * OpenRegister main's value objects for a save, a target and a deletion.
	 *
	 * @var string
	 */
	private const SAVE_OPTIONS = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectSaveOptions';

	/**
	 * @var string
	 */
	private const FIND_TARGET = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectFindTarget';

	/**
	 * @var string
	 */
	private const DELETION = 'OCA\\OpenRegister\\Kernel\\Contracts\\Value\\ObjectDeletion';

	/**
	 * The refusal when OpenRegister cannot take the write.
	 *
	 * @var string
	 */
	public const UNAVAILABLE_REFUSAL = 'Integriq cannot take this trigger right now, so nothing was applied.';

	/**
	 * @param ContainerInterface $container Resolves OpenRegister's object service at use time.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister offers the object API this store writes through.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
	 */
	public function available(): bool {
		return class_exists(self::SAVE_OPTIONS) === true
			&& class_exists(self::FIND_TARGET) === true
			&& class_exists(self::DELETION) === true
			&& $this->container->has(self::OBJECT_SERVICE) === true;
	}//end available()

	/**
	 * Create or replace one integriq object.
	 *
	 * @param string               $schema The integriq schema slug.
	 * @param array<string, mixed> $object The full object (a save replaces, it does not merge).
	 * @param string|null          $uuid   The object to replace, or null to create one.
	 *
	 * @return string The stored object's uuid.
	 *
	 * @throws UnsupportedAutomationCombinationException When the write cannot be made.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
	 */
	public function save(string $schema, array $object, ?string $uuid): string {
		if ($this->available() === false) {
			throw new UnsupportedAutomationCombinationException(message: self::UNAVAILABLE_REFUSAL);
		}

		$targetClass = self::FIND_TARGET;
		$optionsClass = self::SAVE_OPTIONS;
		$options = new $optionsClass(target: new $targetClass(register: self::REGISTER, schema: $schema), uuid: $uuid);

		try {
			$saved = $this->container->get(self::OBJECT_SERVICE)->saveObject(object: $object, options: $options);
		} catch (Throwable $e) {
			throw new UnsupportedAutomationCombinationException(message: self::UNAVAILABLE_REFUSAL . ' (' . $schema . ': ' . $e->getMessage() . ')');
		}

		$stored = (string)$saved->getUuid();
		if ($stored === '') {
			throw new UnsupportedAutomationCombinationException(message: self::UNAVAILABLE_REFUSAL . ' (' . $schema . ' has no uuid)');
		}

		return $stored;
	}//end save()

	/**
	 * Delete one integriq object; one that is already gone is no error.
	 *
	 * @param string $schema The integriq schema slug.
	 * @param string $uuid   The object uuid.
	 *
	 * @return bool Whether the delete went through (false when OpenRegister refused or is absent).
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
	 */
	public function delete(string $schema, string $uuid): bool {
		if ($uuid === '' || $this->available() === false) {
			return false;
		}

		$targetClass = self::FIND_TARGET;
		$deletionClass = self::DELETION;
		$deletion = new $deletionClass(uuid: $uuid, target: new $targetClass(register: self::REGISTER, schema: $schema));

		try {
			return (bool)$this->container->get(self::OBJECT_SERVICE)->deleteObject(deletion: $deletion);
		} catch (Throwable $e) {
			return false;
		}
	}//end delete()
}//end class
