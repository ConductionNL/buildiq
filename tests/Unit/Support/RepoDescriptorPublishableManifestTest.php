<?php

/**
 * RepoDescriptor::publishableManifest() strips secrets before a manifest is published.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Service\RepoDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * Pins the published manifest: credential declarations reduced to their
 * schema keys and types, secret-named keys dropped at any depth, and
 * token-shaped strings and private keys redacted.
 *
 * @covers \OCA\Buildiq\Service\RepoDescriptor
 * @uses   \OCA\Buildiq\Service\GitHub\TokenScrubber
 */
class RepoDescriptorPublishableManifestTest extends TestCase {

	/**
	 * A manifest without credentials or secrets is published unchanged.
	 *
	 * @return void
	 */
	public function testAPlainManifestIsUnchanged(): void {
		$manifest = [
			'name' => 'Permits',
			'version' => 3,
			'enabled' => true,
			'pages' => [['id' => 'home', 'route' => '/']],
			'missing' => null,
		];

		self::assertSame($manifest, RepoDescriptor::publishableManifest(manifest: $manifest));
	}//end testAPlainManifestIsUnchanged()

	/**
	 * Credential entries keep only provider, reason and string scopes.
	 *
	 * @return void
	 */
	public function testCredentialEntriesKeepOnlyTheirDeclarationKeys(): void {
		$manifest = [
			'credentials' => [
				[
					'provider' => 'github',
					'reason' => 'Push builds',
					'scopes' => ['repo', 7, ['nested'], 'workflow'],
					'clientId' => 'abc',
					'extra' => 'dropped',
				],
				['provider' => 42, 'reason' => ['not', 'a', 'string'], 'scopes' => 'repo'],
				['provider' => 'slack', 'scopes' => ['a' => 'chat:write']],
				'not-an-object',
				['unrelated' => 'only'],
			],
		];

		$published = RepoDescriptor::publishableManifest(manifest: $manifest);

		self::assertSame(
			[
				['provider' => 'github', 'reason' => 'Push builds', 'scopes' => ['repo', 'workflow']],
				['scopes' => []],
				['provider' => 'slack', 'scopes' => []],
			],
			$published['credentials']
		);
	}//end testCredentialEntriesKeepOnlyTheirDeclarationKeys()

	/**
	 * A non-list credentials value publishes as an empty list.
	 *
	 * @return void
	 */
	public function testAScalarCredentialsValueBecomesAnEmptyList(): void {
		$published = RepoDescriptor::publishableManifest(manifest: ['credentials' => 'github']);

		self::assertSame(['credentials' => []], $published);
	}//end testAScalarCredentialsValueBecomesAnEmptyList()

	/**
	 * A secret written inside a credential entry never survives.
	 *
	 * @return void
	 */
	public function testASecretNextToADeclarationIsDropped(): void {
		$published = RepoDescriptor::publishableManifest(
			manifest: ['credentials' => [['provider' => 'github', 'token' => 'ghp_abcdefghijklmnopqrstuvwxyz0123']]]
		);

		self::assertSame([['provider' => 'github']], $published['credentials']);
	}//end testASecretNextToADeclarationIsDropped()

	/**
	 * Keys named like a secret are dropped at every depth, in any spelling.
	 *
	 * @return void
	 */
	public function testSecretNamedKeysAreDroppedAtAnyDepth(): void {
		$manifest = [
			'password' => 'p',
			'Client_Secret' => 's',
			'connectors' => [
				[
					'name' => 'crm',
					'api-key' => 'k',
					'auth' => ['ACCESS_TOKEN' => 't', 'privateKey' => 'x', 'user' => 'bot'],
				],
			],
			'tokenizer' => 'kept',
		];

		self::assertSame(
			[
				'connectors' => [['name' => 'crm', 'auth' => ['user' => 'bot']]],
				'tokenizer' => 'kept',
			],
			RepoDescriptor::publishableManifest(manifest: $manifest)
		);
	}//end testSecretNamedKeysAreDroppedAtAnyDepth()

	/**
	 * Integer keys are never treated as secret names, even in a list.
	 *
	 * @return void
	 */
	public function testListItemsAreKept(): void {
		$manifest = ['tags' => ['token', 'secret']];

		self::assertSame($manifest, RepoDescriptor::publishableManifest(manifest: $manifest));
	}//end testListItemsAreKept()

	/**
	 * Private keys are redacted whole and token-shaped strings are scrubbed.
	 *
	 * @return void
	 */
	public function testPrivateKeysAndTokensInValuesAreRedacted(): void {
		$manifest = [
			'cert' => "-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----",
			'note' => 'use ghp_abcdefghijklmnopqrstuvwxyz0123 for pushes',
			'remote' => 'https://bot:hunter2@example.com/repo.git',
			'plain' => 'nothing to see',
		];

		self::assertSame(
			[
				'cert' => '[redacted]',
				'note' => 'use [redacted-token] for pushes',
				'remote' => 'https://bot:[redacted-token]@example.com/repo.git',
				'plain' => 'nothing to see',
			],
			RepoDescriptor::publishableManifest(manifest: $manifest)
		);
	}//end testPrivateKeysAndTokensInValuesAreRedacted()
}//end class
