<?php

/**
 * Unit tests for RepoSource: the owner, repository, ref and credential an app is read from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Kernel\Values
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Kernel\Values;

use OCA\Buildiq\Kernel\Values\RepoSource;
use PHPUnit\Framework\TestCase;

/**
 * Construction and immutability of RepoSource.
 *
 * @covers \OCA\Buildiq\Kernel\Values\RepoSource
 */
final class RepoSourceTest extends TestCase {

	/**
	 * Owner, repository, ref and credential are kept exactly as given.
	 *
	 * @return void
	 */
	public function testKeepsEveryPartVerbatim(): void {
		$source = new RepoSource(owner: 'ConductionNL', repo: 'pet-store', ref: 'refs/heads/main', credentialId: 'cred-1');

		$this->assertSame(expected: 'ConductionNL', actual: $source->owner);
		$this->assertSame(expected: 'pet-store', actual: $source->repo);
		$this->assertSame(expected: 'refs/heads/main', actual: $source->ref);
		$this->assertSame(expected: 'cred-1', actual: $source->credentialId);
	}//end testKeepsEveryPartVerbatim()

	/**
	 * A null ref (default branch) and a null credential (public read) stay null.
	 *
	 * @return void
	 */
	public function testRefAndCredentialMayBeNull(): void {
		$source = new RepoSource(owner: 'ConductionNL', repo: 'pet-store', ref: null, credentialId: null);

		$this->assertNull(actual: $source->ref);
		$this->assertNull(actual: $source->credentialId);
	}//end testRefAndCredentialMayBeNull()

	/**
	 * The credential has no default, so leaving it out is an error rather than a silent public read.
	 *
	 * @return void
	 */
	public function testTheCredentialMustBePassed(): void {
		$this->expectException(exception: \ArgumentCountError::class);

		new RepoSource(...['owner' => 'ConductionNL', 'repo' => 'pet-store', 'ref' => 'main']);
	}//end testTheCredentialMustBePassed()

	/**
	 * A RepoSource cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new RepoSource(owner: 'o', repo: 'r', ref: null, credentialId: null);

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->credentialId = 'cred-2';
	}//end testPropertiesAreReadOnly()
}//end class
