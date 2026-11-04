<?php

/**
 * Buildiq Automation Compile Input
 *
 * The fields of one Automation object that the compiler backends read, normalised once:
 * the slug, the trigger block and its type, the condition, the action list and the
 * enabled flag, next to the full object for the fields only the rules backend needs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
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

namespace OCA\Buildiq\Service\Automation;

/**
 * One Automation object as the compiler backends read it.
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-existing-declarative-primitives-req-autd-004
 */
final class CompileInput {

	/**
	 * The automation slug, or '' when absent.
	 *
	 * @var string
	 */
	public readonly string $slug;

	/**
	 * The trigger block, or [] when it is not an array.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $trigger;

	/**
	 * The trigger type, or '' when absent.
	 *
	 * @var string
	 */
	public readonly string $triggerType;

	/**
	 * The condition block as stored (null when absent).
	 *
	 * @var mixed
	 */
	public readonly mixed $condition;

	/**
	 * The action records as a list, or [] when they are not an array.
	 *
	 * @var array<int,mixed>
	 */
	public readonly array $actions;

	/**
	 * Whether the automation is enabled (true when absent).
	 *
	 * @var bool
	 */
	public readonly bool $enabled;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $automation The full Automation object.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $automation,
	) {
		$this->slug = (string)($automation['slug'] ?? '');
		$this->trigger = self::arrayOrEmpty(value: $automation['trigger'] ?? null);
		$this->triggerType = (string)($this->trigger['type'] ?? '');
		$this->condition = $automation['condition'] ?? null;
		$this->actions = array_values(self::arrayOrEmpty(value: $automation['actions'] ?? null));
		$this->enabled = (bool)($automation['enabled'] ?? true);
	}//end __construct()

	/**
	 * Return `$value` when it is an array, otherwise [].
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return array<mixed>
	 */
	private static function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOrEmpty()
}//end class
