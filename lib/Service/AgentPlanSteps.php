<?php

/**
 * The steps of an agent plan: which builder tools write, how a step is checked and compared.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The pure step logic of an agent plan: which builder tools write, whether a
 * step list is a valid plan for one app, and how a write call is compared
 * with a stored step (tool and canonical arguments, key order ignored).
 *
 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
 */
final class AgentPlanSteps {

	/**
	 * The builder tools that write. Every other builder tool only reads.
	 */
	public const WRITE_TOOLS = [
		'buildiq.createApp',
		'buildiq.promoteVersion',
		'buildiq.upsertSchema',
		'buildiq.upsertPage',
		'buildiq.addWidget',
		'buildiq.upsertMenuItem',
	];

	/**
	 * The most steps one plan may hold.
	 */
	public const MAX_STEPS = 50;

	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Whether a builder tool writes.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function isWriteTool(string $toolId): bool {
		return in_array(needle: $toolId, haystack: self::WRITE_TOOLS, strict: true);
	}//end isWriteTool()

	/**
	 * The steps as stored: the tool and its arguments without any planId.
	 *
	 * @param array<int, array<string, mixed>> $steps The validated steps.
	 *
	 * @return array<int, array{tool: string, arguments: array<string, mixed>}>
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function normalisedSteps(array $steps): array {
		$normalised = [];
		foreach ($steps as $step) {
			$normalised[] = ['tool' => (string)$step['tool'], 'arguments' => self::withoutPlanId(arguments: (array)$step['arguments'])];
		}

		return $normalised;
	}//end normalisedSteps()

	/**
	 * A tool call as one comparable key: the tool and its canonical arguments.
	 *
	 * @param string               $toolId    The tool.
	 * @param array<string, mixed> $arguments Its arguments, without a planId.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function callKey(string $toolId, array $arguments): string {
		return $toolId . "\0" . self::canonical(value: $arguments);
	}//end callKey()

	/**
	 * The arguments without the `planId` an MCP call carries.
	 *
	 * @param array<string,mixed> $arguments The call's arguments.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function withoutPlanId(array $arguments): array {
		unset($arguments['planId']);
		return $arguments;
	}//end withoutPlanId()

	/**
	 * Why a step list is not a valid plan for the app, or null when it is.
	 *
	 * @param string $appSlug The app every step targets.
	 * @param array  $steps   The steps.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function stepsError(string $appSlug, array $steps): ?string {
		$shape = self::shapeError(appSlug: $appSlug, steps: $steps);
		if ($shape !== null) {
			return $shape;
		}

		foreach ($steps as $index => $step) {
			$error = self::stepError(appSlug: $appSlug, step: $step);
			if ($error !== null) {
				return 'Step ' . $index . ' ' . $error;
			}
		}

		return null;
	}//end stepsError()

	/**
	 * Why the app slug or the step list's shape is not a plan's, or null.
	 *
	 * @param string $appSlug The app every step targets.
	 * @param array  $steps   The steps.
	 *
	 * @return string|null
	 */
	private static function shapeError(string $appSlug, array $steps): ?string {
		if (preg_match(self::SLUG_PATTERN, $appSlug) !== 1) {
			return 'appSlug must be a kebab-case app slug.';
		}

		if ($steps === [] || count($steps) > self::MAX_STEPS || array_is_list($steps) === false) {
			return 'A plan holds 1 to ' . self::MAX_STEPS . ' steps.';
		}

		return null;
	}//end shapeError()

	/**
	 * Why one step is not a write call on the app, or null when it is.
	 *
	 * @param string $appSlug The app every step targets.
	 * @param mixed  $step    The step.
	 *
	 * @return string|null The reason, to follow "Step <index> ".
	 */
	private static function stepError(string $appSlug, mixed $step): ?string {
		if (is_array($step) === false) {
			$step = [];
		}

		$tool = ($step['tool'] ?? null);
		$arguments = ($step['arguments'] ?? null);
		if (self::isWriteCall(tool: $tool, arguments: $arguments) === false) {
			return 'must be {tool, arguments} with tool one of: ' . implode(', ', self::WRITE_TOOLS) . '.';
		}

		if (self::targetApp(toolId: $tool, arguments: $arguments) !== $appSlug) {
			return 'targets another app than ' . $appSlug . '.';
		}

		return null;
	}//end stepError()

	/**
	 * Whether a tool and its arguments form a builder write call.
	 *
	 * @param mixed $tool      The tool id.
	 * @param mixed $arguments The arguments.
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true string $tool
	 * @psalm-assert-if-true array $arguments
	 */
	private static function isWriteCall(mixed $tool, mixed $arguments): bool {
		return is_string($tool) === true && self::isWriteTool(toolId: $tool) === true && is_array($arguments) === true;
	}//end isWriteCall()

	/**
	 * The app a write call targets: createApp names it `slug`, the others `appSlug`.
	 *
	 * @param string              $toolId    The tool id.
	 * @param array<string,mixed> $arguments The call's arguments.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function targetApp(string $toolId, array $arguments): string {
		$key = 'appSlug';
		if ($toolId === 'buildiq.createApp') {
			$key = 'slug';
		}

		return (string)($arguments[$key] ?? '');
	}//end targetApp()

	/**
	 * The index of the first unused step equal to this call, or null.
	 *
	 * @param array<string,mixed> $data   The plan.
	 * @param string              $wanted The call's key (callKey()): the tool and its arguments without a planId.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function matchingStep(array $data, string $wanted): ?int {
		$used = array_map('intval', (array)($data['usedSteps'] ?? []));
		foreach ((array)($data['steps'] ?? []) as $index => $step) {
			if (self::isOpenStep(step: $step, used: in_array((int)$index, $used, true)) === true
				&& self::callKey(toolId: $step['tool'], arguments: (array)($step['arguments'] ?? [])) === $wanted
			) {
				return (int)$index;
			}
		}

		return null;
	}//end matchingStep()

	/**
	 * Whether a step is unused and names a tool.
	 *
	 * @param mixed $step The step.
	 * @param bool  $used Whether the step was used already.
	 *
	 * @return bool
	 */
	private static function isOpenStep(mixed $step, bool $used): bool {
		if ($used === true || is_array($step) === false) {
			return false;
		}

		return is_string($step['tool'] ?? null);
	}//end isOpenStep()

	/**
	 * A key-order independent encoding of a value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public static function canonical(mixed $value): string {
		return (string)json_encode(self::sorted(value: $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end canonical()

	/**
	 * The value with every object's keys sorted, lists kept in order.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			$out[$key] = self::sorted(value: $item);
		}

		if (array_is_list($out) === false) {
			ksort($out, SORT_STRING);
		}

		return $out;
	}//end sorted()
}//end class
