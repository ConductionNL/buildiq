<?php

/**
 * Unit tests for EmbedOrigins.
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
 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\EmbedOrigins;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An origin is an https scheme and host, optionally a port, nothing else.
 */
#[CoversClass(EmbedOrigins::class)]
final class EmbedOriginsTest extends TestCase {

	/**
	 * Origins and the reason each is refused, null for a valid one.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function origins(): array {
		return [
			'intranet' => ['https://intranet.example.nl', null],
			'with port' => ['https://intranet.example.nl:8443', null],
			'trimmed' => ['  https://intranet.example.nl ', null],
			'wildcard' => ['https://*.example.nl', 'A wildcard is not allowed: list each site separately.'],
			'http' => ['http://intranet.example.nl', 'A site must start with https://.'],
			'no scheme' => ['intranet.example.nl', 'A site must start with https://.'],
			'path' => ['https://intranet.example.nl/portal', 'A site is a scheme and a host only, without a path.'],
			'trailing slash' => ['https://intranet.example.nl/', 'A site is a scheme and a host only, without a path.'],
			'query' => ['https://intranet.example.nl?x=1', 'A site is a scheme and a host only, without a path.'],
			'user info' => ['https://user@intranet.example.nl', 'This is not a valid site address.'],
			'empty host' => ['https://', 'This is not a valid site address.'],
			'csp injection' => ['https://a.nl; script-src *', 'A wildcard is not allowed: list each site separately.'],
			'space' => ['https://a.nl b.nl', 'This is not a valid site address.'],
		];
	}//end origins()

	/**
	 * Each origin passes or is refused with its reason.
	 *
	 * @param string      $origin The origin.
	 * @param string|null $reason The expected reason.
	 *
	 * @return void
	 */
	#[DataProvider('origins')]
	public function testAnOriginPassesOrNamesWhyNot(string $origin, ?string $reason): void {
		$this->assertSame($reason, (new EmbedOrigins())->reason(origin: $origin));
	}//end testAnOriginPassesOrNamesWhyNot()

	/**
	 * An app with embedding on is framed by its valid origins, each once; an
	 * invalid stored origin is left out.
	 *
	 * @return void
	 */
	public function testAnAppWithEmbeddingOnIsFramedByItsValidOrigins(): void {
		$application = [
			'embed' => [
				'enabled' => true,
				'origins' => ['https://intranet.example.nl', 'https://*.example.nl', 'https://intranet.example.nl', 42, 'https://wiki.example.nl'],
			],
		];

		$this->assertSame(
			['https://intranet.example.nl', 'https://wiki.example.nl'],
			(new EmbedOrigins())->allowedFor(application: $application)
		);
	}//end testAnAppWithEmbeddingOnIsFramedByItsValidOrigins()

	/**
	 * Embedding off, absent, malformed or an unknown app frames nothing.
	 *
	 * @return void
	 */
	public function testEmbeddingOffFramesNothing(): void {
		$origins = new EmbedOrigins();

		$this->assertSame([], $origins->allowedFor(application: null));
		$this->assertSame([], $origins->allowedFor(application: ['slug' => 'room-bookings']));
		$this->assertSame([], $origins->allowedFor(application: ['embed' => ['enabled' => false, 'origins' => ['https://intranet.example.nl']]]));
		$this->assertSame([], $origins->allowedFor(application: ['embed' => ['enabled' => 'true', 'origins' => ['https://intranet.example.nl']]]));
		$this->assertSame([], $origins->allowedFor(application: ['embed' => ['enabled' => true, 'origins' => 'https://intranet.example.nl']]));
		$this->assertSame([], $origins->allowedFor(application: ['embed' => 'on']));
	}//end testEmbeddingOffFramesNothing()

	/**
	 * The register declares the same pattern on `Application.embed.origins`,
	 * so a save through OpenRegister refuses what the runtime would leave out.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresTheEmbedSettingOffByDefault(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/openbuild_register.json'), true);
		$embed = $register['components']['schemas']['Application']['properties']['embed'];

		$this->assertFalse($embed['properties']['enabled']['default']);
		$this->assertSame([], $embed['properties']['origins']['default']);
		$this->assertSame(
			str_replace('\\/', '/', trim(EmbedOrigins::PATTERN, '/')),
			$embed['properties']['origins']['items']['pattern']
		);
		$pattern = '#' . $embed['properties']['origins']['items']['pattern'] . '#';
		$this->assertSame(1, preg_match($pattern, 'https://intranet.example.nl'));
		$this->assertSame(0, preg_match($pattern, 'https://*.example.nl'));
		$this->assertSame(0, preg_match($pattern, 'https://intranet.example.nl/path'));
	}//end testTheRegisterDeclaresTheEmbedSettingOffByDefault()
}//end class
