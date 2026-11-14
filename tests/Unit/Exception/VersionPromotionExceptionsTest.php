<?php

/**
 * Unit tests for the version promotion exceptions.
 *
 * Each promotion failure carries a stable error code the promotion endpoint
 * returns to the client, a default message, and the cause it was raised from.
 * The lock and failed-promotion exceptions also carry who holds the lock and
 * which strategy failed.
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

use OCA\Buildiq\Exception\InsufficientPermissionException;
use OCA\Buildiq\Exception\InvalidStrategyException;
use OCA\Buildiq\Exception\NoPromoteTargetException;
use OCA\Buildiq\Exception\PromotionFailedException;
use OCA\Buildiq\Exception\VersionLockedException;
use OCA\Buildiq\Exception\VersionPromotionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the version promotion exceptions.
 */
#[CoversClass(VersionPromotionException::class)]
#[CoversClass(InsufficientPermissionException::class)]
#[CoversClass(InvalidStrategyException::class)]
#[CoversClass(NoPromoteTargetException::class)]
#[CoversClass(PromotionFailedException::class)]
#[CoversClass(VersionLockedException::class)]
final class VersionPromotionExceptionsTest extends TestCase {
	/**
	 * Each exception built with its defaults, and the code and message it must carry.
	 *
	 * @return array<string, array{0: callable(): VersionPromotionException, 1: string, 2: string}>
	 */
	public static function defaults(): array {
		return [
			'insufficient permission' => [
				static fn (): VersionPromotionException => new InsufficientPermissionException(),
				'insufficient_permission',
				'Caller is not an owner or editor on the parent Application.',
			],
			'invalid strategy'        => [
				static fn (): VersionPromotionException => new InvalidStrategyException(),
				'invalid_strategy',
				'Unknown or missing promotion strategy.',
			],
			'no promote target'       => [
				static fn (): VersionPromotionException => new NoPromoteTargetException(),
				'no_promote_target',
				'Source version has no promotesTo target.',
			],
			'promotion failed'        => [
				static fn (): VersionPromotionException => new PromotionFailedException(strategy: 'copy'),
				'promotion_failed',
				'Promotion failed; target was archived.',
			],
			'version locked'          => [
				static fn (): VersionPromotionException => new VersionLockedException(),
				'version_locked',
				'Target ApplicationVersion row is locked.',
			],
		];
	}//end defaults()

	/**
	 * Each exception carries its own error code and default message, and no numeric code.
	 *
	 * @param callable(): VersionPromotionException $make    Builds the exception.
	 * @param string                                $code    The expected error code.
	 * @param string                                $message The expected default message.
	 *
	 * @return void
	 */
	#[DataProvider('defaults')]
	public function testCarriesItsErrorCodeAndDefaultMessage(callable $make, string $code, string $message): void {
		$exception = $make();

		self::assertInstanceOf(expected: RuntimeException::class, actual: $exception);
		self::assertSame(expected: $code, actual: $exception->getErrorCode());
		self::assertSame(expected: $message, actual: $exception->getMessage());
		self::assertSame(expected: 0, actual: $exception->getCode());
		self::assertNull(actual: $exception->getPrevious());
	}//end testCarriesItsErrorCodeAndDefaultMessage()

	/**
	 * A given message and cause replace the default and are kept, the error code stays.
	 *
	 * @return void
	 */
	public function testGivenMessageAndCauseAreKept(): void {
		$cause = new RuntimeException(message: 'db down');

		$exception = new NoPromoteTargetException(message: 'Nothing to promote to.', previous: $cause);

		self::assertSame(expected: 'no_promote_target', actual: $exception->getErrorCode());
		self::assertSame(expected: 'Nothing to promote to.', actual: $exception->getMessage());
		self::assertSame(expected: $cause, actual: $exception->getPrevious());
	}//end testGivenMessageAndCauseAreKept()

	/**
	 * A failed promotion names the strategy that failed.
	 *
	 * @return void
	 */
	public function testPromotionFailedNamesItsStrategy(): void {
		$cause = new RuntimeException(message: 'copy aborted');

		$exception = new PromotionFailedException(strategy: 'replace', message: 'Replace failed.', previous: $cause);

		self::assertSame(expected: 'replace', actual: $exception->getStrategy());
		self::assertSame(expected: 'Replace failed.', actual: $exception->getMessage());
		self::assertSame(expected: $cause, actual: $exception->getPrevious());
	}//end testPromotionFailedNamesItsStrategy()

	/**
	 * A lock names its holder and expiry, and both are absent by default.
	 *
	 * @return void
	 */
	public function testVersionLockedNamesHolderAndExpiry(): void {
		$locked = new VersionLockedException(lockedBy: 'alice', expiresAt: '2026-11-12T18:00:00Z');
		$bare   = new VersionLockedException();

		self::assertSame(expected: 'alice', actual: $locked->getLockedBy());
		self::assertSame(expected: '2026-11-12T18:00:00Z', actual: $locked->getExpiresAt());
		self::assertNull(actual: $bare->getLockedBy());
		self::assertNull(actual: $bare->getExpiresAt());
	}//end testVersionLockedNamesHolderAndExpiry()
}//end class
