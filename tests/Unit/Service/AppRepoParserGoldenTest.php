<?php

/**
 * Golden test for the payloads and refusals AppRepoParser produces.
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

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Service\AppRepoParser;
use PHPUnit\Framework\TestCase;

/**
 * Replays recorded repo file maps through the parser: every descriptor,
 * manifest and companion-schema refusal (code, message, file path), the
 * v1 and v2 payload shapes, and every channel path rule (safe paths,
 * prefixes, `.json` suffix, slug / uuid / connector-kind keys, size and
 * depth bounds, verbatim skills).
 *
 * @covers \OCA\Buildiq\Service\AppRepoParser
 */
final class AppRepoParserGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/app-repo-parser-golden.json';

	/**
	 * Every recorded case parses, or is refused, exactly as recorded.
	 *
	 * @return void
	 */
	public function testCasesReplayAsRecorded(): void {
		$fixture = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($fixture['cases']);

		foreach ($fixture['cases'] as $case) {
			$this->assertSame(
				$this->recorded(case: $case),
				$this->replay(case: $case),
				'Case: ' . $case['name']
			);
		}

	}//end testCasesReplayAsRecorded()

	/**
	 * The recorded outcome of a case.
	 *
	 * @param array<string,mixed> $case The recorded case.
	 *
	 * @return array<string,mixed>
	 */
	private function recorded(array $case): array {
		if (isset($case['error']) === true) {
			return ['error' => $case['error']];
		}

		return ['result' => $case['result']];

	}//end recorded()

	/**
	 * Parse a case's file map and capture the payload or the refusal.
	 *
	 * @param array<string,mixed> $case The recorded case.
	 *
	 * @return array<string,mixed>
	 */
	private function replay(array $case): array {
		$files = [];
		foreach ($case['files'] as $path => $contents) {
			$files[(string)$path] = $this->expand(contents: $contents);
		}

		try {
			return ['result' => (new AppRepoParser())->parse($files, $case['repo'])];
		} catch (AppRepoParseException $e) {
			return ['error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'filePath' => $e->getFilePath()]];
		}

	}//end replay()

	/**
	 * Rebuild an over-size file body from its recorded marker.
	 *
	 * @param string|array<string,mixed> $contents The recorded contents or marker.
	 *
	 * @return string
	 */
	private function expand(string|array $contents): string {
		if (is_string($contents) === true) {
			return $contents;
		}

		return $contents['head'] . str_repeat($contents['fill'], $contents['repeat']) . $contents['tail'];

	}//end expand()
}//end class
