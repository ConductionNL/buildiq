<?php

/**
 * Unit tests for AuthoredRowReader: which stored rows belong to one register and schema.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AuthoredRowReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuthoredRowReader.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\AuthoredRowReader
 */
class AuthoredRowReaderTest extends TestCase {

	/**
	 * Rows are read from the configured register and the reader's schema, filtered to the exact register and schema.
	 *
	 * @return void
	 */
	public function testStoredForReadsAndFilters(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('setRegister')->with('custom')->willReturnSelf();
		$objectService->expects($this->once())->method('setSchema')->with('page-layout')->willReturnSelf();
		$objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 'permits'], 'limit' => 500])
			->willReturn(
				[
					['register' => 'permits', 'schema' => 'permit', 'id' => 1],
					['register' => 'permits', 'schema' => 'other', 'id' => 2],
					['register' => 'elsewhere', 'schema' => 'permit', 'id' => 3],
					'not a row',
					['schema' => 'permit', 'id' => 4],
					['register' => 'permits', 'schema' => 'permit', 'id' => 5],
				]
			);

		$reader = new AuthoredRowReader($objectService, $this->appConfig('custom'), 'page-layout');

		$this->assertSame(
			[['register' => 'permits', 'schema' => 'permit', 'id' => 1], ['register' => 'permits', 'schema' => 'permit', 'id' => 5]],
			$reader->storedFor('permits', 'permit')
		);
	}//end testStoredForReadsAndFilters()

	/**
	 * The register slug comes from app config, defaulting to buildiq, and an emptied setting falls back to buildiq.
	 *
	 * @return void
	 */
	public function testRegisterSlug(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);

		$this->assertSame('custom', (new AuthoredRowReader($objectService, $this->appConfig('custom'), 's'))->registerSlug());
		$this->assertSame('buildiq', (new AuthoredRowReader($objectService, $this->appConfig(''), 's'))->registerSlug());
	}//end testRegisterSlug()

	/**
	 * An app config answering one register slug for buildiq/register with default buildiq.
	 *
	 * @param string $slug The slug it answers.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $slug): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('buildiq', 'register', 'buildiq')->willReturn($slug);
		return $config;
	}//end appConfig()
}//end class
