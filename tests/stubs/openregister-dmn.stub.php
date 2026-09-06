<?php

/**
 * Analysis stub for OpenRegister's shared DMN evaluator (ADR-065).
 *
 * Analysis-only — referenced from psalm.xml `<stubs>` and phpstan.neon
 * `scanFiles`, and NEVER loaded at runtime. The runtime stubs live in
 * tests/stubs/openregister-stubs.php, guarded by class_exists.
 *
 * This is a stub rather than a `referencedClass` suppression because
 * DecisionTableEvaluator's refusal is CAUGHT and rethrown by buildiq's adapter.
 * Suppressing UndefinedClass would leave psalm reporting `InvalidThrow` on the
 * rethrow, since it would still not know the class is Throwable. Declaring the
 * shape says the true thing instead of silencing the complaint.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

/**
 * OpenRegister's decision-evaluation refusal.
 */
class DecisionEvaluationException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string               $errorCode Stable machine-readable code.
	 * @param array<string, mixed> $details   Structured details.
	 */
	public function __construct(string $errorCode, array $details = []) {
	}

	/**
	 * The stable error code.
	 *
	 * @return string
	 */
	public function getErrorCode(): string {
	}

	/**
	 * Structured details on the refusal.
	 *
	 * @return array<string, mixed>
	 */
	public function getDetails(): array {
	}
}

/**
 * OpenRegister's unary-test (cell expression) evaluator.
 */
class UnaryTestEvaluator {

	/**
	 * The column types the evaluator accepts.
	 *
	 * @var array<int, string>
	 */
	public const VALID_TYPES = ['string', 'number', 'boolean', 'date'];
}

/**
 * OpenRegister's shared rule matcher and hit-policy engine.
 */
class DecisionTableEvaluator {

	/**
	 * Constructor.
	 *
	 * @param UnaryTestEvaluator|null $evaluator The cell evaluator.
	 */
	public function __construct(?UnaryTestEvaluator $evaluator = null) {
	}

	/**
	 * Evaluate a decision table.
	 *
	 * @param array<string, mixed> $decisionTable The table definition.
	 * @param array<string, mixed> $inputs        Named input values.
	 *
	 * @return array{outputs: array<string, mixed>, matchedRuleIds: array<int, string>, hitPolicy: string}
	 */
	public function evaluate(array $decisionTable, array $inputs): array {
	}
}
