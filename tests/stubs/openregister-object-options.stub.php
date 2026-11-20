<?php

/**
 * Minimal stubs for OpenRegister main's object-API value objects.
 *
 * Mirrors openregister/lib/Kernel/Contracts/Value/{ObjectFindTarget,ObjectSaveOptions,ObjectDeletion}.php
 * (constructor properties only), so buildiq code written against main's object
 * API can be unit-tested out of container. Every declaration is guarded, so this
 * file is a no-op when the real OpenRegister is on the autoload path.
 *
 * @category Stub
 * @package  OCA\Buildiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

if (class_exists(ObjectFindTarget::class) === false) {
	/**
	 * Stub of the register and schema an object call is scoped to.
	 */
	final class ObjectFindTarget {

		/**
		 * @param mixed $register Register id, uuid or slug.
		 * @param mixed $schema   Schema id, uuid or slug.
		 */
		public function __construct(
			public readonly mixed $register=null,
			public readonly mixed $schema=null,
		) {
		}//end __construct()
	}//end class
}

if (class_exists(ObjectSaveOptions::class) === false) {
	/**
	 * Stub of the options of one save.
	 */
	final class ObjectSaveOptions {

		/**
		 * @param ObjectFindTarget|null $target The register and schema.
		 * @param string|null           $uuid   The object to replace.
		 */
		public function __construct(
			public readonly ?ObjectFindTarget $target=null,
			public readonly ?string $uuid=null,
		) {
		}//end __construct()
	}//end class
}

if (class_exists(ObjectDeletion::class) === false) {
	/**
	 * Stub of one deletion.
	 */
	final class ObjectDeletion {

		/**
		 * @param string                $uuid   The object uuid.
		 * @param ObjectFindTarget|null $target The register and schema.
		 */
		public function __construct(
			public readonly string $uuid,
			public readonly ?ObjectFindTarget $target=null,
		) {
		}//end __construct()
	}//end class
}
