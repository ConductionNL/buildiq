<?php

/**
 * Source-tree scan assertion (automation-document-action task 5.2 /
 * `docudesk-document-templates` REQ-DDT-006 "Contract surface is closed"):
 * no `OCA\Filinq\*` class is imported or referenced anywhere in
 * `DocumentGenerationService` or `DocumentGenerationListener` — the
 * document-generation call path is an HTTP call to the pinned
 * `correspondence/generate` route, never a PHP class import.
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
 *
 * @spec openspec/changes/automation-document-action/tasks.md#5.2
 * @spec openspec/changes/automation-document-action/specs/docudesk-document-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-tree assertion — no `OCA\Filinq\*` reference in the
 * automation-triggered document-generation call path.
 */
final class DocumentGenerationNoDocudeskImportTest extends TestCase {
	/**
	 * Every file in the automation-triggered document-generation call path
	 * that MUST NOT reference a Docudesk PHP class.
	 *
	 * @var array<int,string>
	 */
	private const GUARDED_FILES = [
		__DIR__ . '/../../../lib/Service/DocumentGenerationService.php',
		__DIR__ . '/../../../lib/Listener/DocumentGenerationListener.php',
		__DIR__ . '/../../../lib/Controller/GeneratedDocumentController.php',
	];

	/**
	 * No LIVE `OCA\DocuDesk` reference (a `use` import, a type-hint, an
	 * instantiation, or a static call) appears in any guarded file. Doc-
	 * comment prose that merely NAMES the forbidden namespace while
	 * explaining why it is not imported (this class's own docblock does
	 * exactly that) is intentionally tolerated — only PHP/PHPDoc comments
	 * are stripped before scanning, so a real `use OCA\Filinq\...;`
	 * import would still fail this assertion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-document-action/tasks.md#5.2
	 */
	public function testNoDocudeskNamespaceReferenced(): void {
		foreach (self::GUARDED_FILES as $path) {
			$this->assertFileExists($path);
			$contents = file_get_contents($path);
			$this->assertIsString($contents);
			$code = $this->stripComments($contents);
			$this->assertStringNotContainsString(
				'OCA\\DocuDesk',
				$code,
				'File "' . basename($path) . '" must not reference the OCA\\DocuDesk namespace in live code — '
				. 'the automation-triggered call path is HTTP-only (REQ-DDT-006).'
			);
		}

	}//end testNoDocudeskNamespaceReferenced()

	/**
	 * Strip `/** ... *\/`, `// ...` and `# ...` PHP comments from source so
	 * only live code is scanned.
	 *
	 * @param string $source The raw PHP source.
	 *
	 * @return string
	 */
	private function stripComments(string $source): string {
		$withoutBlockComments = (string)preg_replace('#/\*.*?\*/#s', '', $source);
		return (string)preg_replace('#(^|\s)//[^\n]*#', '', $withoutBlockComments);
	}//end stripComments()
}//end class
