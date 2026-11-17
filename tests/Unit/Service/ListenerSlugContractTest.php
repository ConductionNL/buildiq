<?php

/**
 * Unit tests for ListenerSlugContract: the flag is read from the app
 * config under its own key, off by default.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ListenerSlugContract;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the listener slug contract flag.
 *
 * @covers \OCA\Buildiq\Service\ListenerSlugContract
 */
final class ListenerSlugContractTest extends TestCase {

	/**
	 * The flag is the app config value, read with false as default.
	 *
	 * @return void
	 */
	public function testIsEnabledReadsTheAppConfigFlag(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->exactly(2))->method('getValueBool')
			->with('buildiq', 'listener_slug_contract', false)
			->willReturnOnConsecutiveCalls(true, false);

		$contract = new ListenerSlugContract($config);

		self::assertTrue($contract->isEnabled());
		self::assertFalse($contract->isEnabled());
	}//end testIsEnabledReadsTheAppConfigFlag()
}//end class
