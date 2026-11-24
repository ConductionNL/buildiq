<?php

/**
 * Unit tests for ProductionVersion: which version an app's users see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ProductionVersion;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProductionVersion.
 *
 * @covers \OCA\Buildiq\Service\ProductionVersion
 */
final class ProductionVersionTest extends TestCase {

	/**
	 * A uuid or an expanded object, on either side, names the same version.
	 *
	 * @return void
	 */
	public function testAUuidOrAnExpandedObjectIsRead(): void {
		self::assertTrue(condition: ProductionVersion::is(application: ['productionVersion' => 'u-prod'], version: 'u-prod'));
		self::assertTrue(condition: ProductionVersion::is(application: ['productionVersion' => ['id' => 'u-prod']], version: ['uuid' => 'u-prod']));
	}//end testAUuidOrAnExpandedObjectIsRead()

	/**
	 * Another version, no production version or no version is not production.
	 *
	 * @return void
	 */
	public function testAnythingElseIsNotProduction(): void {
		self::assertFalse(condition: ProductionVersion::is(application: ['productionVersion' => 'u-prod'], version: 'u-draft'));
		self::assertFalse(condition: ProductionVersion::is(application: [], version: 'u-prod'));
		self::assertFalse(condition: ProductionVersion::is(application: ['productionVersion' => ''], version: ''));
		self::assertFalse(condition: ProductionVersion::is(application: ['productionVersion' => 'u-prod'], version: null));
	}//end testAnythingElseIsNotProduction()
}//end class
