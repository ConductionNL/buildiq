<?php

/**
 * Buildiq FeelCellCondition
 *
 * The PHP mirror of the decision-table cell check in `src/utils/feelCell.js`
 * (`isCellConditionValid()`): a don't-care token, a comparison operator with
 * an operand, an inclusive range, a list membership, or a bare literal; a lone
 * `=` is refused. The copilot assist runs it on every cell of a proposed
 * decision row, so a row the editor would flag never reaches the maker. Both
 * checks are driven by `tests/fixtures/feel-cell-conditions.json` so they
 * cannot drift.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Syntactic check of one decision-table cell condition.
 *
 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
 */
final class FeelCellCondition {

	/**
	 * Tokens that match any value.
	 *
	 * @var string[]
	 */
	private const DONT_CARE = ['', '-', '*', 'any'];

	/**
	 * A comparison with an operand, a range low..high, and `in (a, b)`, in the
	 * order the JavaScript check tries them.
	 *
	 * @var string[]
	 */
	private const OPERATOR_SHAPES = [
		'/^(==|!=|<=|>=|<|>)\s*\S+/u',
		'/^\S+\.\.\S+$/Du',
		'/^in\s*\(.*\)$/Diu',
	];

	/**
	 * A lone `=` followed by anything but a second `=`.
	 *
	 * @var string
	 */
	private const LONE_EQUALS = '/^=[^=]/u';

	/**
	 * A quoted literal, or any text without brackets.
	 *
	 * @var string
	 */
	private const BARE_LITERAL = "/^('.*'|[^()]+)$/Du";

	/**
	 * Whether a cell condition is a recognised shape.
	 *
	 * @param mixed $raw The cell source; null reads as empty, a scalar as its text.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
	 */
	public function isValid(mixed $raw): bool {
		if ($raw !== null && is_scalar($raw) === false) {
			return false;
		}

		$value = $this->source(raw: $raw);
		if (in_array(mb_strtolower($value), self::DONT_CARE, true) === true || $this->hasOperatorShape(value: $value) === true) {
			return true;
		}

		if (preg_match(self::LONE_EQUALS, $value) === 1 || $value === '=') {
			return false;
		}

		return preg_match(self::BARE_LITERAL, $value) === 1;
	}//end isValid()

	/**
	 * The trimmed cell text, '' for none.
	 *
	 * @param mixed $raw A scalar or null.
	 *
	 * @return string
	 */
	private function source(mixed $raw): string {
		if ($raw === null) {
			return '';
		}

		if ($raw === true) {
			return 'true';
		}

		if ($raw === false) {
			return 'false';
		}

		return trim((string)$raw);
	}//end source()

	/**
	 * Whether the text is a comparison, a range or a list membership.
	 *
	 * @param string $value The trimmed cell text.
	 *
	 * @return boolean
	 */
	private function hasOperatorShape(string $value): bool {
		foreach (self::OPERATOR_SHAPES as $shape) {
			if (preg_match($shape, $value) === 1) {
				return true;
			}
		}

		return false;
	}//end hasOperatorShape()
}//end class
