<?php

/**
 * ManifestPageShape::normalise() edge cases for form fields and non-array configs.
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

use OCA\Buildiq\Support\ManifestPageShape;
use PHPUnit\Framework\TestCase;

/**
 * Pins how unusable form field entries are dropped or repaired.
 *
 * @covers \OCA\Buildiq\Support\ManifestPageShape
 */
class ManifestPageShapeFieldEdgesTest extends TestCase {

	/**
	 * A page whose config is not an object is returned untouched.
	 *
	 * @return void
	 */
	public function testANonArrayConfigIsLeftAlone(): void {
		$page = ['id' => 'apply', 'type' => 'form', 'config' => 'broken'];

		self::assertSame($page, ManifestPageShape::normalise(page: $page));
	}//end testANonArrayConfigIsLeftAlone()

	/**
	 * A page of another type keeps its config as it was.
	 *
	 * @return void
	 */
	public function testAnIndexPageConfigIsUnchanged(): void {
		$page = ['id' => 'list', 'type' => 'index', 'config' => ['fields' => ['a', 7]]];

		self::assertSame($page, ManifestPageShape::normalise(page: $page));
	}//end testAnIndexPageConfigIsUnchanged()

	/**
	 * Blank, scalar and keyless field entries are dropped; the rest are repaired.
	 *
	 * @return void
	 */
	public function testUnusableFieldsAreDroppedAndTheRestRepaired(): void {
		$page = [
			'type' => 'form',
			'config' => [
				'fields' => [
					'   ',
					42,
					null,
					['label' => 'No key'],
					['key' => '  '],
					' firstName ',
					['key' => 'birth_date', 'label' => ' ', 'type' => 'number'],
					['key' => 'notes', 'label' => 'Remarks', 'type' => 'wysiwyg'],
					['key' => '--'],
				],
			],
		];

		$fields = ManifestPageShape::normalise(page: $page)['config']['fields'];

		self::assertSame(
			[
				['key' => 'firstName', 'label' => 'First name', 'type' => 'string'],
				['key' => 'birth_date', 'label' => 'Birth date', 'type' => 'number'],
				['key' => 'notes', 'label' => 'Remarks', 'type' => 'string'],
				['key' => '--', 'label' => '--', 'type' => 'string'],
			],
			$fields
		);
	}//end testUnusableFieldsAreDroppedAndTheRestRepaired()
}//end class
