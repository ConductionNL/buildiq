<?php

/**
 * Buildiq CopilotPlanValidator
 *
 * Pure structural validator for a decoded copilot plan `{summary, steps[]}`
 * against the Buildiq MCP tool catalogue
 * (`BuildiqToolProvider::getToolDescriptors()`). Every step's `tool` must
 * be on the allow-list and every step's `arguments` must satisfy the
 * matching tool's JSON-Schema `inputSchema` for the six constraint kinds the
 * descriptors actually use (`required`, `enum`, `pattern`, `type`,
 * `minLength`/`maxLength`, `minimum`/`maximum`) — no JSON-Schema composer
 * dependency is added for this (design.md Decision 3, layer 2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

/**
 * Validates a copilot plan's steps against the tool catalogue's inputSchemas.
 *
 * Stateless / side-effect free: takes the decoded plan and the tool
 * descriptor array, returns a list of violations (empty = valid).
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
class CopilotPlanValidator {
	/**
	 * Validate a decoded plan against the tool catalogue.
	 *
	 * @param array<string, mixed> $plan Decoded plan `{summary, steps[]}`.
	 * @param array<int, array<string, mixed>> $toolDescriptors Tool catalogue (BuildiqToolProvider::getToolDescriptors()).
	 *
	 * @return array<int, array{stepIndex: int, message: string}> Empty when the plan is valid.
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 */
	public function validate(array $plan, array $toolDescriptors): array {
		$steps = ($plan['steps'] ?? null);
		if (is_array($steps) === false) {
			return [['stepIndex' => -1, 'message' => 'Plan is missing a "steps" array.']];
		}

		if ($steps === []) {
			return [['stepIndex' => -1, 'message' => 'Plan has no steps.']];
		}

		return $this->stepViolations(steps: $steps, descriptorsById: $this->descriptorsById(toolDescriptors: $toolDescriptors));
	}//end validate()

	/**
	 * The violations of every step, in step order.
	 *
	 * @param array<array-key, mixed> $steps The plan's steps.
	 * @param array<string, array<string, mixed>> $descriptorsById Tool descriptors keyed by id.
	 *
	 * @return array<int, array{stepIndex: int, message: string}>
	 */
	private function stepViolations(array $steps, array $descriptorsById): array {
		$violations = [];
		foreach (array_values($steps) as $index => $step) {
			$stepViolations = $this->validateStep(step: $step, index: $index, descriptorsById: $descriptorsById);
			$violations = array_merge($violations, $stepViolations);
		}

		return $violations;
	}//end stepViolations()

	/**
	 * The tool descriptors keyed by id; a descriptor without an id is dropped.
	 *
	 * @param array<int, array<string, mixed>> $toolDescriptors Tool catalogue.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function descriptorsById(array $toolDescriptors): array {
		$descriptorsById = [];
		foreach ($toolDescriptors as $descriptor) {
			$id = (string)($descriptor['id'] ?? '');
			if ($id !== '') {
				$descriptorsById[$id] = $descriptor;
			}
		}

		return $descriptorsById;
	}//end descriptorsById()

	/**
	 * Validate a single plan step.
	 *
	 * @param mixed $step Raw step entry.
	 * @param int $index Step index (for the violation report).
	 * @param array<string, array<string, mixed>> $descriptorsById Tool descriptors keyed by id.
	 *
	 * @return array<int, array{stepIndex: int, message: string}>
	 */
	private function validateStep(mixed $step, int $index, array $descriptorsById): array {
		if (is_array($step) === false) {
			return [['stepIndex' => $index, 'message' => 'Step is not an object.']];
		}

		return array_map(
			static fn (string $message): array => ['stepIndex' => $index, 'message' => "Step {$index}: {$message}"],
			$this->stepMessages(step: $step, descriptorsById: $descriptorsById)
		);
	}//end validateStep()

	/**
	 * What is wrong with a step object: an unknown tool, non-object arguments,
	 * or arguments that break the tool's inputSchema.
	 *
	 * @param array<array-key, mixed> $step The step object.
	 * @param array<string, array<string, mixed>> $descriptorsById Tool descriptors keyed by id.
	 *
	 * @return array<int, string> Violation messages, without the step prefix.
	 */
	private function stepMessages(array $step, array $descriptorsById): array {
		$tool = (string)($step['tool'] ?? '');
		if ($tool === '' || isset($descriptorsById[$tool]) === false) {
			$allowed = implode(', ', array_keys($descriptorsById));
			return ["unknown tool '{$tool}'. Allowed tools: {$allowed}."];
		}

		$arguments = ($step['arguments'] ?? []);
		if (is_array($arguments) === false) {
			return ["'arguments' must be an object."];
		}

		$inputSchema = (array)($descriptorsById[$tool]['inputSchema'] ?? []);
		return $this->argumentMessages(arguments: $arguments, schema: $inputSchema);
	}//end stepMessages()

	/**
	 * Validate a step's arguments against its tool's inputSchema.
	 *
	 * @param array<string, mixed> $arguments Step arguments.
	 * @param array<string, mixed> $schema The tool's inputSchema (`{type, properties, required}`).
	 *
	 * @return array<int, string> Violation messages, without the step prefix.
	 */
	private function argumentMessages(array $arguments, array $schema): array {
		$messages = $this->missingRequired(arguments: $arguments, schema: $schema);

		$properties = (array)($schema['properties'] ?? []);
		foreach ($properties as $key => $propertySchema) {
			if (array_key_exists($key, $arguments) === false || is_array($propertySchema) === false) {
				continue;
			}

			$message = $this->validateValue(argument: new PlanArgument(key: (string)$key, value: $arguments[$key], schema: $propertySchema));
			if ($message !== null) {
				$messages[] = $message;
			}
		}

		return $messages;
	}//end argumentMessages()

	/**
	 * One message per required argument the step leaves out.
	 *
	 * @param array<string, mixed> $arguments Step arguments.
	 * @param array<string, mixed> $schema The tool's inputSchema.
	 *
	 * @return array<int, string>
	 */
	private function missingRequired(array $arguments, array $schema): array {
		$messages = [];
		foreach ((array)($schema['required'] ?? []) as $key) {
			if (array_key_exists((string)$key, $arguments) === false) {
				$messages[] = "missing required argument '{$key}'.";
			}
		}

		return $messages;
	}//end missingRequired()

	/**
	 * Validate a single argument value against its property schema.
	 *
	 * Supports the six constraint kinds the descriptors actually use:
	 * `type`, `enum`, `pattern`, `minLength`/`maxLength`, `minimum`/`maximum`.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateValue(PlanArgument $argument): ?string {
		$typeError = $this->validateType(argument: $argument);
		if ($typeError !== null) {
			return $typeError;
		}

		$enumError = $this->validateEnum(argument: $argument);
		if ($enumError !== null) {
			return $enumError;
		}

		return $this->validateKindConstraints(argument: $argument);
	}//end validateValue()

	/**
	 * Validate the JSON-Schema `type` constraint, when the property declares one.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateType(PlanArgument $argument): ?string {
		$type = ($argument->schema['type'] ?? null);
		if ($type === null) {
			return null;
		}

		$type = (string)$type;
		if ($this->matchesType(value: $argument->value, type: $type) === true) {
			return null;
		}

		return "argument '{$argument->key}' must be of type {$type}.";
	}//end validateType()

	/**
	 * Whether a value is of a declared JSON-Schema type; an unknown type matches anything.
	 *
	 * @param mixed $value The argument value.
	 * @param string $type Declared JSON-Schema type.
	 *
	 * @return bool
	 */
	private function matchesType(mixed $value, string $type): bool {
		return match ($type) {
			'string' => is_string($value),
			'integer' => is_int($value),
			'number' => is_int($value) || is_float($value),
			'boolean' => is_bool($value),
			'array' => is_array($value) === true && array_is_list($value),
			'object' => is_array($value),
			default => true,
		};
	}//end matchesType()

	/**
	 * Validate the JSON-Schema `enum` constraint.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateEnum(PlanArgument $argument): ?string {
		$enum = ($argument->schema['enum'] ?? null);
		if (is_array($enum) === true && in_array(needle: $argument->value, haystack: $enum, strict: true) === false) {
			return "argument '{$argument->key}' must be one of: " . implode(', ', array_map('strval', $enum)) . '.';
		}

		return null;
	}//end validateEnum()

	/**
	 * Validate the constraints of the value's own kind: string or number.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateKindConstraints(PlanArgument $argument): ?string {
		$value = $argument->value;
		if (is_string($value) === true) {
			return $this->validateStringConstraints(argument: $argument, value: $value);
		}

		if (is_int($value) === true || is_float($value) === true) {
			return $this->validateNumericConstraints(argument: $argument, value: $value);
		}

		return null;
	}//end validateKindConstraints()

	/**
	 * Validate `pattern`/`minLength`/`maxLength` for a string argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param string $value The argument value.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateStringConstraints(PlanArgument $argument, string $value): ?string {
		$pattern = ($argument->schema['pattern'] ?? null);
		if (is_string($pattern) === true && $pattern !== '' && preg_match('#' . $pattern . '#', $value) !== 1) {
			return "argument '{$argument->key}' does not match the required pattern.";
		}

		return $this->validateLength(argument: $argument, length: strlen($value));
	}//end validateStringConstraints()

	/**
	 * Validate `minLength`/`maxLength` for a string argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param int $length The string's length in bytes.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateLength(PlanArgument $argument, int $length): ?string {
		$tooShort = $this->validateMinLength(argument: $argument, length: $length);
		if ($tooShort !== null) {
			return $tooShort;
		}

		return $this->validateMaxLength(argument: $argument, length: $length);
	}//end validateLength()

	/**
	 * Validate `minLength` for a string argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param int $length The string's length in bytes.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateMinLength(PlanArgument $argument, int $length): ?string {
		$minLength = ($argument->schema['minLength'] ?? null);
		if (is_int($minLength) === true && $length < $minLength) {
			return "argument '{$argument->key}' must be at least {$minLength} characters.";
		}

		return null;
	}//end validateMinLength()

	/**
	 * Validate `maxLength` for a string argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param int $length The string's length in bytes.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateMaxLength(PlanArgument $argument, int $length): ?string {
		$maxLength = ($argument->schema['maxLength'] ?? null);
		if (is_int($maxLength) === true && $length > $maxLength) {
			return "argument '{$argument->key}' must be at most {$maxLength} characters.";
		}

		return null;
	}//end validateMaxLength()

	/**
	 * Validate `minimum`/`maximum` for a numeric argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param int|float $value The argument value.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateNumericConstraints(PlanArgument $argument, int|float $value): ?string {
		$tooSmall = $this->validateMinimum(argument: $argument, value: $value);
		if ($tooSmall !== null) {
			return $tooSmall;
		}

		return $this->validateMaximum(argument: $argument, value: $value);
	}//end validateNumericConstraints()

	/**
	 * Validate `minimum` for a numeric argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param int|float $value The argument value.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateMinimum(PlanArgument $argument, int|float $value): ?string {
		$minimum = ($argument->schema['minimum'] ?? null);
		if (is_numeric($minimum) === true && $value < $minimum) {
			return "argument '{$argument->key}' must be >= {$minimum}.";
		}

		return null;
	}//end validateMinimum()

	/**
	 * Validate `maximum` for a numeric argument.
	 *
	 * @param PlanArgument $argument The argument and its property schema.
	 * @param int|float $value The argument value.
	 *
	 * @return string|null Violation message, or null when valid.
	 */
	private function validateMaximum(PlanArgument $argument, int|float $value): ?string {
		$maximum = ($argument->schema['maximum'] ?? null);
		if (is_numeric($maximum) === true && $value > $maximum) {
			return "argument '{$argument->key}' must be <= {$maximum}.";
		}

		return null;
	}//end validateMaximum()
}//end class
