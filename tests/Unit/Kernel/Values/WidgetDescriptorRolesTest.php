<?php

/**
 * Unit tests for the parts of WidgetDescriptor the serialised golden does not
 * see: the role list taken from the placement, the principals and application
 * fields kept on the object, and normaliseSegment() called directly.
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
use OCA\Buildiq\Kernel\Values\WidgetDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * Roles, retained fields and segment normalisation of WidgetDescriptor.
 *
 * @covers \OCA\Buildiq\Kernel\Values\WidgetDescriptor
 * @uses   \OCA\Buildiq\Kernel\Values\WidgetApplication
 */
final class WidgetDescriptorRolesTest extends TestCase {

	/**
	 * Build a descriptor with the given placement.
	 *
	 * @param array<string,mixed> $placement The placement block.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function descriptor(array $placement): WidgetDescriptor {
		return new WidgetDescriptor(
			application: new WidgetApplication(uuid: 'app-uuid', slug: 'pet-store', name: 'Pet Store'),
			principals: ['user:alice', 'group:staff'],
			entryId: 'open-cases',
			widgetKey: 'stat',
			panel: [],
			placement: $placement
		);
	}//end descriptor()

	/**
	 * Roles are re-indexed into a list, whatever keys the placement used.
	 *
	 * @return void
	 */
	public function testRolesAreReindexedIntoAList(): void {
		$descriptor = $this->descriptor(placement: ['roles' => ['a' => 'editor', 'b' => 'viewer']]);

		$this->assertSame(expected: ['editor', 'viewer'], actual: $descriptor->roles);
	}//end testRolesAreReindexedIntoAList()

	/**
	 * Without roles in the placement the descriptor carries none.
	 *
	 * @return void
	 */
	public function testMissingRolesAreEmpty(): void {
		$this->assertSame(expected: [], actual: $this->descriptor(placement: [])->roles);
	}//end testMissingRolesAreEmpty()

	/**
	 * A single scalar role is wrapped into a one-element list.
	 *
	 * @return void
	 */
	public function testAScalarRoleBecomesAList(): void {
		$this->assertSame(expected: ['editor'], actual: $this->descriptor(placement: ['roles' => 'editor'])->roles);
	}//end testAScalarRoleBecomesAList()

	/**
	 * Roles and principals stay on the object but are never serialised.
	 *
	 * @return void
	 */
	public function testRolesAndPrincipalsAreNotSerialised(): void {
		$descriptor = $this->descriptor(placement: ['roles' => ['editor']]);
		$json       = $descriptor->jsonSerialize();

		$this->assertSame(expected: ['user:alice', 'group:staff'], actual: $descriptor->principals);
		$this->assertArrayNotHasKey(key: 'roles', array: $json);
		$this->assertArrayNotHasKey(key: 'principals', array: $json);
		$this->assertArrayNotHasKey(key: 'applicationUuid', array: $json);
		$this->assertSame(expected: 'app-uuid', actual: $descriptor->applicationUuid);
	}//end testRolesAndPrincipalsAreNotSerialised()

	/**
	 * A segment already inside the alphabet is returned unchanged, without a hash.
	 *
	 * @return void
	 */
	public function testACleanSegmentIsReturnedAsIs(): void {
		$this->assertSame(expected: 'open-cases_2', actual: WidgetDescriptor::normaliseSegment(raw: 'open-cases_2'));
	}//end testACleanSegmentIsReturnedAsIs()

	/**
	 * A lossy fold appends the first eight hex digits of the raw value's sha1.
	 *
	 * @return void
	 */
	public function testALossyFoldPinsTheRawValue(): void {
		$this->assertSame(
			expected: 'open-cases-' . substr(sha1('Open Cases!'), 0, 8),
			actual: WidgetDescriptor::normaliseSegment(raw: 'Open Cases!')
		);
	}//end testALossyFoldPinsTheRawValue()

	/**
	 * A segment that folds to nothing becomes 'x' plus the hash of the raw value.
	 *
	 * @return void
	 */
	public function testASegmentThatFoldsToNothingBecomesX(): void {
		$this->assertSame(expected: 'x-' . substr(sha1(''), 0, 8), actual: WidgetDescriptor::normaliseSegment(raw: ''));
		$this->assertSame(expected: 'x-' . substr(sha1('!!!'), 0, 8), actual: WidgetDescriptor::normaliseSegment(raw: '!!!'));
	}//end testASegmentThatFoldsToNothingBecomesX()
}//end class
