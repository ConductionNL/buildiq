<?php

/**
 * Unit tests for LocalApplicationRef: a reference to a local Application by uuid, slug or both.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Kernel\Values
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Kernel\Values;

use OCA\Buildiq\Kernel\Values\LocalApplicationRef;
use PHPUnit\Framework\TestCase;

/**
 * Construction, defaults and immutability of LocalApplicationRef.
 *
 * @covers \OCA\Buildiq\Kernel\Values\LocalApplicationRef
 */
final class LocalApplicationRefTest extends TestCase {

	/**
	 * Uuid and slug are kept exactly as given.
	 *
	 * @return void
	 */
	public function testKeepsUuidAndSlug(): void {
		$ref = new LocalApplicationRef(uuid: 'app-uuid', slug: 'Pet-Store');

		$this->assertSame(expected: 'app-uuid', actual: $ref->uuid);
		$this->assertSame(expected: 'Pet-Store', actual: $ref->slug);
	}//end testKeepsUuidAndSlug()

	/**
	 * An empty reference names no application: both parts are null.
	 *
	 * @return void
	 */
	public function testBothPartsDefaultToNull(): void {
		$ref = new LocalApplicationRef();

		$this->assertNull(actual: $ref->uuid);
		$this->assertNull(actual: $ref->slug);
	}//end testBothPartsDefaultToNull()

	/**
	 * A reference by slug alone leaves the uuid null.
	 *
	 * @return void
	 */
	public function testASlugOnlyReferenceHasNoUuid(): void {
		$ref = new LocalApplicationRef(slug: 'pet-store');

		$this->assertNull(actual: $ref->uuid);
		$this->assertSame(expected: 'pet-store', actual: $ref->slug);
	}//end testASlugOnlyReferenceHasNoUuid()

	/**
	 * A LocalApplicationRef cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new LocalApplicationRef(uuid: 'a');

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->uuid = 'b';
	}//end testPropertiesAreReadOnly()
}//end class
