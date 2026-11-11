<?php

/**
 * Buildiq AuthoringScope
 *
 * The register and schema an authoring read is about, taken from the request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCP\IRequest;

/**
 * One register and one schema, both named.
 */
final class AuthoringScope {
	/**
	 * Constructor.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()

	/**
	 * The scope the request names.
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return self|null The scope, or null when the register or the schema is missing.
	 */
	public static function fromRequest(IRequest $request): ?self {
		$register = (string)$request->getParam('register', '');
		$schema = (string)$request->getParam('schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return new self(register: $register, schema: $schema);
	}//end fromRequest()
}//end class
