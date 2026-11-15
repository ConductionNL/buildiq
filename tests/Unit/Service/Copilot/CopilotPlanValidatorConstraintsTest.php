<?php

/**
 * Unit tests for the argument checks of CopilotPlanValidator that its first
 * tests leave out: every JSON Schema type, maximum length, numeric bounds,
 * the order the checks run in, a non-object arguments value, and descriptors
 * without an id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use OCA\Buildiq\Service\Copilot\CopilotPlanValidator;
use PHPUnit\Framework\TestCase;

/**
 * Argument constraints of CopilotPlanValidator.
 *
 * @covers \OCA\Buildiq\Service\Copilot\CopilotPlanValidator
 * @uses   \OCA\Buildiq\Service\Copilot\PlanArgument
 */
final class CopilotPlanValidatorConstraintsTest extends TestCase {

	/**
	 * The messages for one step calling the probe tool with the given arguments.
	 *
	 * @param array<string,mixed> $properties The probe tool's property schemas.
	 * @param mixed               $arguments  The step's arguments.
	 *
	 * @return array<int,string> The violation messages.
	 */
	private function messages(array $properties, mixed $arguments): array {
		$violations = (new CopilotPlanValidator())->validate(
			plan: ['steps' => [['tool' => 'probe', 'arguments' => $arguments]]],
			toolDescriptors: [['id' => 'probe', 'inputSchema' => ['properties' => $properties]]]
		);

		return array_column($violations, 'message');
	}//end messages()

	/**
	 * Each declared type accepts its own values and refuses the others.
	 *
	 * @return void
	 */
	public function testEachTypeAcceptsOnlyItsValues(): void {
		$cases = [
			'string'  => [['a', ''], [1, null]],
			'integer' => [[1, -3], [1.5, '1']],
			'number'  => [[1, 1.5], ['1', true]],
			'boolean' => [[true, false], [0, 'true']],
			'array'   => [[[], [1, 2]], [['a' => 1], 'x']],
			'object'  => [[['a' => 1], []], ['x', 3]],
		];

		foreach ($cases as $type => [$accepted, $refused]) {
			foreach ($accepted as $value) {
				self::assertSame([], $this->messages(['v' => ['type' => $type]], ['v' => $value]), $type.' accepts '.json_encode($value));
			}

			foreach ($refused as $value) {
				self::assertSame(
					["Step 0: argument 'v' must be of type {$type}."],
					$this->messages(['v' => ['type' => $type]], ['v' => $value]),
					$type.' refuses '.json_encode($value)
				);
			}
		}
	}//end testEachTypeAcceptsOnlyItsValues()

	/**
	 * An unknown type, or none, accepts anything.
	 *
	 * @return void
	 */
	public function testAnUnknownOrMissingTypeAcceptsAnything(): void {
		self::assertSame([], $this->messages(['v' => ['type' => 'null']], ['v' => 'x']));
		self::assertSame([], $this->messages(['v' => []], ['v' => ['nested' => true]]));
	}//end testAnUnknownOrMissingTypeAcceptsAnything()

	/**
	 * A string longer than maxLength is refused; exactly maxLength is accepted.
	 *
	 * @return void
	 */
	public function testMaxLengthIsInclusive(): void {
		$schema = ['v' => ['type' => 'string', 'maxLength' => 3]];

		self::assertSame([], $this->messages($schema, ['v' => 'abc']));
		self::assertSame(["Step 0: argument 'v' must be at most 3 characters."], $this->messages($schema, ['v' => 'abcd']));
	}//end testMaxLengthIsInclusive()

	/**
	 * Numbers outside minimum and maximum are refused; the bounds themselves are accepted.
	 *
	 * @return void
	 */
	public function testNumericBoundsAreInclusive(): void {
		$schema = ['v' => ['type' => 'number', 'minimum' => 1, 'maximum' => 10]];

		self::assertSame([], $this->messages($schema, ['v' => 1]));
		self::assertSame([], $this->messages($schema, ['v' => 10.0]));
		self::assertSame(["Step 0: argument 'v' must be >= 1."], $this->messages($schema, ['v' => 0.5]));
		self::assertSame(["Step 0: argument 'v' must be <= 10."], $this->messages($schema, ['v' => 11]));
	}//end testNumericBoundsAreInclusive()

	/**
	 * A wrong type is the only message: enum and length checks do not run on it.
	 *
	 * @return void
	 */
	public function testATypeErrorStopsTheOtherChecks(): void {
		self::assertSame(
			["Step 0: argument 'v' must be of type string."],
			$this->messages(['v' => ['type' => 'string', 'enum' => ['a'], 'minLength' => 5]], ['v' => 7])
		);
	}//end testATypeErrorStopsTheOtherChecks()

	/**
	 * An enum value outside the list is the only message, even when it is also too short.
	 *
	 * @return void
	 */
	public function testAnEnumErrorStopsTheLengthCheck(): void {
		self::assertSame(
			["Step 0: argument 'v' must be one of: index, detail."],
			$this->messages(['v' => ['enum' => ['index', 'detail'], 'minLength' => 5]], ['v' => 'x'])
		);
	}//end testAnEnumErrorStopsTheLengthCheck()

	/**
	 * An enum compares strictly: the string form of a listed number is refused.
	 *
	 * @return void
	 */
	public function testTheEnumComparesStrictly(): void {
		self::assertSame(
			["Step 0: argument 'v' must be one of: 1, 2."],
			$this->messages(['v' => ['enum' => [1, 2]]], ['v' => '1'])
		);
	}//end testTheEnumComparesStrictly()

	/**
	 * Arguments that are not an object are refused for the step.
	 *
	 * @return void
	 */
	public function testNonObjectArgumentsAreRefused(): void {
		self::assertSame(["Step 0: 'arguments' must be an object."], $this->messages([], 'slug=x'));
	}//end testNonObjectArgumentsAreRefused()

	/**
	 * Undeclared arguments are not checked, and declared ones absent from the call are skipped.
	 *
	 * @return void
	 */
	public function testOnlyDeclaredAndPresentArgumentsAreChecked(): void {
		self::assertSame([], $this->messages(['v' => ['type' => 'string']], ['other' => 3]));
	}//end testOnlyDeclaredAndPresentArgumentsAreChecked()

	/**
	 * A descriptor without an id is not a tool, and the allowed list names only the real ones.
	 *
	 * @return void
	 */
	public function testDescriptorsWithoutAnIdAreNotTools(): void {
		$violations = (new CopilotPlanValidator())->validate(
			plan: ['steps' => [['tool' => '', 'arguments' => []], ['arguments' => []]]],
			toolDescriptors: [['inputSchema' => []], ['id' => 'buildiq.a'], ['id' => 'buildiq.b']]
		);

		self::assertSame(
			[
				['stepIndex' => 0, 'message' => "Step 0: unknown tool ''. Allowed tools: buildiq.a, buildiq.b."],
				['stepIndex' => 1, 'message' => "Step 1: unknown tool ''. Allowed tools: buildiq.a, buildiq.b."],
			],
			$violations
		);
	}//end testDescriptorsWithoutAnIdAreNotTools()

	/**
	 * Steps keyed by name are numbered by position.
	 *
	 * @return void
	 */
	public function testStepsAreNumberedByPosition(): void {
		$violations = (new CopilotPlanValidator())->validate(
			plan: ['steps' => ['first' => ['tool' => 'probe'], 'second' => 'not a step']],
			toolDescriptors: [['id' => 'probe']]
		);

		self::assertSame([['stepIndex' => 1, 'message' => 'Step is not an object.']], $violations);
	}//end testStepsAreNumberedByPosition()
}//end class
