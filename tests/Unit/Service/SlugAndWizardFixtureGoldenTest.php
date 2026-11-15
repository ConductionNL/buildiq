<?php

/**
 * Slug validation and the wizard's fixture loading answer as recorded before
 * their shared error and loading code was extracted.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ApplicationCreationService;
use OCA\Buildiq\Service\SlugValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The fixture holds, recorded on the old code, the answer of both slug
 * validators for every slug below, and a hash of each decoded wizard fixture
 * as the former loadDefaultManifest() and loadDefaultSchemas() returned it.
 *
 * @covers \OCA\Buildiq\Service\SlugValidator
 * @covers \OCA\Buildiq\Service\ApplicationCreationService
 */
class SlugAndWizardFixtureGoldenTest extends TestCase {

	/**
	 * The recorded answers.
	 *
	 * @return array<string,mixed>
	 */
	private static function golden(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/slug-and-wizard-fixture-golden.json'), true);
	}//end golden()

	/**
	 * Both validators answer every recorded slug as before.
	 *
	 * @return void
	 */
	public function testSlugValidatorsAnswerAsRecorded(): void {
		$validator = (new ReflectionClass(SlugValidator::class))->newInstanceWithoutConstructor();
		foreach (['validateAppSlug', 'validateVersionSlug'] as $method) {
			foreach (self::golden()[$method] as [$slug, $answer]) {
				$this->assertSame(expected: $answer, actual: $validator->$method($slug), message: $method . ' ' . $slug);
			}
		}
	}//end testSlugValidatorsAnswerAsRecorded()

	/**
	 * Both wizard fixtures decode to the recorded content.
	 *
	 * @return void
	 */
	public function testWizardFixturesDecodeAsRecorded(): void {
		$service = (new ReflectionClass(ApplicationCreationService::class))->newInstanceWithoutConstructor();
		$fixtures = [
			'loadDefaultManifest' => ['name' => 'manifest', 'shape' => 'object'],
			'loadDefaultSchemas' => ['name' => 'schemas', 'shape' => 'array'],
		];
		foreach ($fixtures as $former => $fixture) {
			$decoded = (new ReflectionMethod($service, 'loadWizardFixture'))->invoke($service, $fixture);
			$this->assertSame(expected: self::golden()[$former], actual: md5((string)json_encode($decoded)), message: $former);
		}
	}//end testWizardFixturesDecodeAsRecorded()
}//end class
