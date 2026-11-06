<?php

/**
 * Tests for ExportRequestSanitiser.
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
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ExportRequestSanitiser;
use PHPUnit\Framework\TestCase;

/**
 * The export submit payload is untrusted: every sanitiser drops what does
 * not fit rather than failing the request.
 */
class ExportRequestSanitiserTest extends TestCase {
	/**
	 * The sanitiser under test.
	 *
	 * @var ExportRequestSanitiser
	 */
	private ExportRequestSanitiser $sanitiser;

	/**
	 * Create a fresh sanitiser for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sanitiser = new ExportRequestSanitiser();
	}//end setUp()

	/**
	 * Well-formed register choices survive, with includeData cast to a boolean.
	 *
	 * @return void
	 */
	public function testDataRegistersKeepsWellFormedEntriesAndCastsIncludeData(): void {
		$result = $this->sanitiser->dataRegisters(
			raw: [
				['register' => 'petstore', 'includeData' => 1],
				['register' => 'vocabulary'],
				['register' => ''],
				'not-an-entry',
			]
		);

		$this->assertSame(
			expected: [
				['register' => 'petstore', 'includeData' => true],
				['register' => 'vocabulary', 'includeData' => false],
			],
			actual: $result
		);
	}

	/**
	 * A non-array register choice yields no registers.
	 *
	 * @return void
	 */
	public function testDataRegistersAnswersEmptyForANonArray(): void {
		$this->assertSame(expected: [], actual: $this->sanitiser->dataRegisters(raw: 'petstore'));
	}

	/**
	 * Only a lowercase slug survives.
	 *
	 * @return void
	 */
	public function testSlugKeepsASlugAndDropsAnythingElse(): void {
		$this->assertSame(expected: 'development', actual: $this->sanitiser->slug(raw: 'development'));
		$this->assertSame(expected: '', actual: $this->sanitiser->slug(raw: 'Development'));
		$this->assertSame(expected: '', actual: $this->sanitiser->slug(raw: '../production'));
		$this->assertSame(expected: '', actual: $this->sanitiser->slug(raw: 42));
	}

	/**
	 * A flow binding keeps only its trimmed UUID.
	 *
	 * @return void
	 */
	public function testFlowsKeepsOnlyTheTrimmedUuid(): void {
		$result = $this->sanitiser->flows(
			raw: [
				['flow' => ' 1f0c9b1e-0000-4000-8000-000000000001 ', 'label' => 'Intake'],
				['flow' => '   '],
				['label' => 'no flow'],
				7,
			]
		);

		$this->assertSame(
			expected: [['flow' => '1f0c9b1e-0000-4000-8000-000000000001']],
			actual: $result
		);
	}

	/**
	 * A non-array flow choice yields no flows.
	 *
	 * @return void
	 */
	public function testFlowsAnswersEmptyForANonArray(): void {
		$this->assertSame(expected: [], actual: $this->sanitiser->flows(raw: null));
	}
}//end class
