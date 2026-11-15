<?php

/**
 * Unit tests for AppCatalogue: the apps and their versions, read in system context.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppCatalogue;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading apps and versions.
 *
 * @covers \OCA\Buildiq\Service\AppCatalogue
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class AppCatalogueTest extends TestCase {

	/**
	 * Apps are read in system context, re-checked on slug and sorted; versions only of that app.
	 *
	 * @return void
	 */
	public function testAppsAndVersionsAreReadAndFiltered(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$calls = [];
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $register, string $schema, array $filters, bool $_rbac, bool $_multitenancy) use (&$calls): array {
				$calls[] = [$register, $schema, $filters, $_rbac, $_multitenancy];
				if ($schema === AppCatalogue::APPLICATION_SCHEMA) {
					return [['slug' => 'vergunningen', 'name' => 'V'], ['slug' => 'meldingen', 'name' => 'M']];
				}

				return [
					['slug' => 'production', 'semver' => '1.2.0', 'status' => 'published', 'application' => 'app-v'],
					['slug' => 'development', 'semver' => '1.3.0', 'status' => 'draft', 'application' => 'app-v'],
					['slug' => 'other', 'semver' => '9.0.0', 'status' => 'draft', 'application' => 'app-x'],
				];
			}
		);
		$catalogue = new AppCatalogue($objects);

		$this->assertSame(['meldingen', 'vergunningen'], array_column($catalogue->apps(), 'slug'));
		$this->assertSame('vergunningen', $catalogue->app('vergunningen')['slug']);
		$this->assertNull($catalogue->app('verg'));
		$this->assertSame(
			[
				['slug' => 'development', 'semver' => '1.3.0', 'status' => 'draft'],
				['slug' => 'production', 'semver' => '1.2.0', 'status' => 'published'],
			],
			$catalogue->versions('app-v')
		);

		$this->assertSame(['buildiq', 'built-app', [], false, false], $calls[0]);
		$this->assertSame(['buildiq', 'built-app', ['slug' => 'vergunningen'], false, false], $calls[1]);
		$this->assertSame(['buildiq', 'applicationVersion', ['application' => 'app-v'], false, false], $calls[3]);

	}//end testAppsAndVersionsAreReadAndFiltered()

	/**
	 * A count instead of a list reads as no apps.
	 *
	 * @return void
	 */
	public function testACountReadsAsNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn(3);

		$this->assertSame([], (new AppCatalogue($objects))->apps());

	}//end testACountReadsAsNothing()
}//end class
