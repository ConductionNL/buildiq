<?php

/**
 * Unit tests for the payloads and messages of buildiq's other exceptions:
 * the repository parse error document, the register read failure message,
 * the automation combination code and the wizard failure report.
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

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Exception\RegisterRowReadFailedException;
use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Exception\WizardCreationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the exception payloads.
 */
#[CoversClass(AppRepoParseException::class)]
#[CoversClass(RegisterRowReadFailedException::class)]
#[CoversClass(UnsupportedAutomationCombinationException::class)]
#[CoversClass(WizardCreationException::class)]
final class ExceptionPayloadsTest extends TestCase {
	/**
	 * A parse error serialises to its error document, naming the file.
	 *
	 * @return void
	 */
	public function testRepoParseErrorDocumentNamesTheFile(): void {
		$cause     = new RuntimeException(message: 'syntax');
		$exception = new AppRepoParseException(
			errorCode: 'invalid_json',
			message: 'appinfo/manifest.json is not valid JSON.',
			filePath: 'appinfo/manifest.json',
			previous: $cause,
		);

		self::assertSame(
			expected: [
				'error'  => 'repo_parse_failed',
				'code'   => 'invalid_json',
				'file'   => 'appinfo/manifest.json',
				'detail' => 'appinfo/manifest.json is not valid JSON.',
			],
			actual: $exception->toArray()
		);
		self::assertSame(expected: 'invalid_json', actual: $exception->getErrorCode());
		self::assertSame(expected: 'appinfo/manifest.json', actual: $exception->getFilePath());
		self::assertSame(expected: $cause, actual: $exception->getPrevious());
	}//end testRepoParseErrorDocumentNamesTheFile()

	/**
	 * A parse error without a file serialises with a null file.
	 *
	 * @return void
	 */
	public function testRepoParseErrorWithoutFile(): void {
		$exception = new AppRepoParseException(errorCode: 'repo_empty', message: 'The repository is empty.');

		self::assertNull(actual: $exception->getFilePath());
		self::assertSame(
			expected: ['error' => 'repo_parse_failed', 'code' => 'repo_empty', 'file' => null, 'detail' => 'The repository is empty.'],
			actual: $exception->toArray()
		);
	}//end testRepoParseErrorWithoutFile()

	/**
	 * A register read failure names the register and the schema, and keeps its cause.
	 *
	 * @return void
	 */
	public function testRegisterReadFailureNamesRegisterAndSchema(): void {
		$cause     = new RuntimeException(message: 'timeout');
		$exception = new RegisterRowReadFailedException(registerSlug: 'buildiq', schemaId: '17', previous: $cause);

		self::assertSame(expected: 'Could not read rows of register "buildiq" for schema 17', actual: $exception->getMessage());
		self::assertSame(expected: 0, actual: $exception->getCode());
		self::assertSame(expected: $cause, actual: $exception->getPrevious());
	}//end testRegisterReadFailureNamesRegisterAndSchema()

	/**
	 * An unsupported automation combination always carries the same error code.
	 *
	 * @return void
	 */
	public function testUnsupportedCombinationHasAFixedCode(): void {
		$exception = new UnsupportedAutomationCombinationException(message: 'A transition trigger cannot send mail.');

		self::assertSame(expected: 'unsupported_automation_combination', actual: $exception->getErrorCode());
		self::assertSame(expected: 'A transition trigger cannot send mail.', actual: $exception->getMessage());
	}//end testUnsupportedCombinationHasAFixedCode()

	/**
	 * A wizard failure reports the step, the rollback outcome and what was left behind.
	 *
	 * @return void
	 */
	public function testWizardFailureReportsStepRollbackAndOrphans(): void {
		$cause     = new RuntimeException(message: 'register create failed');
		$exception = new WizardCreationException(
			errorCode: 'register_create_failed',
			failedAtStep: 'register',
			message: 'Could not create the register.',
			rollbackStatus: 'partial',
			orphanedResources: [['type' => 'schema', 'id' => 's-1']],
			previous: $cause,
		);

		self::assertSame(expected: 'register_create_failed', actual: $exception->getErrorCode());
		self::assertSame(expected: 'register', actual: $exception->getFailedAtStep());
		self::assertSame(expected: 'Could not create the register.', actual: $exception->getMessage());
		self::assertSame(expected: 'partial', actual: $exception->getRollbackStatus());
		self::assertSame(expected: [['type' => 'schema', 'id' => 's-1']], actual: $exception->getOrphanedResources());
		self::assertSame(expected: $cause, actual: $exception->getPrevious());
	}//end testWizardFailureReportsStepRollbackAndOrphans()

	/**
	 * Without a rollback, a wizard failure reports rollback "none", no orphans and an empty message.
	 *
	 * @return void
	 */
	public function testWizardFailureDefaults(): void {
		$exception = new WizardCreationException(errorCode: 'schema_invalid', failedAtStep: 'schema');

		self::assertSame(expected: 'none', actual: $exception->getRollbackStatus());
		self::assertSame(expected: [], actual: $exception->getOrphanedResources());
		self::assertSame(expected: '', actual: $exception->getMessage());
		self::assertNull(actual: $exception->getPrevious());
	}//end testWizardFailureDefaults()
}//end class
