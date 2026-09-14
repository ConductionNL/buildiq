<?php

/**
 * The automations channel reads the slug this instance's OWN register carries.
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

use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\TemplateRepoSerializer;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Migrated and unmigrated instances, told apart, for Buildiq's own register.
 *
 * ## Why this test is here at all
 *
 * `AppRepoSerializer` already injected {@see FakeSlugResolver}'s real
 * counterpart and already resolved the CONNECTOR register. Sixty lines below
 * that, `collectAutomations()` read `'register' => 'buildiq'` as a literal. The
 * class knew how to ask the question and this one read did not ask it.
 *
 * The failure mode is the one with no behaviour to watch. On an instance that
 * has not run this app's rename step the register answers to `openbuild`, the
 * literal matches no register row, `findAll()` returns zero rows, and the
 * published repository carries an automations section that is byte-for-byte
 * what an application with no automations produces. No exception, no 404, and
 * before this change no log line either.
 *
 * ## Watched failing, not assumed
 *
 * With `'register' => $registerSlug->slug` reverted to `'register' => 'buildiq'`
 * and the resolution branch removed, three of the four assertions reddened and
 * they were the right three:
 *
 *  - `testAnUnmigratedInstanceIsReadWithItsOldSlug` —
 *    "Failed asserting that two strings are identical. -'openbuild' +'buildiq'".
 *  - `testAnAbsentRegisterIsNotReadAndPublishesNoAutomations` —
 *    "Failed asserting that 'buildiq' is null", the read attempted anyway.
 *  - `testTheAutomationSurvivesOnAnUnmigratedInstance` —
 *    "Failed asserting that an array has the key 'automations/nightly-refresh.json'",
 *    which is the defect itself: the automation silently absent from the
 *    published repository.
 *
 * `testAMigratedInstanceIsReadWithItsNewSlug` did NOT redden, because on a
 * migrated instance the pinned literal happens to be the right answer. That is
 * exactly why this survived every test already written.
 *
 * @covers \OCA\Buildiq\Service\AppRepoSerializer
 *
 * @uses \OCA\Buildiq\Service\TemplateRepoSerializer
 * @uses \OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver
 */
final class OwnRegisterResolutionTest extends TestCase {

	/**
	 * The register slug the last `findAll()` was asked for.
	 *
	 * @var string|null
	 */
	private ?string $askedRegister = null;

	/**
	 * How many times `findAll()` was called.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Serialise one application against an instance carrying the given slugs.
	 *
	 * @param list<string> $present The register slugs this instance carries.
	 *
	 * @return array<string,mixed> The serialiser's `channels` descriptor input,
	 *                             reached through the public `serialize()`.
	 */
	private function serializeAgainst(array $present): array {
		$this->askedRegister = null;
		$this->reads = 0;

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($present): array {
				$this->reads++;
				$filters = [];
				if (is_array($config['filters'] ?? null) === true) {
					$filters = $config['filters'];
				}

				$register = (string)($filters['register'] ?? '');
				$schema = (string)($filters['schema'] ?? '');
				if ($schema !== 'automation') {
					return [];
				}

				$this->askedRegister = $register;

				// The instance only answers for the slug it actually carries,
				// which is the whole difference this test exists to see. Asked
				// for a slug this instance does not have, it returns zero rows —
				// byte for byte what a register holding no automations returns.
				if (in_array($register, $present, true) === false) {
					return [];
				}

				return [['slug' => 'nightly-refresh', 'enabled' => true]];
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$serializer = new AppRepoSerializer(
			registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			logger: $logger,
			templateSerializer: new TemplateRepoSerializer(
				schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
				logger: $logger
			),
			slugResolver: new FakeSlugResolver(present: $present),
			objectService: $objectService
		);

		$files = $serializer->serialize(
			[
				'slug' => 'hydra-console',
				'name' => 'Hydra Console',
				'description' => 'A demo',
				'appType' => 'virtual',
			],
			['manifest' => []]
		);

		return $files;
	}//end serializeAgainst()

	/**
	 * A migrated instance is read with the new slug.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceIsReadWithItsNewSlug(): void {
		$this->serializeAgainst(present: ['buildiq']);

		$this->assertSame(
			expected: 'buildiq',
			actual: $this->askedRegister,
			message: 'On an instance that has run the rename, the automations read must name buildiq.'
		);
	}//end testAMigratedInstanceIsReadWithItsNewSlug()

	/**
	 * An unmigrated instance is read with the OLD slug.
	 *
	 * This is the assertion the pinned literal could not pass, and the only one
	 * of the three that a reinstated pin reddens.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceIsReadWithItsOldSlug(): void {
		$this->serializeAgainst(present: ['openbuild']);

		$this->assertSame(
			expected: 'openbuild',
			actual: $this->askedRegister,
			message: 'On an instance that has NOT run the rename, the automations read must name openbuild.'
		);
	}//end testAnUnmigratedInstanceIsReadWithItsOldSlug()

	/**
	 * The automation still reaches the published repository on an unmigrated instance.
	 *
	 * Asserting the slug alone would not be enough: the defect is not that the
	 * wrong word was passed, it is that a feature quietly stopped happening.
	 *
	 * @return void
	 */
	public function testTheAutomationSurvivesOnAnUnmigratedInstance(): void {
		$files = $this->serializeAgainst(present: ['openbuild']);

		$this->assertArrayHasKey(
			key: 'automations/nightly-refresh.json',
			array: $files,
			message: 'The automation must be published on an unmigrated instance, not silently dropped.'
		);
	}//end testTheAutomationSurvivesOnAnUnmigratedInstance()

	/**
	 * A register that is on this instance under NO slug is not read at all.
	 *
	 * The branch has to come BEFORE the read, not after it: reading with the
	 * canonical slug and treating the empty result as "no automations" is the
	 * defect, restated.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterIsNotReadAndPublishesNoAutomations(): void {
		$files = $this->serializeAgainst(present: []);

		$this->assertNull(
			actual: $this->askedRegister,
			message: 'With the register absent, no automations read may be attempted at all.'
		);
		$this->assertArrayNotHasKey(key: 'automations/nightly-refresh.json', array: $files);
	}//end testAnAbsentRegisterIsNotReadAndPublishesNoAutomations()
}//end class
