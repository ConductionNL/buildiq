<?php

/**
 * Unit tests for CopilotException.
 *
 * The exception carries the error code, HTTP status, failing step and context
 * the copilot endpoint answers with; withContext() returns a new exception
 * that adds context without overriding what is already there.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Exception;

use OCA\Buildiq\Exception\CopilotException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for CopilotException.
 */
#[CoversClass(CopilotException::class)]
final class CopilotExceptionTest extends TestCase {
	/**
	 * Everything given to the constructor is carried, and the numeric code stays 0.
	 *
	 * @return void
	 */
	public function testCarriesWhatItWasGiven(): void {
		$cause = new RuntimeException(message: 'llm timeout');

		$exception = new CopilotException(
			errorCode: 'step_failed',
			message: 'Step 2 failed.',
			httpStatus: 502,
			stepIndex: 2,
			context: ['model' => 'm'],
			previous: $cause,
		);

		self::assertSame(expected: 'step_failed', actual: $exception->getErrorCode());
		self::assertSame(expected: 'Step 2 failed.', actual: $exception->getMessage());
		self::assertSame(expected: 502, actual: $exception->getHttpStatus());
		self::assertSame(expected: 2, actual: $exception->getStepIndex());
		self::assertSame(expected: ['model' => 'm'], actual: $exception->getContext());
		self::assertSame(expected: 0, actual: $exception->getCode());
		self::assertSame(expected: $cause, actual: $exception->getPrevious());
	}//end testCarriesWhatItWasGiven()

	/**
	 * Without a step, context or cause, those are null, empty and null.
	 *
	 * @return void
	 */
	public function testOptionalPartsDefaultToAbsent(): void {
		$exception = new CopilotException(errorCode: 'bad_prompt', message: 'Bad prompt.', httpStatus: 400);

		self::assertNull(actual: $exception->getStepIndex());
		self::assertSame(expected: [], actual: $exception->getContext());
		self::assertNull(actual: $exception->getPrevious());
	}//end testOptionalPartsDefaultToAbsent()

	/**
	 * withContext() returns a new exception that keeps everything and adds the
	 * new keys, while keys already present keep their original value.
	 *
	 * @return void
	 */
	public function testWithContextAddsWithoutOverriding(): void {
		$cause    = new RuntimeException(message: 'cause');
		$original = new CopilotException(
			errorCode: 'step_failed',
			message: 'Step failed.',
			httpStatus: 500,
			stepIndex: 1,
			context: ['step' => 'schema', 'attempt' => 1],
			previous: $cause,
		);

		$enriched = $original->withContext(extra: ['attempt' => 2, 'requestId' => 'r-1']);

		self::assertNotSame(expected: $original, actual: $enriched);
		self::assertSame(expected: ['step' => 'schema', 'attempt' => 1, 'requestId' => 'r-1'], actual: $enriched->getContext());
		self::assertSame(expected: ['step' => 'schema', 'attempt' => 1], actual: $original->getContext());
		self::assertSame(expected: 'step_failed', actual: $enriched->getErrorCode());
		self::assertSame(expected: 'Step failed.', actual: $enriched->getMessage());
		self::assertSame(expected: 500, actual: $enriched->getHttpStatus());
		self::assertSame(expected: 1, actual: $enriched->getStepIndex());
		self::assertSame(expected: $cause, actual: $enriched->getPrevious());
	}//end testWithContextAddsWithoutOverriding()
}//end class
