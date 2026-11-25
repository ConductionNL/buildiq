<?php

/**
 * Buildiq CopilotAssistService
 *
 * The builder assist: a maker writing a FEEL condition, a decision table row,
 * a field mapping or a webhook payload template asks the language model to
 * write it from a plain request (`write`) or to explain the current value
 * (`explain`). The answer is checked before it is returned: a condition must
 * parse with FeelParser, every cell of a row must pass the cell grammar, a
 * mapping must be JSON whose keys are properties of the target schema, a
 * payload template must be JSON. A refused answer gets one repair round-trip;
 * a second refusal is the 422 `assist_invalid` envelope with the checker's
 * message. The assist writes nothing.
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
 * @spec openspec/specs/ai-builder-assist/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use InvalidArgumentException;
use OCA\Buildiq\Exception\CopilotException;
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Service\CopilotService;
use OCA\Buildiq\Service\FeelCellCondition;
use OCA\Buildiq\Service\FeelParser;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Throwable;
use OCA\Buildiq\Support\SlugSearch;

/**
 * Proposes or explains one builder expression, checked before it is returned.
 *
 * @spec openspec/specs/ai-builder-assist/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) SlugSearch is a call adapter over
 *   OpenRegister's slug search (one published method, two signatures), not a
 *   collaborator of its own; it took this class from 12 to 13.
 */
class CopilotAssistService {

	/**
	 * The editor fields the assist can write.
	 *
	 * @var string[]
	 */
	public const TARGETS = ['feel-condition', 'decision-rule', 'field-mapping', 'payload-template'];

	/**
	 * Write a value from the request, or explain the current one.
	 *
	 * @var string[]
	 */
	public const MODES = ['write', 'explain'];

	/**
	 * Longest request or current value the assist reads.
	 *
	 * @var integer
	 */
	public const MAX_TEXT_LENGTH = 2000;

	/**
	 * Most names of one kind (columns, paths, properties) put into a prompt.
	 *
	 * @var integer
	 */
	private const MAX_NAMES = 200;

	/**
	 * Longest single name put into a prompt.
	 *
	 * @var integer
	 */
	private const MAX_NAME_LENGTH = 128;

	/**
	 * Register carrying Application and ApplicationVersion rows.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema of an ApplicationVersion row.
	 *
	 * @var string
	 */
	private const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Constructor.
	 *
	 * @param CopilotService                     $copilotService Availability and the app write-role check.
	 * @param CopilotTextTask                    $textTask       The language model seam.
	 * @param FeelParser                         $feelParser     The server FEEL grammar.
	 * @param FeelCellCondition                  $cellCondition  The decision-table cell grammar.
	 * @param RegistrationFormTargetSchemaReader $schemaReader   Reads a schema's property names.
	 * @param ObjectServiceInterface             $objectService  Reads the app's versions.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CopilotService $copilotService,
		private readonly CopilotTextTask $textTask,
		private readonly FeelParser $feelParser,
		private readonly FeelCellCondition $cellCondition,
		private readonly RegistrationFormTargetSchemaReader $schemaReader,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Propose a checked value, or explain the current one.
	 *
	 * @param string               $target  One of {@see self::TARGETS}.
	 * @param string               $mode    One of {@see self::MODES}.
	 * @param string               $request The maker's plain request (write mode).
	 * @param string               $current The editor's current value (explain mode; context in write mode).
	 * @param string               $appSlug The app being edited.
	 * @param array<string, mixed> $context Names only: `columns`, `paths`, `register`, `targetSchema`, `triggerSchema`.
	 * @param string               $userId  Acting user's UID.
	 *
	 * @return array{mode: string, explanation: string, value?: mixed}
	 *
	 * @throws CopilotException 422 on bad arguments or a twice-refused answer, 403/404 on scope, 503 unavailable, 502 provider.
	 *
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-explains-the-current-value-req-bqch-003
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
	 */
	public function assist(string $target, string $mode, string $request, string $current, string $appSlug, array $context, string $userId): array {
		$this->assertArguments(target: $target, mode: $mode, request: $request, current: $current, appSlug: $appSlug);

		$app = $this->copilotService->requireWritableApp(appSlug: $appSlug, userId: $userId);

		$names = $this->contextNames(target: $target, context: $context);
		if ($target === 'field-mapping' && $mode === 'write') {
			$names['properties'] = $this->targetSchemaProperties(app: $app, context: $context);
		}

		$manager = $this->requireManager();

		$prompt = $this->buildPrompt(target: $target, mode: $mode, request: $request, current: $current, names: $names);
		$raw = $this->textTask->run(manager: $manager, prompt: $prompt, userId: $userId, appSlug: $appSlug);
		[$answer, $error] = $this->checkAnswer(target: $target, mode: $mode, raw: $raw, names: $names);
		if ($answer !== null) {
			return $answer;
		}

		// Exactly one repair round-trip, as the plan has (REQ-OBAIC-002).
		$repair = $this->buildRepairPrompt(prompt: $prompt, previous: $raw, error: $error);
		$raw = $this->textTask->run(manager: $manager, prompt: $repair, userId: $userId, appSlug: $appSlug);
		[$answer, $error] = $this->checkAnswer(target: $target, mode: $mode, raw: $raw, names: $names);
		if ($answer !== null) {
			return $answer;
		}

		throw new CopilotException(
			errorCode: 'assist_invalid',
			message: $error,
			httpStatus: 422
		);
	}//end assist()

	/**
	 * The text-task manager, or a 503 when the copilot is not available here.
	 *
	 * @return mixed The manager the text task runs on.
	 *
	 * @throws CopilotException (503) When the copilot is not available on this server.
	 */
	private function requireManager(): mixed {
		$health = $this->copilotService->health();
		$manager = null;
		if (($health['available'] ?? false) === true) {
			$manager = $this->textTask->manager();
		}

		if ($manager === null) {
			throw new CopilotException(
				errorCode: (string)($health['reason'] ?? 'unsupported_server'),
				message: 'The AI copilot is not available on this server.',
				httpStatus: 503
			);
		}

		return $manager;
	}//end requireManager()

	/**
	 * Check one model answer and shape it for the dialog.
	 *
	 * @param string                  $target The editor field.
	 * @param string                  $mode   `write` or `explain`.
	 * @param string                  $raw    The model's text.
	 * @param array<string, string[]> $names  The names the prompt carried.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string} `[answer|null, checker message]`.
	 *
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
	 */
	public function checkAnswer(string $target, string $mode, string $raw, array $names): array {
		$decoded = $this->decodeObject(text: $raw);
		[$explanation, $error] = $this->explanationOf(decoded: $decoded);
		if ($explanation === null) {
			return [null, $error];
		}

		if ($mode === 'explain') {
			return [['mode' => 'explain', 'explanation' => $explanation], ''];
		}

		if (array_key_exists('value', $decoded) === false) {
			return [null, 'The answer has no value.'];
		}

		[$value, $error] = match ($target) {
			'feel-condition' => $this->checkCondition(value: $decoded['value']),
			'decision-rule' => $this->checkDecisionRule(value: $decoded['value'], columns: ($names['columns'] ?? [])),
			'field-mapping' => $this->checkFieldMapping(value: $decoded['value'], properties: ($names['properties'] ?? [])),
			default => $this->checkPayloadTemplate(value: $decoded['value']),
		};

		if ($error !== '') {
			return [null, $error];
		}

		return [['mode' => 'write', 'value' => $value, 'explanation' => $explanation], ''];
	}//end checkAnswer()

	/**
	 * The trimmed explanation of a decoded answer, or null and why not.
	 *
	 * @param array<string, mixed>|null $decoded The decoded answer, null when it was not a JSON object.
	 *
	 * @return array{0: string|null, 1: string} The explanation (or null) and the error.
	 */
	private function explanationOf(?array $decoded): array {
		if ($decoded === null) {
			return [null, 'The answer was not a JSON object.'];
		}

		$explanation = $decoded['explanation'] ?? '';
		if (is_string($explanation) === false || trim($explanation) === '') {
			return [null, 'The answer has no explanation.'];
		}

		return [trim($explanation), ''];
	}//end explanationOf()

	/**
	 * Refuse a target, mode, request or app slug the assist cannot take.
	 *
	 * @param string $target  The editor field.
	 * @param string $mode    `write` or `explain`.
	 * @param string $request The maker's request.
	 * @param string $current The current value.
	 * @param string $appSlug The app slug.
	 *
	 * @return void
	 *
	 * @throws CopilotException (422 invalid_arguments).
	 */
	private function assertArguments(string $target, string $mode, string $request, string $current, string $appSlug): void {
		$problem = $this->shapeProblem(target: $target, mode: $mode, appSlug: $appSlug);
		if ($problem === null) {
			$problem = $this->textProblem(mode: $mode, request: $request, current: $current);
		}

		if ($problem !== null) {
			throw new CopilotException(errorCode: 'invalid_arguments', message: $problem, httpStatus: 422);
		}
	}//end assertArguments()

	/**
	 * What is wrong with the target, mode or app slug, or null.
	 *
	 * @param string $target  The editor field.
	 * @param string $mode    `write` or `explain`.
	 * @param string $appSlug The app slug.
	 *
	 * @return string|null
	 */
	private function shapeProblem(string $target, string $mode, string $appSlug): ?string {
		if (in_array($target, self::TARGETS, true) === false) {
			return 'target must be one of: ' . implode(', ', self::TARGETS) . '.';
		}

		if (in_array($mode, self::MODES, true) === false) {
			return 'mode must be write or explain.';
		}

		if ($appSlug === '') {
			return 'appSlug is required.';
		}

		return null;
	}//end shapeProblem()

	/**
	 * What is wrong with the request or the current value for the mode, or null.
	 *
	 * @param string $mode    `write` or `explain`.
	 * @param string $request The maker's request.
	 * @param string $current The current value.
	 *
	 * @return string|null
	 */
	private function textProblem(string $mode, string $request, string $current): ?string {
		if ($mode === 'write' && (trim($request) === '' || mb_strlen($request) > self::MAX_TEXT_LENGTH)) {
			return 'The request must be between 1 and ' . self::MAX_TEXT_LENGTH . ' characters.';
		}

		if ($mode === 'explain' && trim($current) === '') {
			return 'There is nothing to explain yet.';
		}

		if (mb_strlen($current) > self::MAX_TEXT_LENGTH) {
			return 'The current value is longer than ' . self::MAX_TEXT_LENGTH . ' characters.';
		}

		return null;
	}//end textProblem()

	/**
	 * The names the prompt may carry, read from the request context: strings
	 * only, trimmed, de-duplicated and capped. Never record values.
	 *
	 * @param string               $target  The editor field.
	 * @param array<string, mixed> $context The request context.
	 *
	 * @return array<string, string[]>
	 *
	 * @throws CopilotException (422 invalid_arguments) When a decision row has no input columns.
	 */
	private function contextNames(string $target, array $context): array {
		$names = [
			'columns' => $this->nameList(raw: ($context['columns'] ?? [])),
			'paths' => $this->nameList(raw: ($context['paths'] ?? [])),
			'triggerSchema' => $this->nameList(raw: [($context['triggerSchema'] ?? '')]),
			'targetSchema' => $this->nameList(raw: [($context['targetSchema'] ?? '')]),
		];

		if ($target === 'decision-rule' && $names['columns'] === []) {
			throw new CopilotException(
				errorCode: 'invalid_arguments',
				message: 'Add an input column before asking for a rule.',
				httpStatus: 422
			);
		}

		return $names;
	}//end contextNames()

	/**
	 * A clean list of names from a raw request value.
	 *
	 * @param mixed $raw A list of names.
	 *
	 * @return string[]
	 */
	private function nameList(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$names = [];
		foreach ($raw as $name) {
			if (is_string($name) === false) {
				continue;
			}

			$name = trim($name);
			if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || in_array($name, $names, true) === true) {
				continue;
			}

			$names[] = $name;
			if (count($names) >= self::MAX_NAMES) {
				break;
			}
		}

		return $names;
	}//end nameList()

	/**
	 * The property names of the mapping's target schema, resolved only among
	 * the app's own registers.
	 *
	 * @param array<string, mixed> $app     The Application record.
	 * @param array<string, mixed> $context The request context.
	 *
	 * @return string[]
	 *
	 * @throws CopilotException (422) Without a target schema, (404) when the app holds no such schema.
	 *
	 * @spec openspec/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
	 */
	private function targetSchemaProperties(array $app, array $context): array {
		$schemaSlug = $this->contextString(context: $context, key: 'targetSchema');
		if ($schemaSlug === '') {
			throw new CopilotException(
				errorCode: 'invalid_arguments',
				message: 'Pick a target schema before asking for a field mapping.',
				httpStatus: 422
			);
		}

		$registers = $this->appRegisterSlugs(app: $app);
		$wanted = $this->contextString(context: $context, key: 'register');
		if ($wanted !== '') {
			$registers = array_values(array_intersect($registers, [$wanted]));
		}

		foreach ($registers as $registerSlug) {
			$read = $this->schemaReader->read(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
			if (is_array($read['properties'] ?? null) === true) {
				return array_values(array_map('strval', $read['properties']));
			}
		}

		throw new CopilotException(
			errorCode: 'not_found',
			message: "This app has no schema '{$schemaSlug}'.",
			httpStatus: 404
		);
	}//end targetSchemaProperties()

	/**
	 * One trimmed string from the request context, '' when absent or not a string.
	 *
	 * @param array<string, mixed> $context The request context.
	 * @param string               $key     The context key.
	 *
	 * @return string
	 */
	private function contextString(array $context, string $key): string {
		$value = ($context[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end contextString()

	/**
	 * The registers an app owns: each version's register and its bound data registers.
	 *
	 * @param array<string, mixed> $app The Application record.
	 *
	 * @return string[]
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function appRegisterSlugs(array $app): array {
		$registers = $this->versionRegisterSlugs(app: $app);
		foreach ((array)($app['dataRegisters'] ?? []) as $binding) {
			if (is_array($binding) === true) {
				$registers[] = (string)($binding['register'] ?? '');
			}
		}

		return array_values(array_unique(array_filter($registers, static fn (string $slug): bool => $slug !== '')));
	}//end appRegisterSlugs()

	/**
	 * The register slug of each of the app's versions: the version's own
	 * register, else the one its slug names.
	 *
	 * @param array<string, mixed> $app The Application record.
	 *
	 * @return string[] Register slugs, '' for a version that names none.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function versionRegisterSlugs(array $app): array {
		$registers = [];
		$appSlug = (string)($app['slug'] ?? '');
		foreach ($this->appVersions(app: $app) as $item) {
			$version = ObjectRecord::castArray(object: $item);
			$register = (string)($version['register'] ?? '');
			if ($register === '' && (string)($version['slug'] ?? '') !== '') {
				$register = ApplicationVersionServiceInterface::VERSION_REGISTER_PREFIX . $appSlug . '-' . (string)$version['slug'];
			}

			$registers[] = $register;
		}

		return $registers;
	}//end versionRegisterSlugs()

	/**
	 * The app's ApplicationVersion objects the caller may read; none when the
	 * app has no id or the search fails.
	 *
	 * @param array<string, mixed> $app The Application record.
	 *
	 * @return array<int, mixed>
	 */
	private function appVersions(array $app): array {
		$appUuid = (string)($app['uuid'] ?? ($app['id'] ?? ''));
		$versions = [];
		if ($appUuid !== '') {
			try {
				$versions = (new SlugSearch(register: self::REGISTER_SLUG, schema: self::APPLICATION_VERSION_SCHEMA))->run(
					objectService: $this->objectService,
					filters: ['application' => $appUuid],
					rbac: true,
					multitenancy: false
				);
			} catch (Throwable $e) {
				$versions = [];
			}
		}

		if (is_array($versions) === false) {
			return [];
		}

		return $versions;
	}//end appVersions()

	/**
	 * Build the prompt for one target and mode.
	 *
	 * @param string                  $target  The editor field.
	 * @param string                  $mode    `write` or `explain`.
	 * @param string                  $request The maker's request.
	 * @param string                  $current The current value.
	 * @param array<string, string[]> $names   The names the prompt may carry.
	 *
	 * @return string
	 */
	private function buildPrompt(string $target, string $mode, string $request, string $current, array $names): string {
		$lines = [
			'You help a maker in a no-code app builder fill in one field.',
			$this->targetGrammar(target: $target),
		];

		$labels = [
			'columns' => 'Decision table input columns',
			'paths' => 'Payload paths',
			'triggerSchema' => 'Trigger schema',
			'targetSchema' => 'Target schema',
			'properties' => 'Properties of the target schema',
		];
		foreach ($labels as $key => $label) {
			if (($names[$key] ?? []) !== []) {
				$lines[] = $label . ': ' . implode(', ', $names[$key]);
			}
		}

		if (trim($current) !== '') {
			$lines[] = 'Current value:';
			$lines[] = $current;
		}

		if ($mode === 'explain') {
			$lines[] = 'Explain in one or two plain sentences what the current value does. Do not propose a new value.';
			$lines[] = 'Answer with only a JSON object: {"explanation": "..."}';
			return implode("\n", $lines);
		}

		$lines[] = 'The maker asks:';
		$lines[] = $request;
		$lines[] = 'Answer with only a JSON object: {"value": <the value>, "explanation": "<one line saying what it does>"}';

		return implode("\n", $lines);
	}//end buildPrompt()

	/**
	 * What a value for the target must look like.
	 *
	 * @param string $target The editor field.
	 *
	 * @return string
	 */
	private function targetGrammar(string $target): string {
		return match ($target) {
			'feel-condition' => 'The field is a FEEL condition, given as a string. Allowed: comparisons == != < > <= >=, '
				. 'ranges 5..10, lists in (1, 2), and, or, not, is null, is not null, arithmetic + - * /, numbers, '
				. 'single-quoted strings, true, false, null and dot paths such as applicant.age. No function calls.',
			'decision-rule' => 'The field is one decision table row, given as '
				. '{"conditions": {<input column>: <cell>}, "decision": "<decision>"}. Use only the input columns listed. '
				. 'A cell is -, a comparison such as >= 18, a range 18..65, a list in (\'NL\', \'BE\') or a literal. Never a single =.',
			'field-mapping' => 'The field is a field mapping, given as a JSON object whose keys are properties of the target schema '
				. 'and whose values are payload paths or literals. Use only the properties listed.',
			default => 'The field is a webhook payload template, given as a JSON object or array.',
		};
	}//end targetGrammar()

	/**
	 * The repair prompt: the original prompt, the refused answer and why.
	 *
	 * @param string $prompt   The original prompt.
	 * @param string $previous The refused answer.
	 * @param string $error    The checker's message.
	 *
	 * @return string
	 */
	private function buildRepairPrompt(string $prompt, string $previous, string $error): string {
		return $prompt . "\n\nYour previous answer was refused.\nPrevious answer:\n" . $previous
			. "\nWhy: " . $error . "\nAnswer again with only the JSON object.";
	}//end buildRepairPrompt()

	/**
	 * Decode a JSON object from the model's text, without code fences.
	 *
	 * @param string $text The model's text.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeObject(string $text): ?array {
		$trimmed = trim($text);
		if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches) === 1) {
			$trimmed = trim($matches[1]);
		}

		try {
			$decoded = json_decode($trimmed, associative: true, flags: JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($decoded) === false || array_is_list($decoded) === true) {
			return null;
		}

		return $decoded;
	}//end decodeObject()

	/**
	 * A condition must be a string FeelParser accepts.
	 *
	 * @param mixed $value The proposed value.
	 *
	 * @return array{0: mixed, 1: string}
	 */
	private function checkCondition(mixed $value): array {
		if (is_string($value) === false || trim($value) === '') {
			return [null, 'The condition must be a non-empty string.'];
		}

		try {
			$this->feelParser->parse(expression: trim($value));
		} catch (InvalidArgumentException $e) {
			return [null, 'The condition does not parse: ' . $e->getMessage()];
		}

		return [trim($value), ''];
	}//end checkCondition()

	/**
	 * A row must name only the table's input columns, each cell passing the cell grammar.
	 *
	 * @param mixed    $value   The proposed value.
	 * @param string[] $columns The table's input column names.
	 *
	 * @return array{0: mixed, 1: string}
	 */
	private function checkDecisionRule(mixed $value, array $columns): array {
		if (is_array($value) === false || is_array($value['conditions'] ?? null) === false) {
			return [null, 'The rule must be an object with "conditions" and "decision".'];
		}

		$decision = ($value['decision'] ?? null);
		if (is_scalar($decision) === false || trim((string)$decision) === '') {
			return [null, 'The rule has no decision.'];
		}

		$conditions = [];
		foreach ($value['conditions'] as $column => $cell) {
			$column = (string)$column;
			if (in_array($column, $columns, true) === false) {
				return [null, "The rule uses the column '{$column}', which the table does not have."];
			}

			if ($this->cellCondition->isValid(raw: $cell) === false) {
				return [null, "The cell for '{$column}' is not a valid condition."];
			}

			$conditions[$column] = trim((string)$cell);
		}

		return [['conditions' => $conditions, 'decision' => trim((string)$decision)], ''];
	}//end checkDecisionRule()

	/**
	 * A mapping must be a JSON object whose keys are target schema properties.
	 *
	 * @param mixed    $value      The proposed value.
	 * @param string[] $properties The target schema's property names.
	 *
	 * @return array{0: mixed, 1: string}
	 */
	private function checkFieldMapping(mixed $value, array $properties): array {
		$mapping = $this->jsonValue(value: $value);
		if (is_array($mapping) === false || ($mapping !== [] && array_is_list($mapping) === true)) {
			return [null, 'The field mapping must be a JSON object.'];
		}

		$unknown = array_values(array_diff(array_map('strval', array_keys($mapping)), $properties));
		if ($unknown !== []) {
			return [null, 'The target schema has no property ' . implode(', ', $unknown) . '.'];
		}

		return [$this->pretty(value: $mapping), ''];
	}//end checkFieldMapping()

	/**
	 * A payload template must be JSON: an object or an array.
	 *
	 * @param mixed $value The proposed value.
	 *
	 * @return array{0: mixed, 1: string}
	 */
	private function checkPayloadTemplate(mixed $value): array {
		$template = $this->jsonValue(value: $value);
		if (is_array($template) === false) {
			return [null, 'The payload template must be a JSON object or array.'];
		}

		return [$this->pretty(value: $template), ''];
	}//end checkPayloadTemplate()

	/**
	 * The decoded value: a JSON string is decoded, anything else is kept.
	 *
	 * @param mixed $value The proposed value.
	 *
	 * @return mixed The decoded value, or null when a string is not JSON.
	 */
	private function jsonValue(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		try {
			return json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return null;
		}
	}//end jsonValue()

	/**
	 * The JSON text the editor's textarea takes.
	 *
	 * @param array<mixed> $value A decoded object or list.
	 *
	 * @return string
	 */
	private function pretty(array $value): string {
		if ($value === []) {
			return '{}';
		}

		return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end pretty()
}//end class
