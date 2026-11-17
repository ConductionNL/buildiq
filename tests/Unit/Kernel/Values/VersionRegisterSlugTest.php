<?php

/**
 * Unit tests for VersionRegisterSlug: the slug of the register that backs one
 * ApplicationVersion is the contract prefix, the app slug, a dash and the
 * version slug, verbatim.
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

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Values\VersionRegisterSlug;
use PHPUnit\Framework\TestCase;

/**
 * Derivation of the per-version register slug.
 *
 * @covers \OCA\Buildiq\Kernel\Values\VersionRegisterSlug
 */
final class VersionRegisterSlugTest extends TestCase {

	/**
	 * The slug is the prefix, the app slug, a dash and the version slug.
	 *
	 * @return void
	 */
	public function testJoinsPrefixAppAndVersion(): void {
		$this->assertSame(
			expected: 'openbuild-pet-store-draft',
			actual: VersionRegisterSlug::forVersion(appSlug: 'pet-store', versionSlug: 'draft')
		);
	}//end testJoinsPrefixAppAndVersion()

	/**
	 * The prefix is the one the version service contract publishes.
	 *
	 * @return void
	 */
	public function testUsesTheContractPrefix(): void {
		$slug = VersionRegisterSlug::forVersion(appSlug: 'a', versionSlug: 'b');

		$this->assertSame(
			expected: ApplicationVersionServiceInterface::VERSION_REGISTER_PREFIX . 'a-b',
			actual: $slug
		);
	}//end testUsesTheContractPrefix()

	/**
	 * The parts are used verbatim: no case folding, trimming or escaping.
	 *
	 * @return void
	 */
	public function testPartsAreNotNormalised(): void {
		$this->assertSame(
			expected: 'openbuild-My App-V 1.0',
			actual: VersionRegisterSlug::forVersion(appSlug: 'My App', versionSlug: 'V 1.0')
		);
	}//end testPartsAreNotNormalised()

	/**
	 * Different versions of one app land on different registers.
	 *
	 * @return void
	 */
	public function testDifferentVersionsGetDifferentSlugs(): void {
		$this->assertNotSame(
			expected: VersionRegisterSlug::forVersion(appSlug: 'pet-store', versionSlug: 'draft'),
			actual: VersionRegisterSlug::forVersion(appSlug: 'pet-store', versionSlug: 'published')
		);
	}//end testDifferentVersionsGetDifferentSlugs()

	/**
	 * Empty parts still keep the separating dash.
	 *
	 * @return void
	 */
	public function testEmptyPartsKeepTheSeparator(): void {
		$this->assertSame(
			expected: 'openbuild--',
			actual: VersionRegisterSlug::forVersion(appSlug: '', versionSlug: '')
		);
	}//end testEmptyPartsKeepTheSeparator()
}//end class
