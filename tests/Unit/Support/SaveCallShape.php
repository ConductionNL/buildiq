<?php

/**
 * Reads a saveObject() call back as its separate arguments.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use PHPUnit\Framework\Constraint\Callback;

/**
 * Turns the arguments a saveObject() double receives into a flat list.
 *
 * saveObject() takes (object, ObjectSaveOptions, _rbac, _multitenancy). Test
 * doubles that assert on the register, schema, uuid or flags read them through
 * this list instead of digging into the options themselves:
 * [object, extend, register, schema, uuid, _rbac, _multitenancy, silent,
 * validation, uploadedFiles, currentUser, failIfExists].
 */
final class SaveCallShape {

	/**
	 * Flatten the received arguments.
	 *
	 * @param array<int, mixed> $args The arguments, as the double received them.
	 *
	 * @return array<int, mixed>
	 */
	public static function flatten(array $args): array {
		$options = ($args[1] ?? null);
		if ($options instanceof ObjectSaveOptions === false) {
			$options = new ObjectSaveOptions();
		}

		return [
			($args[0] ?? null),
			$options->extend,
			$options->target->register,
			$options->target->schema,
			$options->uuid,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->policy->silent,
			$options->policy->validation,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
		];
	}//end flatten()

	/**
	 * Flatten the received arguments, keyed by the old parameter names.
	 *
	 * @param array<int, mixed> $args The arguments, as the double received them.
	 *
	 * @return array<string, mixed>
	 */
	public static function named(array $args): array {
		return array_combine(
			['object', 'extend', 'register', 'schema', 'uuid', '_rbac', '_multitenancy', 'silent', '_validation', 'uploadedFiles', 'currentUser', 'failIfExists'],
			self::flatten(args: $args)
		);
	}//end named()

	/**
	 * A constraint on the options argument: each named old parameter must equal its value.
	 *
	 * @param array<string, mixed> $expected Old parameter name (register, schema, uuid, ...) => value.
	 *
	 * @return Callback<mixed>
	 */
	public static function where(array $expected): Callback {
		return new Callback(
			static function (mixed $options) use ($expected): bool {
				if ($options instanceof ObjectSaveOptions === false) {
					return false;
				}

				$named = self::named(args: [null, $options]);
				foreach ($expected as $name => $value) {
					if (($named[$name] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}
		);
	}//end where()
}//end class
