<?php

/**
 * OpenBuild CopilotPlanValidator
 *
 * Pure structural validator for a decoded copilot plan `{summary, steps[]}`
 * against the OpenBuild MCP tool catalogue
 * (`OpenBuildToolProvider::getToolDescriptors()`). Every step's `tool` must
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
 * @package  OCA\OpenBuild\Service\Copilot
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

namespace OCA\OpenBuild\Service\Copilot;

/**
 * Validates a copilot plan's steps against the tool catalogue's inputSchemas.
 *
 * Stateless / side-effect free: takes the decoded plan and the tool
 * descriptor array, returns a list of violations (empty = valid).
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
class CopilotPlanValidator
{
    /**
     * Validate a decoded plan against the tool catalogue.
     *
     * @param array<string, mixed>             $plan            Decoded plan `{summary, steps[]}`.
     * @param array<int, array<string, mixed>> $toolDescriptors Tool catalogue (OpenBuildToolProvider::getToolDescriptors()).
     *
     * @return array<int, array{stepIndex: int, message: string}> Empty when the plan is valid.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     */
    public function validate(array $plan, array $toolDescriptors): array
    {
        $violations = [];

        $steps = ($plan['steps'] ?? null);
        if (is_array($steps) === false) {
            return [['stepIndex' => -1, 'message' => 'Plan is missing a "steps" array.']];
        }

        if ($steps === []) {
            return [['stepIndex' => -1, 'message' => 'Plan has no steps.']];
        }

        $descriptorsById = [];
        foreach ($toolDescriptors as $descriptor) {
            $id = (string) ($descriptor['id'] ?? '');
            if ($id !== '') {
                $descriptorsById[$id] = $descriptor;
            }
        }

        foreach (array_values($steps) as $index => $step) {
            $stepViolations = $this->validateStep(step: $step, index: $index, descriptorsById: $descriptorsById);
            $violations     = array_merge($violations, $stepViolations);
        }

        return $violations;
    }//end validate()

    /**
     * Validate a single plan step.
     *
     * @param mixed                               $step            Raw step entry.
     * @param int                                 $index           Step index (for the violation report).
     * @param array<string, array<string, mixed>> $descriptorsById Tool descriptors keyed by id.
     *
     * @return array<int, array{stepIndex: int, message: string}>
     */
    private function validateStep(mixed $step, int $index, array $descriptorsById): array
    {
        if (is_array($step) === false) {
            return [['stepIndex' => $index, 'message' => 'Step is not an object.']];
        }

        $tool = (string) ($step['tool'] ?? '');
        if ($tool === '' || isset($descriptorsById[$tool]) === false) {
            $allowed = implode(', ', array_keys($descriptorsById));
            return [
                [
                    'stepIndex' => $index,
                    'message'   => "Step {$index}: unknown tool '{$tool}'. Allowed tools: {$allowed}.",
                ],
            ];
        }

        $arguments = ($step['arguments'] ?? []);
        if (is_array($arguments) === false) {
            return [['stepIndex' => $index, 'message' => "Step {$index}: 'arguments' must be an object."]];
        }

        $inputSchema = (array) ($descriptorsById[$tool]['inputSchema'] ?? []);
        return $this->validateArguments(arguments: $arguments, schema: $inputSchema, index: $index);
    }//end validateStep()

    /**
     * Validate a step's arguments against its tool's inputSchema.
     *
     * @param array<string, mixed> $arguments Step arguments.
     * @param array<string, mixed> $schema    The tool's inputSchema (`{type, properties, required}`).
     * @param int                  $index     Step index (for the violation report).
     *
     * @return array<int, array{stepIndex: int, message: string}>
     */
    private function validateArguments(array $arguments, array $schema, int $index): array
    {
        $violations = [];

        $required = (array) ($schema['required'] ?? []);
        foreach ($required as $key) {
            if (array_key_exists((string) $key, $arguments) === false) {
                $violations[] = [
                    'stepIndex' => $index,
                    'message'   => "Step {$index}: missing required argument '{$key}'.",
                ];
            }
        }

        $properties = (array) ($schema['properties'] ?? []);
        foreach ($properties as $key => $propertySchema) {
            if (array_key_exists($key, $arguments) === false || is_array($propertySchema) === false) {
                continue;
            }

            $message = $this->validateValue(value: $arguments[$key], propertySchema: $propertySchema, key: (string) $key);
            if ($message !== null) {
                $violations[] = ['stepIndex' => $index, 'message' => "Step {$index}: {$message}"];
            }
        }

        return $violations;
    }//end validateArguments()

    /**
     * Validate a single argument value against its property schema.
     *
     * Supports the six constraint kinds the descriptors actually use:
     * `type`, `enum`, `pattern`, `minLength`/`maxLength`, `minimum`/`maximum`.
     *
     * @param mixed                $value          The argument value.
     * @param array<string, mixed> $propertySchema The property's JSON-Schema fragment.
     * @param string               $key            Argument name (for the violation message).
     *
     * @return string|null Violation message, or null when valid.
     */
    private function validateValue(mixed $value, array $propertySchema, string $key): ?string
    {
        $type = ($propertySchema['type'] ?? null);
        if ($type !== null) {
            $typeError = $this->validateType(value: $value, type: (string) $type, key: $key);
            if ($typeError !== null) {
                return $typeError;
            }
        }

        $enum = ($propertySchema['enum'] ?? null);
        if (is_array($enum) === true && in_array(needle: $value, haystack: $enum, strict: true) === false) {
            return "argument '{$key}' must be one of: ".implode(', ', array_map('strval', $enum)).'.';
        }

        if (is_string($value) === true) {
            $stringError = $this->validateStringConstraints(value: $value, propertySchema: $propertySchema, key: $key);
            if ($stringError !== null) {
                return $stringError;
            }
        }

        if (is_int($value) === true || is_float($value) === true) {
            return $this->validateNumericConstraints(value: $value, propertySchema: $propertySchema, key: $key);
        }

        return null;
    }//end validateValue()

    /**
     * Validate the JSON-Schema `type` constraint.
     *
     * @param mixed  $value The argument value.
     * @param string $type  Declared JSON-Schema type.
     * @param string $key   Argument name (for the violation message).
     *
     * @return string|null Violation message, or null when valid.
     */
    private function validateType(mixed $value, string $type, string $key): ?string
    {
        $matches = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) === true && array_is_list($value),
            'object' => is_array($value),
            default => true,
        };

        if ($matches === true) {
            return null;
        }

        return "argument '{$key}' must be of type {$type}.";
    }//end validateType()

    /**
     * Validate `pattern`/`minLength`/`maxLength` for a string argument.
     *
     * @param string               $value          The argument value.
     * @param array<string, mixed> $propertySchema The property's JSON-Schema fragment.
     * @param string               $key            Argument name (for the violation message).
     *
     * @return string|null Violation message, or null when valid.
     */
    private function validateStringConstraints(string $value, array $propertySchema, string $key): ?string
    {
        $pattern = ($propertySchema['pattern'] ?? null);
        if (is_string($pattern) === true && $pattern !== '' && preg_match('#'.$pattern.'#', $value) !== 1) {
            return "argument '{$key}' does not match the required pattern.";
        }

        $minLength = ($propertySchema['minLength'] ?? null);
        if (is_int($minLength) === true && strlen($value) < $minLength) {
            return "argument '{$key}' must be at least {$minLength} characters.";
        }

        $maxLength = ($propertySchema['maxLength'] ?? null);
        if (is_int($maxLength) === true && strlen($value) > $maxLength) {
            return "argument '{$key}' must be at most {$maxLength} characters.";
        }

        return null;
    }//end validateStringConstraints()

    /**
     * Validate `minimum`/`maximum` for a numeric argument.
     *
     * @param int|float            $value          The argument value.
     * @param array<string, mixed> $propertySchema The property's JSON-Schema fragment.
     * @param string               $key            Argument name (for the violation message).
     *
     * @return string|null Violation message, or null when valid.
     */
    private function validateNumericConstraints(int|float $value, array $propertySchema, string $key): ?string
    {
        $minimum = ($propertySchema['minimum'] ?? null);
        if (is_numeric($minimum) === true && $value < $minimum) {
            return "argument '{$key}' must be >= {$minimum}.";
        }

        $maximum = ($propertySchema['maximum'] ?? null);
        if (is_numeric($maximum) === true && $value > $maximum) {
            return "argument '{$key}' must be <= {$maximum}.";
        }

        return null;
    }//end validateNumericConstraints()
}//end class
