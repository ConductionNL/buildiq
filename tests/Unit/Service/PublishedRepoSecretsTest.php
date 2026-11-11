<?php

/**
 * A published app or template repository never carries a credential secret.
 *
 * Both serialisers write the version's manifest to `manifest.json` next to the
 * `openbuild-app.json` descriptor. A manifest `credentials[]` entry that holds a
 * secret next to its declaration must reach neither file: the descriptor lists
 * provider, reason and scopes, and `manifest.json` keeps the same declaration
 * keys, each only in its schema type, while every other part of the manifest
 * is published unchanged.
 * The files are canonical JSON with sorted keys, so they are compared by value.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-credentials-declaration-in-the-descriptor
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\TemplateRepoSerializer;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Secrets in a manifest's credential declarations stay on the instance.
 */
class PublishedRepoSecretsTest extends TestCase {
	/**
	 * Every secret value the manifest below carries.
	 *
	 * @var array<int,string>
	 */
	private const SECRETS = [
		'ghp_secret-token-value',
		'client-secret-value',
		'p4ssw0rd',
		'inline-secret',
		'oauth-client-secret',
		'0b7d1f2e-credential-uuid',
		'mail-api-key',
		'ghp_nested-auth-token',
		'ghp_env-style-token',
		'oauth-access-token',
		'oauth-refresh-token',
		'BEGIN PRIVATE KEY',
		'url-password',
		'bare-string-entry-token',
		'provider-object-token',
		'reason-object-secret',
		'scope-item-token',
		'scope-map-token',
	];

	/**
	 * The serialised app repository carries no secret, in any file.
	 *
	 * @return void
	 */
	public function testAppRepositoryCarriesNoSecret(): void {
		$files = $this->appSerializer()->serialize(
			application: ['slug' => 'demo-app', 'name' => 'Demo App', 'appType' => 'virtual'],
			version: ['semver' => '1.0.0', 'manifest' => self::manifest()]
		);

		$this->assertCarriesNoSecret(files: $files);
		$this->assertPublishedDeclarations(files: $files);
	}//end testAppRepositoryCarriesNoSecret()

	/**
	 * The serialised template repository carries no secret, in any file.
	 *
	 * @return void
	 */
	public function testTemplateRepositoryCarriesNoSecret(): void {
		$files = $this->templateSerializer()->serialize(template: ['slug' => 'demo', 'title' => 'Demo', 'manifest' => self::manifest()]);

		$this->assertCarriesNoSecret(files: $files);
		$this->assertPublishedDeclarations(files: $files);
	}//end testTemplateRepositoryCarriesNoSecret()

	/**
	 * A manifest without credentials is published exactly as stored.
	 *
	 * @return void
	 */
	public function testManifestWithoutCredentialsIsPublishedAsIs(): void {
		$manifest = self::manifest();
		unset($manifest['credentials']);

		$app = $this->appSerializer()->serialize(application: ['slug' => 'demo-app', 'appType' => 'virtual'], version: ['manifest' => $manifest]);
		$template = $this->templateSerializer()->serialize(template: ['slug' => 'demo', 'manifest' => $manifest]);

		$this->assertEquals($manifest, json_decode($app['manifest.json'], true));
		$this->assertEquals($manifest, json_decode($template['manifest.json'], true));
	}//end testManifestWithoutCredentialsIsPublishedAsIs()

	/**
	 * Secrets outside `credentials` are not published either, while settings
	 * fields and keys that only resemble a secret name stay.
	 *
	 * @return void
	 */
	public function testSecretsElsewhereInTheManifestAreNotPublished(): void {
		$token = 'gh' . 'p_' . str_repeat('Zq7', 12);
		$manifest = [
			'version' => '1.0.0',
			'theme' => ['tokenSet' => 'default'],
			'settings' => ['registry_token' => ['type' => 'string', 'format' => 'password']],
			'sources' => [
				['name' => 'crm', 'url' => 'https://svc:page-password@crm.example.org', 'auth' => ['password' => 'source-password', 'api_key' => 'source-api-key']],
			],
			'pages' => [
				['id' => 'home', 'config' => ['headers' => ['Authorization' => 'Bearer header-secret-value'], 'note' => 'call with ' . $token]],
			],
			'signing' => ['privateKey' => 'x', 'pem' => "-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----"],
		];

		$app = $this->appSerializer()->serialize(application: ['slug' => 'demo-app', 'appType' => 'virtual'], version: ['manifest' => $manifest]);
		$template = $this->templateSerializer()->serialize(template: ['slug' => 'demo', 'manifest' => $manifest]);

		foreach ([$app, $template] as $files) {
			foreach (['page-password', 'source-password', 'source-api-key', 'header-secret-value', $token, 'PRIVATE KEY'] as $secret) {
				$this->assertStringNotContainsString($secret, $files['manifest.json']);
			}

			$published = json_decode($files['manifest.json'], true);
			$this->assertSame('default', $published['theme']['tokenSet']);
			$this->assertEquals($manifest['settings'], $published['settings']);
			$this->assertSame('crm', $published['sources'][0]['name']);
		}
	}//end testSecretsElsewhereInTheManifestAreNotPublished()

	/**
	 * No file of the repository contains a secret value.
	 *
	 * @param array<string,string> $files The serialised `path => contents` map.
	 *
	 * @return void
	 */
	private function assertCarriesNoSecret(array $files): void {
		foreach ($files as $path => $contents) {
			foreach (self::SECRETS as $secret) {
				$this->assertStringNotContainsString($secret, $contents, $path . ' carries ' . $secret);
			}
		}
	}//end assertCarriesNoSecret()

	/**
	 * Both files list the declarations, and the rest of the manifest is unchanged.
	 *
	 * @param array<string,string> $files The serialised `path => contents` map.
	 *
	 * @return void
	 */
	private function assertPublishedDeclarations(array $files): void {
		$descriptor = json_decode($files['openbuild-app.json'], true);
		$this->assertEquals(
			[
				['provider' => 'github', 'reason' => 'Push', 'scopes' => ['repo']],
				['provider' => 'mail', 'reason' => 'Notify', 'scopes' => ['send']],
				['provider' => 'gitlab', 'reason' => '', 'scopes' => ['api']],
				['provider' => 'deck', 'reason' => 'Map scopes', 'scopes' => []],
			],
			$descriptor['credentials']
		);

		$expected = self::manifest();
		$expected['credentials'] = [
			['provider' => 'github', 'reason' => 'Push', 'scopes' => ['repo']],
			['provider' => 'mail', 'scopes' => ['send'], 'reason' => 'Notify'],
			['reason' => 'no provider'],
			['reason' => 'Object provider'],
			['provider' => 'gitlab', 'scopes' => ['api']],
			['provider' => 'deck', 'reason' => 'Map scopes', 'scopes' => []],
		];
		$this->assertEquals($expected, json_decode($files['manifest.json'], true));
	}//end assertPublishedDeclarations()

	/**
	 * A manifest whose credential declarations carry secrets, next to a
	 * settings field whose name ends in `_token` (a field definition, not a secret).
	 *
	 * @return array<string,mixed>
	 */
	private static function manifest(): array {
		return [
			'version' => '1.0.0',
			'pages' => [['id' => 'home', 'title' => 'Home']],
			'settings' => ['registry_token' => ['type' => 'string', 'format' => 'password']],
			'credentials' => [
				[
					'provider' => 'github',
					'reason' => 'Push',
					'scopes' => ['repo'],
					'token' => 'ghp_secret-token-value',
					'secret' => 'client-secret-value',
					'password' => 'p4ssw0rd',
					'value' => 'inline-secret',
					'clientSecret' => 'oauth-client-secret',
					'credentialId' => '0b7d1f2e-credential-uuid',
					'auth' => ['token' => 'ghp_nested-auth-token'],
					'GITHUB_TOKEN' => 'ghp_env-style-token',
					'accessToken' => 'oauth-access-token',
					'refreshToken' => 'oauth-refresh-token',
					'privateKey' => '-----BEGIN PRIVATE KEY-----',
					'url' => 'https://user:url-password@example.org',
				],
				['provider' => 'mail', 'apiKey' => 'mail-api-key', 'scopes' => ['send'], 'reason' => 'Notify'],
				'bare-string-entry-token',
				['reason' => 'no provider'],
				['provider' => ['token' => 'provider-object-token'], 'reason' => 'Object provider'],
				['provider' => 'gitlab', 'reason' => ['secret' => 'reason-object-secret'], 'scopes' => ['api', ['token' => 'scope-item-token']]],
				['provider' => 'deck', 'reason' => 'Map scopes', 'scopes' => ['token' => 'scope-map-token']],
			],
		];
	}//end manifest()

	/**
	 * An app serialiser without data sources, as in the v1 construction.
	 *
	 * @return AppRepoSerializer
	 */
	private function appSerializer(): AppRepoSerializer {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new \RuntimeException('no register'));

		return new AppRepoSerializer(
			$registerMapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->templateSerializer(),
			new FakeSlugResolver(['integriq'])
		);
	}//end appSerializer()

	/**
	 * A template serialiser.
	 *
	 * @return TemplateRepoSerializer
	 */
	private function templateSerializer(): TemplateRepoSerializer {
		return new TemplateRepoSerializer($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class));
	}//end templateSerializer()
}//end class
