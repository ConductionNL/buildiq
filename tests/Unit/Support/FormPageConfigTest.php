<?php

/**
 * Buildiq FormPageConfig unit tests
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\FormPageConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FormPageConfig}.
 *
 * @covers \OCA\Buildiq\Support\FormPageConfig
 */
final class FormPageConfigTest extends TestCase {

	/**
	 * Name-only fields become objects, and the register/schema pair gives the form its endpoint and destination.
	 *
	 * @return void
	 */
	public function testFieldsAndDestinationFromThePair(): void {
		$config = FormPageConfig::normalise(config: [
			'register' => 'lib',
			'schema' => 'loan',
			'fields' => ['borrowedOn', '', ['key' => 'asset_tag', 'type' => 'weird'], 7],
		]);

		self::assertSame(
			[
				['key' => 'borrowedOn', 'label' => 'Borrowed on', 'type' => 'string'],
				['key' => 'asset_tag', 'type' => 'string', 'label' => 'Asset tag'],
			],
			$config['fields']
		);
		self::assertSame('/apps/openregister/api/objects/lib/loan', $config['submitEndpoint']);
		self::assertSame(['register' => 'lib', 'schema' => 'loan'], $config['destination']);
	}//end testFieldsAndDestinationFromThePair()

	/**
	 * A named destination is the authority for the register and schema.
	 *
	 * @return void
	 */
	public function testDestinationFillsThePair(): void {
		$config = FormPageConfig::normalise(config: ['destination' => ['register' => ' r ', 'schema' => 's']]);

		self::assertSame('r', $config['register']);
		self::assertSame('s', $config['schema']);
		self::assertSame('/apps/openregister/api/objects/r/s', $config['submitEndpoint']);
	}//end testDestinationFillsThePair()

	/**
	 * A custom submit handler gets no endpoint and no destination.
	 *
	 * @return void
	 */
	public function testSubmitHandlerPostsThroughAppCode(): void {
		$config = FormPageConfig::normalise(config: ['submitHandler' => 'save', 'register' => 'r', 'schema' => 's']);

		self::assertArrayNotHasKey('submitEndpoint', $config);
		self::assertArrayNotHasKey('destination', $config);
	}//end testSubmitHandlerPostsThroughAppCode()

	/**
	 * Without a pair nothing is invented.
	 *
	 * @return void
	 */
	public function testNothingInventedWithoutAPair(): void {
		self::assertSame(['register' => 'r'], FormPageConfig::normalise(config: ['register' => 'r']));
	}//end testNothingInventedWithoutAPair()
}//end class
