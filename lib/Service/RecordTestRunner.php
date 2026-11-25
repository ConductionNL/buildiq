<?php

/**
 * Record Test Runner
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Checks one record test of a built app through OpenRegister's validate-only
 * call (`ObjectServiceInterface::validateObject()`, openregister change
 * records-validate-without-saving), so the sample record is never saved.
 *
 * - `accept` expected: passes when the record is accepted, fails naming the
 *   refused fields otherwise.
 * - `refuse` expected: passes when every expected field is refused (any field
 *   when none is named); fails when the record is accepted or refused only on
 *   other fields.
 * - A call that throws, or a version without a register, fails: a check that
 *   silently did not run looks exactly like one that passed.
 * - An OpenRegister without the call reports the test skipped, never passed.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Throwable;

/**
 * Runs one record test against a register without saving the record.
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
 */
class RecordTestRunner {

	/**
	 * Why a record test does not run on this OpenRegister.
	 */
	public const SKIP_REASON = 'OpenRegister cannot check a record without saving it yet, so this record test did not run.';

	/**
	 * Why a record test fails when its version names no register.
	 */
	public const NO_REGISTER = 'This version has no register, so the record could not be checked.';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface|null $records OpenRegister's object service, for its validate-only call.
	 */
	public function __construct(
		private readonly ?ObjectServiceInterface $records=null,
	) {
	}//end __construct()

	/**
	 * Run one record test.
	 *
	 * @param array<string, mixed> $test     The record test (schema, record, expect, expectedFields).
	 * @param string               $register The version's register (id, uuid or slug).
	 *
	 * @return array{outcome: string, message: string} outcome passed, failed or skipped.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	public function run(array $test, string $register): array {
		$records = $this->records;
		// An installed OpenRegister older than records-validate-without-saving lacks the call,
		// whatever the contract this app is analysed against declares.
		/* @phpstan-ignore function.alreadyNarrowedType */
		if ($records === null || method_exists($records, 'validateObject') === false) {
			return self::result(outcome: AppTestSuiteService::SKIPPED, message: self::SKIP_REASON);
		}

		if ($register === '') {
			return self::result(outcome: AppTestSuiteService::FAILED, message: self::NO_REGISTER);
		}

		$record = $test['record'] ?? [];
		if (is_array($record) === false) {
			$record = [];
		}

		try {
			$verdict = $records->validateObject(object: $record, register: $register, schema: (string)($test['schema'] ?? ''));
		} catch (Throwable $e) {
			return self::result(outcome: AppTestSuiteService::FAILED, message: 'The record could not be checked: ' . $e->getMessage());
		}

		$refused = self::refusedFields(verdict: $verdict);
		if (($test['expect'] ?? '') === 'accept') {
			return self::judgeAcceptance(refused: $refused);
		}

		return self::judgeRefusal(refused: $refused, expected: self::expectedFields(test: $test));
	}//end run()

	/**
	 * The verdict on a test that expects the record to be accepted.
	 *
	 * @param list<string>|null $refused The refused fields, or null when the record was accepted.
	 *
	 * @return array{outcome: string, message: string}
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	private static function judgeAcceptance(?array $refused): array {
		if ($refused === null) {
			return self::result(outcome: AppTestSuiteService::PASSED, message: '');
		}

		$message = 'Expected the record to be accepted, but it was refused' . self::onFields(fields: $refused) . '.';

		return self::result(outcome: AppTestSuiteService::FAILED, message: $message);
	}//end judgeAcceptance()

	/**
	 * The verdict on a test that expects the record to be refused.
	 *
	 * @param list<string>|null $refused  The refused fields, or null when the record was accepted.
	 * @param list<string>      $expected The fields the refusal should name; empty for any.
	 *
	 * @return array{outcome: string, message: string}
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	private static function judgeRefusal(?array $refused, array $expected): array {
		if ($refused === null) {
			return self::result(outcome: AppTestSuiteService::FAILED, message: 'Expected the record to be refused, but it was accepted.');
		}

		$missing = array_values(array_diff($expected, $refused));
		if ($missing === []) {
			return self::result(outcome: AppTestSuiteService::PASSED, message: '');
		}

		$message = 'Expected a refusal on ' . implode(', ', $missing) . ', but it was refused' . self::onFields(fields: $refused) . '.';

		return self::result(outcome: AppTestSuiteService::FAILED, message: $message);
	}//end judgeRefusal()

	/**
	 * The non-empty field names a test expects the refusal on.
	 *
	 * @param array<string, mixed> $test The record test.
	 *
	 * @return list<string>
	 */
	private static function expectedFields(array $test): array {
		$fields = array_map('strval', (array)($test['expectedFields'] ?? []));

		return array_values(array_filter($fields, static fn (string $field): bool => $field !== ''));
	}//end expectedFields()

	/**
	 * The properties a validate-only verdict refused, or null when it accepted the record.
	 *
	 * Read through the verdict's published JSON shape (`valid`, `errors[].property`),
	 * so buildiq does not depend on the verdict class itself. A refusal that
	 * names no single property is listed as `(record)`.
	 *
	 * @param mixed $verdict The answer of validateObject().
	 *
	 * @return list<string>|null
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	private static function refusedFields(mixed $verdict): ?array {
		$answer = $verdict;
		if ($verdict instanceof JsonSerializable) {
			$answer = $verdict->jsonSerialize();
		}

		if (is_array($answer) === false) {
			return [];
		}

		$errors = (array)($answer['errors'] ?? []);
		if (($answer['valid'] ?? false) === true && $errors === []) {
			return null;
		}

		$fields = [];
		foreach ($errors as $error) {
			$fields[] = self::fieldOf(error: $error);
		}

		return array_values(array_unique($fields));
	}//end refusedFields()

	/**
	 * The property one refusal names, or `(record)` when it names none.
	 *
	 * @param mixed $error One entry of the verdict's errors.
	 *
	 * @return string
	 */
	private static function fieldOf(mixed $error): string {
		$property = null;
		if (is_array($error) === true) {
			$property = $error['property'] ?? null;
		}

		if (is_string($property) === false || $property === '') {
			return '(record)';
		}

		return $property;
	}//end fieldOf()

	/**
	 * " on a, b" for a list of fields, or "" when the list is empty.
	 *
	 * @param list<string> $fields The fields.
	 *
	 * @return string
	 */
	private static function onFields(array $fields): string {
		if ($fields === []) {
			return '';
		}

		return ' on ' . implode(', ', $fields);
	}//end onFields()

	/**
	 * One outcome with its message.
	 *
	 * @param string $outcome passed, failed or skipped.
	 * @param string $message Why, when it did not pass.
	 *
	 * @return array{outcome: string, message: string}
	 */
	private static function result(string $outcome, string $message): array {
		return ['outcome' => $outcome, 'message' => $message];
	}//end result()
}//end class
