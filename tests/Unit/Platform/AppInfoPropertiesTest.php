<?php

/**
 * The licence, the absence of seat or licence-key checks, and the language
 * model reached only through Nextcloud Task Processing.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Platform
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/platform-properties/spec.md (REQ-PLAT-002, REQ-PLAT-007, REQ-PLAT-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reads appinfo/info.xml, LICENSE and the PHP under lib/.
 */
final class AppInfoPropertiesTest extends TestCase {
	/**
	 * The app root.
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * Every PHP file under a directory of the app, by its path from the root.
	 *
	 * @param string $dir The directory, from the app root.
	 *
	 * @return array<string, string> The sources by path.
	 */
	private static function sources(string $dir): array {
		$out = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT . '/' . $dir));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$out[substr($file->getPathname(), strlen(self::ROOT) + 1)] = (string)file_get_contents($file->getPathname());
			}
		}

		ksort($out);
		return $out;
	}//end sources()

	/**
	 * The paths whose source matches a pattern.
	 *
	 * @param array<string, string> $sources The sources.
	 * @param string                $pattern The pattern.
	 *
	 * @return list<string>
	 */
	private static function matching(array $sources, string $pattern): array {
		return array_keys(array_filter($sources, static fn (string $source): bool => preg_match($pattern, $source) === 1));
	}//end matching()

	/**
	 * The app declares the EUPL-1.2, and LICENSE is its text (REQ-PLAT-009).
	 *
	 * @return void
	 */
	public function testTheAppIsLicensedUnderTheEupl(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		self::assertNotFalse($info);
		self::assertSame('EUPL-1.2', (string)$info->licence);

		$licence = (string)file_get_contents(self::ROOT . '/LICENSE');
		self::assertStringStartsWith('EUROPEAN UNION PUBLIC LICENCE v. 1.2', $licence);
	}//end testTheAppIsLicensedUnderTheEupl()

	/**
	 * Nothing under lib/ counts seats or checks a licence key (REQ-PLAT-009).
	 *
	 * @return void
	 */
	public function testNoSeatOrLicenceKeyCheck(): void {
		$sources = self::sources('lib');
		self::assertNotEmpty($sources);
		self::assertSame([], self::matching($sources, '/maxUsers|seatCount|licen[cs]eKey/i'));
	}//end testNoSeatOrLicenceKeyCheck()

	/**
	 * The app needs no service outside the instance to start: OpenRegister is
	 * its only required app, declared in the manifest, and info.xml requires
	 * no other app (REQ-PLAT-007).
	 *
	 * @return void
	 */
	public function testOpenRegisterIsTheOnlyRequiredApp(): void {
		$manifest = json_decode((string)file_get_contents(self::ROOT . '/src/manifest.json'), true);
		self::assertSame(['openregister'], $manifest['dependencies']);

		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		self::assertNotFalse($info);
		self::assertCount(0, $info->dependencies->app);
	}//end testOpenRegisterIsTheOnlyRequiredApp()

	/**
	 * No service names a model vendor's client or a model API key, and the
	 * copilot reaches its model through Task Processing (REQ-PLAT-002).
	 *
	 * @return void
	 */
	public function testTheModelIsReachedOnlyThroughTaskProcessing(): void {
		$services = self::sources('lib/Service');
		self::assertSame([], self::matching($services, '/openai|anthropic|mistral\.ai|generativelanguage/i'));
		self::assertSame([], self::matching($services, '/\w*(model|llm)\w*api_?key|api_?key\w*(model|llm)/i'));

		$copilot = $services['lib/Service/CopilotService.php'];
		self::assertStringContainsString("container->get('OCP\\\\TaskProcessing\\\\IManager')", $copilot);
	}//end testTheModelIsReachedOnlyThroughTaskProcessing()
}//end class
