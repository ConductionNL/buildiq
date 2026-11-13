<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests that the token scrubber redacts every credential shape a GitHub error can carry.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\GitHub;

use OCA\Buildiq\Service\GitHub\TokenScrubber;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\GitHubShopSearch;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Credential shapes in, redacted text out; ordinary text and commit ids stay.
 *
 * @covers \OCA\Buildiq\Service\GitHub\TokenScrubber
 * @uses \OCA\Buildiq\Service\GitHubShopSearch
 */
class TokenScrubberTest extends TestCase {
	/**
	 * Text carrying a credential, and the text with it redacted.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function credentials(): array {
		// Built at run time so no token-shaped literal sits in the source.
		$body = str_repeat('Ab3', 12);

		return [
			'classic token' => ['push failed: gh' . 'p_' . $body, 'push failed: [redacted-token]'],
			'classic token, upper case' => ['GH' . 'S_' . $body . ' refused', '[redacted-token] refused'],
			'fine-grained token' => ['github' . '_pat_11' . $body . '_' . $body, '[redacted-token]'],
			'installation token' => ['v1' . '.' . str_repeat('0a1b', 10) . ' expired', '[redacted-token] expired'],
			'jwt' => ['jwt ey' . 'JhbGciOiJSUzI1NiJ9.ey' . 'Jpc3MiOiIxMjM0NSJ9.c2lnbmF0dXJl', 'jwt [redacted-token]'],
			'legacy 40-hex after token' => [
				'Authorization: token 0123456789abcdef0123456789abcdef01234567',
				'Authorization: token [redacted-token]',
			],
			'bearer' => ['Bearer abcdefghijklmnopqrstuvwxyz012345', 'Bearer [redacted-token]'],
			'basic' => ['Authorization: Basic eC1hY2Nlc3MtdG9rZW46Z2hzX3NlY3JldA==', 'Authorization: Basic [redacted-token]'],
			'url password' => [
				'https://x-access-token:s3cr3tvalue@github.com/org/repo.git',
				'https://x-access-token:[redacted-token]@github.com/org/repo.git',
			],
			'query parameter' => ['?access_token=0123456789abcdef0123456789abcdef01234567&x=1', '?access_token=[redacted-token]&x=1'],
		];
	}//end credentials()

	/**
	 * Every credential shape is redacted.
	 *
	 * @param string $raw      The text with a credential.
	 * @param string $scrubbed The text expected back.
	 *
	 * @return void
	 *
	 * @dataProvider credentials
	 */
	public function testACredentialIsRedacted(string $raw, string $scrubbed): void {
		$this->assertSame(expected: $scrubbed, actual: TokenScrubber::scrub(message: $raw));
	}//end testACredentialIsRedacted()

	/**
	 * Text without a credential, a commit id among it, comes back unchanged.
	 *
	 * @return void
	 */
	public function testOrdinaryTextIsKept(): void {
		$text = 'commit 0123456789abcdef0123456789abcdef01234567 not found; the token expired at noon';
		$this->assertSame(expected: $text, actual: TokenScrubber::scrub(message: $text));
	}//end testOrdinaryTextIsKept()

	/**
	 * A failed shop search logs the error without the token it carried.
	 *
	 * @return void
	 */
	public function testAFailedShopSearchLogsNoToken(): void {
		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);

		$search = new GitHubShopSearch(catalogService: $this->createMock(originalClassName: GitHubCatalogService::class), logger: $logger);
		$search->failed(error: new RuntimeException(message: '401 for Bearer gh' . 'p_' . str_repeat('Ab3', 12)));

		$this->assertCount(expectedCount: 1, haystack: $logged);
		$this->assertStringNotContainsString(needle: 'ghp_', haystack: $logged[0]);
		$this->assertStringContainsString(needle: '[redacted-token]', haystack: $logged[0]);
	}//end testAFailedShopSearchLogsNoToken()
}//end class
