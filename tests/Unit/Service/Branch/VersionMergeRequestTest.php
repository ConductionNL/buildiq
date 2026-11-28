<?php

/**
 * Unit tests for VersionMergeRequest: what a merge request resolves.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Branch;

use OCA\Buildiq\Service\Branch\VersionMergeRequest;
use PHPUnit\Framework\TestCase;

/**
 * The resolution a maker sends with a merge: the side picked per conflict, the
 * hash of the target it was read against, and whether breaking changes are accepted.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionMergeRequest
 */
class VersionMergeRequestTest extends TestCase {

	/**
	 * Only string choices are kept, under their conflict keys.
	 *
	 * @return void
	 */
	public function testOnlyStringChoicesAreKept(): void {
		$request = new VersionMergeRequest(
			source: ['id' => 'v-branch'],
			target: ['id' => 'v-main'],
			resolution: ['choices' => ['schema:meeting' => 'source', 'schema:vote' => 7, 'schema:motion' => 'target', 'schema:x' => null]],
		);

		$this->assertSame(expected: ['schema:meeting' => 'source', 'schema:motion' => 'target'], actual: $request->choices());
		$this->assertSame(expected: ['id' => 'v-branch'], actual: $request->source);
		$this->assertSame(expected: ['id' => 'v-main'], actual: $request->target);
	}//end testOnlyStringChoicesAreKept()

	/**
	 * Without a resolution nothing is chosen, no hash is named and breaking changes are not accepted.
	 *
	 * @return void
	 */
	public function testWithoutAResolutionNothingIsChosenOrAccepted(): void {
		$request = new VersionMergeRequest(source: [], target: []);

		$this->assertSame(expected: [], actual: $request->choices());
		$this->assertSame(expected: '', actual: $request->targetHash());
		$this->assertFalse(condition: $request->acknowledgeBreaking());
	}//end testWithoutAResolutionNothingIsChosenOrAccepted()

	/**
	 * The target hash is read as a string.
	 *
	 * @return void
	 */
	public function testTheTargetHashIsReadAsAString(): void {
		$named  = new VersionMergeRequest(source: [], target: [], resolution: ['targetHash' => 'abc123']);
		$number = new VersionMergeRequest(source: [], target: [], resolution: ['targetHash' => 42]);

		$this->assertSame(expected: 'abc123', actual: $named->targetHash());
		$this->assertSame(expected: '42', actual: $number->targetHash());
	}//end testTheTargetHashIsReadAsAString()

	/**
	 * Breaking changes are accepted only by true or the string "true".
	 *
	 * @return void
	 */
	public function testBreakingChangesAreAcceptedOnlyByTrue(): void {
		$accepts = static function (mixed $value): bool {
			$request = new VersionMergeRequest(source: [], target: [], resolution: ['acknowledgeBreaking' => $value]);
			return $request->acknowledgeBreaking();
		};

		$this->assertSame(
			expected: [true, true, false, false, false, false],
			actual: array_map($accepts, [true, 'true', 'yes', 1, '1', false])
		);
	}//end testBreakingChangesAreAcceptedOnlyByTrue()
}//end class
