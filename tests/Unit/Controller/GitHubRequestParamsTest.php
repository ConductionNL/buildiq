<?php

/**
 * Unit tests for GitHubRequestParams.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\GitHubRequestParams;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see GitHubRequestParams}.
 */
final class GitHubRequestParamsTest extends TestCase {

	/**
	 * A request answering the given value for every parameter.
	 *
	 * @param mixed $value The parameter value.
	 *
	 * @return IRequest
	 */
	private function requestWith(mixed $value): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($value);
		return $request;

	}//end requestWith()

	/**
	 * Only a string is a search query; anything else is no query.
	 *
	 * @return void
	 */
	public function testSearchQueryKeepsStringsOnly(): void {
		self::assertSame('subsidie', GitHubRequestParams::searchQuery(request: $this->requestWith('subsidie')));
		self::assertSame('', GitHubRequestParams::searchQuery(request: $this->requestWith('')));
		self::assertNull(GitHubRequestParams::searchQuery(request: $this->requestWith(null)));
		self::assertNull(GitHubRequestParams::searchQuery(request: $this->requestWith(['a'])));

	}//end testSearchQueryKeepsStringsOnly()

	/**
	 * An empty or non-string credential is no credential.
	 *
	 * @return void
	 */
	public function testCredentialIdDropsEmptyAndNonStrings(): void {
		self::assertSame('cred-1', GitHubRequestParams::credentialId(request: $this->requestWith('cred-1')));
		self::assertNull(GitHubRequestParams::credentialId(request: $this->requestWith('')));
		self::assertNull(GitHubRequestParams::credentialId(request: $this->requestWith(null)));
		self::assertNull(GitHubRequestParams::credentialId(request: $this->requestWith(42)));

	}//end testCredentialIdDropsEmptyAndNonStrings()
}//end class
