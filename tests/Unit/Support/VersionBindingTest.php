<?php

/**
 * Unit tests for VersionBinding: a binding is complete only with both slugs.
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

use OCA\Buildiq\Support\VersionBinding;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VersionBinding.
 *
 * @covers \OCA\Buildiq\Support\VersionBinding
 */
final class VersionBindingTest extends TestCase {

	/**
	 * Both slugs set: complete, and the values are kept as given.
	 *
	 * @return void
	 */
	public function testBothSlugsMakeItComplete(): void {
		$binding = new VersionBinding(appSlug: 'tools', versionSlug: 'v1', dataRegisters: ['buildiq-tools']);

		self::assertTrue($binding->isComplete());
		self::assertSame('tools', $binding->appSlug);
		self::assertSame('v1', $binding->versionSlug);
		self::assertSame(['buildiq-tools'], $binding->dataRegisters);
	}//end testBothSlugsMakeItComplete()

	/**
	 * Either slug empty: incomplete.
	 *
	 * @return void
	 */
	public function testAnEmptySlugMakesItIncomplete(): void {
		self::assertFalse((new VersionBinding(appSlug: '', versionSlug: 'v1'))->isComplete());
		self::assertFalse((new VersionBinding(appSlug: 'tools', versionSlug: ''))->isComplete());
		self::assertFalse((new VersionBinding(appSlug: '', versionSlug: ''))->isComplete());
	}//end testAnEmptySlugMakesItIncomplete()

	/**
	 * Without data registers the list is empty.
	 *
	 * @return void
	 */
	public function testDataRegistersDefaultToEmpty(): void {
		self::assertSame([], (new VersionBinding(appSlug: 'tools', versionSlug: 'v1'))->dataRegisters);
	}//end testDataRegistersDefaultToEmpty()
}//end class
