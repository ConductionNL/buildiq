<?php

/**
 * Unit tests for AppRepoPayloadSafety: slug/uuid validation, connector filenames and secret stripping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRepoPayloadSafety;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AppRepoPayloadSafety.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\AppRepoPayloadSafety
 */
class AppRepoPayloadSafetyTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var AppRepoPayloadSafety
	 */
	private AppRepoPayloadSafety $safety;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->safety = new AppRepoPayloadSafety();
	}//end setUp()

	/**
	 * A valid slug is lower-alnum with hyphens, bounded by alnum, 2..64 chars.
	 *
	 * @return void
	 */
	public function testSafeSlugAcceptsCanonicalSlugs(): void {
		$this->assertTrue($this->safety->isSafeSlug('my-app'));
		$this->assertTrue($this->safety->isSafeSlug('a1'));
		$this->assertTrue($this->safety->isSafeSlug('ab'));
	}//end testSafeSlugAcceptsCanonicalSlugs()

	/**
	 * Uppercase, leading/trailing hyphen, single char, and path-traversal are all unsafe.
	 *
	 * @return void
	 */
	public function testSafeSlugRejectsUnsafeInput(): void {
		$this->assertFalse($this->safety->isSafeSlug('MyApp'));
		$this->assertFalse($this->safety->isSafeSlug('-lead'));
		$this->assertFalse($this->safety->isSafeSlug('lead-'));
		$this->assertFalse($this->safety->isSafeSlug('a'));
		$this->assertFalse($this->safety->isSafeSlug('../etc'));
		$this->assertFalse($this->safety->isSafeSlug(''));
	}//end testSafeSlugRejectsUnsafeInput()

	/**
	 * UUID validation accepts a canonical UUID (either case) and rejects malformed input.
	 *
	 * @return void
	 */
	public function testSafeUuid(): void {
		$this->assertTrue($this->safety->isSafeUuid('12345678-1234-1234-1234-1234567890ab'));
		$this->assertTrue($this->safety->isSafeUuid('ABCDEF12-3456-7890-ABCD-EF1234567890'));
		$this->assertFalse($this->safety->isSafeUuid('12345678-1234-1234-1234-1234567890'));
		$this->assertFalse($this->safety->isSafeUuid('not-a-uuid'));
	}//end testSafeUuid()

	/**
	 * The connector filename prefers the binding slug, then the object slug, then falls back to the uuid.
	 *
	 * @return void
	 */
	public function testConnectorFileNamePrefersBindingSlug(): void {
		$this->assertSame(
			'from-binding',
			$this->safety->connectorFileName(['slug' => 'from-binding'], ['slug' => 'from-object'], 'the-uuid')
		);
	}//end testConnectorFileNamePrefersBindingSlug()

	/**
	 * When the binding slug is unsafe the object slug is used.
	 *
	 * @return void
	 */
	public function testConnectorFileNameFallsBackToObjectSlug(): void {
		$this->assertSame(
			'from-object',
			$this->safety->connectorFileName(['slug' => 'NOT SAFE'], ['slug' => 'from-object'], 'the-uuid')
		);
	}//end testConnectorFileNameFallsBackToObjectSlug()

	/**
	 * When neither slug is safe the uuid is returned verbatim.
	 *
	 * @return void
	 */
	public function testConnectorFileNameFallsBackToUuid(): void {
		$this->assertSame(
			'the-uuid',
			$this->safety->connectorFileName([], [], 'the-uuid')
		);
	}//end testConnectorFileNameFallsBackToUuid()

	/**
	 * Secret-named keys are blanked recursively, counting each strip, and non-secret values are untouched.
	 *
	 * @return void
	 */
	public function testStripSecretsBlanksSecretKeys(): void {
		$stripped = 0;
		$out = $this->safety->stripSecrets(
			[
				'name'     => 'keep me',
				'password' => 'hunter2',
				'api_key'  => 'sk-xyz',
				'nested'   => ['clientSecret' => 'abc', 'label' => 'visible'],
				'empty'    => '',
			],
			$stripped
		);

		$this->assertSame('keep me', $out['name']);
		$this->assertSame('', $out['password']);
		$this->assertSame('', $out['api_key']);
		$this->assertSame('', $out['nested']['clientSecret']);
		$this->assertSame('visible', $out['nested']['label']);
		// An already-empty secret value is not counted (guarded by $value !== '').
		$this->assertSame('', $out['empty']);
		$this->assertSame(3, $stripped);
	}//end testStripSecretsBlanksSecretKeys()

	/**
	 * An inline `scheme://user:pass@host` credential in any string value has its credentials removed.
	 *
	 * @return void
	 */
	public function testStripSecretsRemovesInlineCredentials(): void {
		$stripped = 0;
		$out = $this->safety->stripSecrets(
			['dsn' => 'postgres://admin:s3cr3t@db.example.org:5432/app'],
			$stripped
		);

		$this->assertSame('postgres://db.example.org:5432/app', $out['dsn']);
		$this->assertSame(1, $stripped);
	}//end testStripSecretsRemovesInlineCredentials()
}//end class
