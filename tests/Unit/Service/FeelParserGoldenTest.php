<?php

/**
 * Golden test for the ASTs and syntax errors FeelParser produces.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\FeelParser;
use PHPUnit\Framework\TestCase;

/**
 * Pins every lexer branch (whitespace, strings, numbers, decimals, ranges,
 * two- and one-character operators, punctuation, identifiers, keywords,
 * paths) and every comparison and primary branch, for accepted expressions
 * and for each syntax-error message.
 *
 * @covers \OCA\Buildiq\Service\FeelParser
 */
final class FeelParserGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/feel-parser-golden.json';

	/**
	 * Read the recorded fixture.
	 *
	 * @return array{ok:array<string,array<string,mixed>>,bad:array<string,string>}
	 */
	private function fixture(): array {
		return json_decode((string)file_get_contents(self::FIXTURE), true);

	}//end fixture()

	/**
	 * Every accepted expression parses to its recorded AST.
	 *
	 * @return void
	 */
	public function testAcceptedExpressionsParseAsRecorded(): void {
		foreach ($this->fixture()['ok'] as $expression => $ast) {
			$this->assertSame($ast, (new FeelParser())->parse((string)$expression), 'Expression: ' . $expression);
		}

	}//end testAcceptedExpressionsParseAsRecorded()

	/**
	 * Every rejected expression throws its recorded message.
	 *
	 * @return void
	 */
	public function testRejectedExpressionsThrowAsRecorded(): void {
		foreach ($this->fixture()['bad'] as $expression => $message) {
			$actual = null;
			try {
				(new FeelParser())->parse((string)$expression);
			} catch (InvalidArgumentException $e) {
				$actual = $e->getMessage();
			}

			$this->assertSame($message, $actual, 'Expression: ' . $expression);
		}

	}//end testRejectedExpressionsThrowAsRecorded()
}//end class
