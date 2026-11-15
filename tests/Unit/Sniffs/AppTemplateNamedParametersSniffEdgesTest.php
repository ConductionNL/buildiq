<?php

/**
 * Unit tests for the app template's NamedParameters sniff on malformed or
 * unusual token streams: an unclosed call, a call with nothing before it, and
 * an empty use statement ahead of a `new`.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Sniffs
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

namespace OCA\Buildiq\Tests\Unit\Sniffs;

require_once __DIR__ . '/../../../vendor/squizlabs/php_codesniffer/autoload.php';
// Through PHP_CodeSniffer's own loader, which the ruleset uses too, so the sniff is declared once.
\PHP_CodeSniffer\Autoload::loadFile(__DIR__ . '/../../../lib/Resources/template/phpcs-custom-sniffs/CustomSniffs/Sniffs/Functions/NamedParametersSniff.php');

use CustomSniffs\Sniffs\Functions\NamedParametersSniff;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Util\Tokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Edge token streams the sniff must survive without a false report.
 */
#[CoversClass(NamedParametersSniff::class)]
final class AppTemplateNamedParametersSniffEdgesTest extends TestCase {

	/**
	 * The template's custom standard.
	 */
	private const STANDARD = __DIR__ . '/../../../lib/Resources/template/phpcs-custom-sniffs/CustomSniffs';

	/**
	 * The error source this sniff reports.
	 */
	private const SOURCE = 'CustomSniffs.Functions.NamedParameters.RequireNamedParameters';

	/**
	 * Shared ruleset.
	 *
	 * @var Ruleset|null
	 */
	private static ?Ruleset $ruleset = null;

	/**
	 * Shared config.
	 *
	 * @var Config|null
	 */
	private static ?Config $config = null;

	/**
	 * The [line, source] pairs the sniff reports for some code.
	 *
	 * @param string $code The file contents.
	 *
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function errorsIn(string $code): array {
		if (self::$ruleset === null) {
			if (defined('PHP_CODESNIFFER_VERBOSITY') === false) {
				define('PHP_CODESNIFFER_VERBOSITY', 0);
			}

			if (defined('PHP_CODESNIFFER_CBF') === false) {
				define('PHP_CODESNIFFER_CBF', false);
			}

			class_exists(Tokens::class);
			$config = new Config(['--standard=' . realpath(self::STANDARD), '-q'], false);
			$config->cache = false;
			self::$config = $config;
			self::$ruleset = new Ruleset($config);
		}

		$file = new DummyFile($code, self::$ruleset, self::$config);
		$file->process();

		$found = [];
		foreach ($file->getErrors() as $line => $columns) {
			foreach ($columns as $errors) {
				foreach ($errors as $error) {
					$found[] = [$line, $error['source']];
				}
			}
		}

		return $found;
	}//end errorsIn()

	/**
	 * A call cut off at the end of the file has no closing parenthesis and is
	 * not reported; the complete call before it still is.
	 *
	 * @return void
	 */
	public function testAnUnclosedCallIsNotReported(): void {
		$code = "<?php\nnamespace OCA\\MyApp\\Service;\n\nclass Thing {\n\tpublic function run(): void {\n\t\t\$this->save(\$a);\n\t\t\$this->save(\$b";

		$this->assertSame([[6, self::SOURCE]], $this->errorsIn(code: $code));
	}//end testAnUnclosedCallIsNotReported()

	/**
	 * A call that is the first statement of the file, with no statement
	 * boundary before it, is still read as a call.
	 *
	 * @return void
	 */
	public function testACallWithNothingBeforeItIsStillACall(): void {
		$this->assertSame([[1, self::SOURCE]], $this->errorsIn(code: "<?php \$this->save(\$a);\n"));
		$this->assertSame([], $this->errorsIn(code: "<?php \$this->save(object: \$a);\n"));
	}//end testACallWithNothingBeforeItIsStillACall()

	/**
	 * An empty use statement is skipped while the imports are searched, and
	 * the import after it still makes `new` a call into the app.
	 *
	 * @return void
	 */
	public function testAnEmptyUseStatementIsSkipped(): void {
		$code = "<?php\nnamespace OCA\\MyApp\\Service;\n\nuse ;\nuse OCA\\MyApp\\Db\\Record;\n\nclass Thing {\n\tpublic function run(): void {\n"
			. "\t\t\$a = new Record(\$id);\n"
			. "\t\t\$b = new Unimported(\$id);\n"
			. "\t}\n}\n";

		$this->assertSame([[9, self::SOURCE]], $this->errorsIn(code: $code));
	}//end testAnEmptyUseStatementIsSkipped()
}//end class
