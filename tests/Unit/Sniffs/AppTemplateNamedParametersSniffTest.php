<?php

/**
 * Unit tests for the app template's NamedParameters sniff: calls into the
 * app's own code ($this->, self::, static::, parent::, new OurClass) must use
 * named arguments; definitions, calls to other code and argument-free calls
 * pass.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The template sniff run through PHP_CodeSniffer on small snippets.
 */
#[CoversClass(NamedParametersSniff::class)]
final class AppTemplateNamedParametersSniffTest extends TestCase {

	/**
	 * The standard directory holding the sniff.
	 */
	private const STANDARD = __DIR__ . '/../../../lib/Resources/template/phpcs-custom-sniffs/CustomSniffs';

	/**
	 * The ruleset, built once: it loads every sniff of the standard.
	 *
	 * @var Ruleset|null
	 */
	private static ?Ruleset $ruleset = null;

	/**
	 * The config the ruleset was built with.
	 *
	 * @var Config|null
	 */
	private static ?Config $config = null;

	/**
	 * Run the sniff over a snippet and answer the line of every error it reports, with its code.
	 *
	 * @param string $code The PHP source.
	 *
	 * @return list<array{0:int,1:string}>
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
			$config            = new Config(['--standard=' . realpath(self::STANDARD), '-q'], false);
			$config->cache     = false;
			self::$config      = $config;
			self::$ruleset     = new Ruleset($config);
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
	 * Snippets inside a class body and whether the sniff reports them.
	 *
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function calls(): array {
		return [
			'positional $this call'         => ['$this->save($object);', true],
			'named $this call'              => ['$this->save(object: $object);', false],
			'one named one positional'      => ['$this->save(object: $object, $flag);', true],
			'positional after named'        => ['$this->save(object: $o, flag: true, $x);', true],
			'no arguments'                  => ['$this->save();', false],
			'only a comment'                => ['$this->save(/* nothing */);', false],
			'spread argument'               => ['$this->save(...$args);', false],
			'nested call stays nested'      => ['$this->save(object: foo($a, $b));', false],
			'array argument named'          => ['$this->save(items: [$a, $b], map: [\'k\' => $v]);', false],
			'closure argument named'        => ['$this->save(fn: function ($a, $b) { return [$a, $b]; });', false],
			'positional array'              => ['$this->save([$a, $b]);', true],
			'self call'                     => ['self::build($a);', true],
			'static call'                   => ['static::build($a);', true],
			'parent call'                   => ['parent::__construct($a);', true],
			'named parent call'             => ['parent::__construct(appName: $a);', false],
			'other object call'             => ['$other->save($object);', false],
			'other class static call'       => ['Other::build($a);', false],
			'global function'               => ['strlen($a);', false],
			'whitespace before paren'       => ['$this->save ($object);', true],
			'comment before argument name'  => ['$this->save(/* a */ object: $o);', false],
			'comment between arrow and var' => ['$this /* x */ ->save($o);', true],
			'constant access, no call'      => ['$x = self::LIMIT;', false],
		];
	}//end calls()

	/**
	 * Each call is reported exactly when it is a call into the app's own code with an unnamed argument.
	 *
	 * @param string $statement The statement inside a method body.
	 * @param bool   $reported  Whether the sniff reports it.
	 *
	 * @return void
	 */
	#[DataProvider('calls')]
	public function testACallIsReportedOnlyWhenItIsInternalAndUnnamed(string $statement, bool $reported): void {
		$code = "<?php\nnamespace OCA\\MyApp\\Service;\n\nclass Thing {\n\tpublic function run(): void {\n\t\t" . $statement . "\n\t}\n}\n";

		$expected = [];
		if ($reported === true) {
			$expected = [[6, 'CustomSniffs.Functions.NamedParameters.RequireNamedParameters']];
		}

		$this->assertSame($expected, $this->errorsIn(code: $code));
	}//end testACallIsReportedOnlyWhenItIsInternalAndUnnamed()

	/**
	 * Function and method definitions, with or without parameters, are never reported.
	 *
	 * @return void
	 */
	public function testDefinitionsAreNotCalls(): void {
		$code = "<?php\nnamespace OCA\\MyApp\\Service;\n\nfunction helper(\$a, \$b) {\n}\n\nclass Thing {\n\tpublic function save(\$object, \$flag = false): void {\n\t}\n\n\tprivate static function build(int \$a): self {\n\t\treturn new self();\n\t}\n}\n";

		$this->assertSame([], $this->errorsIn(code: $code));
	}//end testDefinitionsAreNotCalls()

	/**
	 * `new` of a class imported from the app's own namespace is checked; other classes are not.
	 *
	 * @return void
	 */
	public function testNewIsCheckedOnlyForClassesFromTheAppNamespace(): void {
		$code = "<?php\nnamespace OCA\\MyApp\\Service;\n\nuse OCA\\MyApp\\Db\\Record;\nuse OCA\\MyApp\\Db\\Other as Alias;\nuse OCA\\OtherApp\\Db\\Foreign;\nuse DateTime;\n\nclass Thing {\n\tpublic function run(): void {\n"
			. "\t\t\$a = new Record(\$id);\n"
			. "\t\t\$b = new Record(id: \$id);\n"
			. "\t\t\$c = new Foreign(\$id);\n"
			. "\t\t\$d = new DateTime(\$when);\n"
			. "\t\t\$e = new Unimported(\$id);\n"
			. "\t\t\$f = new Other(\$id);\n"
			. "\t}\n}\n";

		$this->assertSame(
			[[11, 'CustomSniffs.Functions.NamedParameters.RequireNamedParameters'], [16, 'CustomSniffs.Functions.NamedParameters.RequireNamedParameters']],
			$this->errorsIn(code: $code)
		);
	}//end testNewIsCheckedOnlyForClassesFromTheAppNamespace()

	/**
	 * Without a namespace, or with a single-segment one, no class counts as the app's own, so `new` is not checked.
	 *
	 * @return void
	 */
	public function testWithoutAnAppNamespaceNewIsNotChecked(): void {
		$withoutNamespace = "<?php\nuse OCA\\MyApp\\Db\\Record;\n\nclass Thing {\n\tpublic function run(): void {\n\t\t\$a = new Record(\$id);\n\t\t\$this->save(\$a);\n\t}\n}\n";
		$singleSegment    = "<?php\nnamespace MyApp;\n\nuse MyApp\\Record;\n\nclass Thing {\n\tpublic function run(): void {\n\t\t\$a = new Record(\$id);\n\t}\n}\n";

		$this->assertSame(
			[[7, 'CustomSniffs.Functions.NamedParameters.RequireNamedParameters']],
			$this->errorsIn(code: $withoutNamespace)
		);
		$this->assertSame([], $this->errorsIn(code: $singleSegment));
	}//end testWithoutAnAppNamespaceNewIsNotChecked()

	/**
	 * The error message names the called function.
	 *
	 * @return void
	 */
	public function testTheMessageNamesTheCall(): void {
		$code = "<?php\nnamespace OCA\\MyApp\\Service;\n\nclass Thing {\n\tpublic function run(): void {\n\t\t\$this->persist(\$x);\n\t}\n}\n";

		if (self::$ruleset === null) {
			$this->errorsIn(code: '<?php');
		}

		$file = new DummyFile($code, self::$ruleset, self::$config);
		$file->process();
		$messages = [];
		foreach (($file->getErrors()[6] ?? []) as $errors) {
			foreach ($errors as $error) {
				$messages[] = $error['message'];
			}
		}

		$this->assertSame(['All arguments in calls to internal code must use named parameters: persist(paramName: $value)'], $messages);
	}//end testTheMessageNamesTheCall()

	/**
	 * The sniff listens to name tokens only.
	 *
	 * @return void
	 */
	public function testTheSniffListensToNameTokens(): void {
		$this->assertSame([T_STRING], (new NamedParametersSniff())->register());
	}//end testTheSniffListensToNameTokens()
}//end class
