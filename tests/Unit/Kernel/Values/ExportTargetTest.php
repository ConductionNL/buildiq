<?php

/**
 * Unit tests for ExportTarget: the application, version and job one export runs for.
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

use OCA\Buildiq\Kernel\Values\ExportTarget;
use PHPUnit\Framework\TestCase;

/**
 * Construction, defaults and immutability of ExportTarget.
 *
 * @covers \OCA\Buildiq\Kernel\Values\ExportTarget
 */
final class ExportTargetTest extends TestCase {

	/**
	 * The application, version, job and slug are kept exactly as given.
	 *
	 * @return void
	 */
	public function testKeepsTheIdentifiersVerbatim(): void {
		$target = new ExportTarget(applicationUuid: 'app-uuid', versionSlug: 'V1.0', jobUuid: 'job-uuid', applicationSlug: 'pet-store');

		$this->assertSame(expected: 'app-uuid', actual: $target->applicationUuid);
		$this->assertSame(expected: 'V1.0', actual: $target->versionSlug);
		$this->assertSame(expected: 'job-uuid', actual: $target->jobUuid);
		$this->assertSame(expected: 'pet-store', actual: $target->applicationSlug);
	}//end testKeepsTheIdentifiersVerbatim()

	/**
	 * Without a slug the target carries an empty string, never null.
	 *
	 * @return void
	 */
	public function testTheApplicationSlugDefaultsToEmpty(): void {
		$target = new ExportTarget(applicationUuid: 'app-uuid', versionSlug: 'v1', jobUuid: 'job-uuid');

		$this->assertSame(expected: '', actual: $target->applicationSlug);
	}//end testTheApplicationSlugDefaultsToEmpty()

	/**
	 * A ExportTarget cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new ExportTarget(applicationUuid: 'a', versionSlug: 'v', jobUuid: 'j');

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->jobUuid = 'other';
	}//end testPropertiesAreReadOnly()
}//end class
