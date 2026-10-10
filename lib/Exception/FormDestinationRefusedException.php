<?php

/**
 * Buildiq FormDestinationRefusedException
 *
 * Thrown when a form is published while OpenRegister's form destination validator
 * still has findings on it. The findings travel with the exception so the
 * controller can answer 422 with every one of them, and the designer can put
 * each on its own field (decision 179, decision 181: no report-only mode).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\Buildiq\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use RuntimeException;

/**
 * 422-mapped: the form has findings against its destination and is not published.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */
final class FormDestinationRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $findings The validator's findings, each `{field?, property, code, message}`.
	 */
	public function __construct(private readonly array $findings) {
		parent::__construct(
			message: sprintf(
				'This form cannot be published: its destination refuses it on %d point(s). Each one is marked on its field; save it as a draft to keep working on it.',
				count($findings)
			)
		);
	}//end __construct()

	/**
	 * Every finding, in the validator's order.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function getFindings(): array {
		return $this->findings;
	}//end getFindings()
}//end class
