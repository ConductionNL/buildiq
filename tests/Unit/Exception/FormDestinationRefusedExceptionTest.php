<?php

/**
 * Unit tests for FormDestinationRefusedException.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Exception;

use OCA\Buildiq\Exception\FormDestinationRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * The exception carries every finding and counts them in its sentence.
 */
final class FormDestinationRefusedExceptionTest extends TestCase {
	/**
	 * Every finding travels with the exception, in order.
	 *
	 * @return void
	 */
	public function testItCarriesEveryFindingAndCountsThem(): void {
		$findings = [
			['field' => 'a', 'property' => 'a', 'code' => 'type-mismatch', 'message' => 'A'],
			['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'B'],
		];

		$e = new FormDestinationRefusedException(findings: $findings);

		self::assertSame($findings, $e->getFindings());
		self::assertStringContainsString('2 point(s)', $e->getMessage());
	}//end testItCarriesEveryFindingAndCountsThem()
}//end class
