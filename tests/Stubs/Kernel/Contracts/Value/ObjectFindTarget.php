<?php

/**
 * OpenRegister ObjectFindTarget
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The register and schema one ObjectService::find() call names, as the caller gave them.
 *
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register The register object or its ID/UUID.
	 * @param Schema|string|int|null   $schema   The schema object or its ID/UUID.
	 *
	 */
	public function __construct(
		public readonly Register|string|int|null $register=null,
		public readonly Schema|string|int|null $schema=null,
	) {
	}//end __construct()

	/**
	 * The target a read's options name; an entity this app did not build is passed by its id.
	 *
	 * @param ObjectFindOptions $options The read's options.
	 *
	 * @return self The target.
	 *
	 */
	public static function fromFindOptions(ObjectFindOptions $options): self {
		$register = $options->register;
		if ($register instanceof RegisterInterface && ($register instanceof Register) === false) {
			$register = $register->getId();
		}

		$schema = $options->schema;
		if ($schema instanceof SchemaInterface && ($schema instanceof Schema) === false) {
			$schema = $schema->getId();
		}

		return new self(register: $register, schema: $schema);
	}//end fromFindOptions()
}//end class
