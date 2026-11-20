<?php

/**
 * Unit tests for WidgetApplication: the uuid, slug and name of the Application a widget is promoted from.
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

use OCA\Buildiq\Kernel\Values\WidgetApplication;
use PHPUnit\Framework\TestCase;

/**
 * Construction and immutability of WidgetApplication.
 *
 * @covers \OCA\Buildiq\Kernel\Values\WidgetApplication
 */
final class WidgetApplicationTest extends TestCase {

	/**
	 * Uuid, slug and name are kept exactly as given, without normalising.
	 *
	 * @return void
	 */
	public function testKeepsUuidSlugAndNameVerbatim(): void {
		$application = new WidgetApplication(uuid: 'UUID With Spaces', slug: 'pet-store', name: ' Pet Store ');

		$this->assertSame(expected: 'UUID With Spaces', actual: $application->uuid);
		$this->assertSame(expected: 'pet-store', actual: $application->slug);
		$this->assertSame(expected: ' Pet Store ', actual: $application->name);
	}//end testKeepsUuidSlugAndNameVerbatim()

	/**
	 * A WidgetApplication cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new WidgetApplication(uuid: 'u', slug: 's', name: 'n');

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->uuid = 'other';
	}//end testPropertiesAreReadOnly()
}//end class
