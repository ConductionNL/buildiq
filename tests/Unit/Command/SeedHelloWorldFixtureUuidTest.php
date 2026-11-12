<?php

/**
 * Characterisation test for the UUID the seed command pre-mints.
 *
 * The hybrid example Application is created with a UUID minted before the
 * object exists. This pins that the command's generator emits a canonical
 * RFC 4122 v4 UUID every time, so sharing the generator with ExportJobService
 * cannot change its shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Command
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

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\SeedHelloWorldFixture;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the shape of the pre-minted Application UUID.
 */
class SeedHelloWorldFixtureUuidTest extends TestCase {
	/**
	 * Every minted UUID is a canonical, distinct RFC 4122 v4 UUID.
	 *
	 * @return void
	 */
	public function testMintedUuidsAreCanonicalV4(): void {
		$command = new SeedHelloWorldFixture(
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
		);
		$mint = new ReflectionMethod($command, 'uuid4');

		$seen = [];
		for ($i = 0; $i < 25; $i++) {
			$uuid = $mint->invoke($command);
			self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
			self::assertArrayNotHasKey($uuid, $seen);
			$seen[$uuid] = true;
		}
	}//end testMintedUuidsAreCanonicalV4()
}//end class
