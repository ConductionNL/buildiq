<?php

/**
 * Buildiq Registration Form Sign-in Level
 *
 * The sign-in a filer needs before the portal shows or accepts one
 * registration form (buildiq#935). buildiq records the maker's choice;
 * portaliq enforces it (portaliq#725). The value set is portaliq's own:
 * `low`, `substantial` or `high`, and no level at all for an anonymous form.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Checks and reads a registration form's sign-in level.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-009)
 */
final class RegistrationFormSignInLevel {
	/**
	 * The sign-in levels portaliq enforces. No level means anonymous; there
	 * is no `anonymous` value.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = ['low', 'substantial', 'high'];

	/**
	 * Refuse a level portaliq does not know, and a form that asks for a
	 * sign-in while also being open to anyone. A level the portal does not
	 * know would refuse every filer with nothing telling the maker why.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the level is unknown or contradicts isPublic.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-009)
	 */
	public function assertValid(array $form): void {
		$level = ($form['minTrust'] ?? null);
		if ($level === null || $level === '') {
			return;
		}

		if (is_string($level) === false || in_array($level, self::LEVELS, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'Unknown sign-in level %s; expected one of %s, or none for an anonymous form.',
					var_export($level, true),
					implode(', ', self::LEVELS)
				)
			);
		}

		if (($form['isPublic'] ?? false) === true) {
			throw new InvalidArgumentException(
				'A form open to anyone without signing in cannot also ask for a sign-in level; choose one of the two.'
			);
		}
	}//end assertValid()
}//end class
