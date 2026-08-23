<?php

/**
 * Unit tests for ObjectSchemaSlugResolver.
 *
 * WHY THESE DOUBLES EXTEND THE REAL `OCP\AppFramework\Db\Entity`
 * ------------------------------------------------------------
 * The resolver's guards were written with `method_exists()`. OpenRegister's
 * `ObjectEntity`, `Schema` and `Register` extend Nextcloud's `Entity`, which
 * serves every column accessor through `__call()` and declares it only as an
 * `@method` docblock. `method_exists()` is therefore FALSE for `getSchema()`,
 * `getRegister()` and `getSlug()` on the real classes — so all three guards took
 * their false branch forever and the resolver returned '' for every real entity.
 *
 * `tests/stubs/openregister-stubs.php` cannot expose this: it DECLARES those
 * accessors concretely, on purpose, because PHPUnit 10 removed `addMethods()`
 * and `createMock()->method('getUuid')` cannot configure a magic method. That
 * stub therefore inverts the exact predicate under test — it makes
 * `method_exists()` TRUE in the suite and FALSE in production.
 *
 * So these fixtures extend the REAL `Entity` and declare only properties. Every
 * accessor below is reached the way production reaches it, through `__call`.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://buildiq.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ObjectSchemaSlugResolver;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An ObjectEntity-shaped double: register/schema reachable ONLY via __call.
 */
class MagicObjectEntityFixture extends Entity {

	/**
	 * Register id, as OpenRegister stores it.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * Schema id, as OpenRegister stores it — `(string) $schema->getId()`.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * A genuinely concrete accessor, declared here as the control. If
	 * `method_exists()` were false for this too, the assertions below would be
	 * measuring a broken fixture rather than the `__call` mechanism.
	 *
	 * @return string
	 */
	public function getConcreteControl(): string {
		return 'concrete';
	}//end getConcreteControl()
}//end class

/**
 * A Schema/Register-shaped double: slug reachable ONLY via __call.
 */
class MagicSlugEntityFixture extends Entity {

	/**
	 * The slug.
	 *
	 * @var string|null
	 */
	protected ?string $slug = null;
}//end class

/**
 * @covers \OCA\Buildiq\Service\ObjectSchemaSlugResolver
 */
class ObjectSchemaSlugResolverTest extends TestCase {

	/**
	 * Build an ObjectEntity-shaped double carrying the given ids.
	 *
	 * @param string $registerId The register id.
	 * @param string $schemaId The schema id.
	 *
	 * @return MagicObjectEntityFixture
	 */
	private function entity(string $registerId, string $schemaId): MagicObjectEntityFixture {
		$entity = new MagicObjectEntityFixture();
		$entity->setRegister($registerId);
		$entity->setSchema($schemaId);

		return $entity;
	}//end entity()

	/**
	 * Build a resolver whose mappers return the given id => slug maps.
	 *
	 * @param array<string,string> $schemas Schema id => slug.
	 * @param array<string,string> $registers Register id => slug.
	 *
	 * @return ObjectSchemaSlugResolver
	 */
	private function resolver(array $schemas, array $registers): ObjectSchemaSlugResolver {
		$mapperFor = static function (array $map) {
			return new class($map) {
				/**
				 * @param array<string,string> $map Id => slug.
				 */
				public function __construct(
					private array $map,
				) {
				}

				/**
				 * Mirror SchemaMapper/RegisterMapper::find().
				 *
				 * @param string $id The id.
				 * @param array $extend Extend.
				 * @param bool $rbac RBAC.
				 * @param bool $tenant Multitenancy.
				 *
				 * @return MagicSlugEntityFixture
				 *
				 * @throws \RuntimeException When the id is unknown.
				 */
				public function find(string $id, array $extend = [], bool $rbac = true, bool $tenant = true): MagicSlugEntityFixture {
					if (isset($this->map[$id]) === false) {
						throw new \RuntimeException('not found');
					}

					$entity = new MagicSlugEntityFixture();
					$entity->setSlug($this->map[$id]);

					return $entity;
				}
			};
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($mapperFor, $schemas, $registers) {
				if (str_contains($class, 'SchemaMapper') === true) {
					return $mapperFor($schemas);
				}

				return $mapperFor($registers);
			}
		);

		return new ObjectSchemaSlugResolver($container, $this->createMock(LoggerInterface::class));
	}//end resolver()

	/**
	 * PREMISE CONTROL. Documents the fact the production bug rested on, with a
	 * concrete control (`getId()`, a real method on Entity) that must differ.
	 *
	 * This test is independent of the fix: it stays green either way.
	 *
	 * @return void
	 */
	public function testMagicAccessorsAreInvisibleToMethodExists(): void {
		$entity = $this->entity('11', '28');

		// The bug: the probe the resolver used.
		$this->assertFalse(method_exists($entity, 'getSchema'));
		$this->assertFalse(method_exists($entity, 'getRegister'));
		$this->assertFalse(method_exists(new MagicSlugEntityFixture(), 'getSlug'));

		// The fix: the probe the resolver now uses.
		$this->assertTrue(is_callable([$entity, 'getSchema']));
		$this->assertTrue(is_callable([$entity, 'getRegister']));

		// CONTROL — a genuinely concrete method must be true for BOTH, otherwise
		// the assertions above would just be measuring a broken fixture.
		$this->assertTrue(method_exists($entity, 'getConcreteControl'));
		$this->assertTrue(is_callable([$entity, 'getConcreteControl']));

		// NOT a typo, and worth its own assertion: `getId()` is ALSO magic on
		// Nextcloud's Entity — it is an `@method` docblock, not a real method,
		// in both `nextcloud/ocp` and the server's own
		// lib/public/AppFramework/Db/Entity.php. Any code elsewhere in the fleet
		// that probes `method_exists($entity, 'getId')` as a "this is a real
		// entity" test is therefore also permanently false.
		$this->assertFalse(method_exists($entity, 'getId'));

		// And the values really are reachable through __call.
		$this->assertSame('28', $entity->getSchema());
		$this->assertSame('11', $entity->getRegister());
	}//end testMagicAccessorsAreInvisibleToMethodExists()

	/**
	 * Resolving a schema slug must work on an entity whose accessors are magic.
	 *
	 * @return void
	 */
	public function testSchemaSlugResolvesThroughMagicAccessor(): void {
		$resolver = $this->resolver(['28' => 'application'], ['11' => 'openbuild']);

		$this->assertSame('application', $resolver->schemaSlug(entity: $this->entity('11', '28')));
	}//end testSchemaSlugResolvesThroughMagicAccessor()

	/**
	 * Resolving a register slug must work on an entity whose accessors are magic.
	 *
	 * @return void
	 */
	public function testRegisterSlugResolvesThroughMagicAccessor(): void {
		$resolver = $this->resolver(['28' => 'application'], ['11' => 'openbuild']);

		$this->assertSame('openbuild', $resolver->registerSlug(entity: $this->entity('11', '28')));
	}//end testRegisterSlugResolvesThroughMagicAccessor()

	/**
	 * The capability the two listeners depend on: an buildiq `application`
	 * object must be recognised as one.
	 *
	 * @return void
	 */
	public function testIsBuildiqSchemaMatchesBuildiqsOwnObject(): void {
		$resolver = $this->resolver(['28' => 'application'], ['11' => 'openbuild']);

		$this->assertTrue(
			$resolver->isBuildiqSchema(entity: $this->entity('11', '28'), schemaSlug: 'application')
		);
	}//end testIsBuildiqSchemaMatchesBuildiqsOwnObject()

	/**
	 * Fail-closed behaviour must be preserved: a same-named schema in another
	 * register must NOT match. `automation` is not a unique slug instance-wide.
	 *
	 * This test is green both before and after the fix — before, because
	 * everything returned ''; after, because the register genuinely differs. It
	 * is here so the fix cannot be "proven" by loosening the match.
	 *
	 * @return void
	 */
	public function testIsBuildiqSchemaRejectsSameSlugInAnotherRegister(): void {
		$resolver = $this->resolver(['5103' => 'automation'], ['99' => 'someotherapp']);

		$this->assertFalse(
			$resolver->isBuildiqSchema(entity: $this->entity('99', '5103'), schemaSlug: 'automation')
		);
	}//end testIsBuildiqSchemaRejectsSameSlugInAnotherRegister()

	/**
	 * An unresolvable schema must still yield '', so an entity from a register
	 * this instance does not know keeps the handlers' fail-closed behaviour.
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaStillYieldsEmptyString(): void {
		$resolver = $this->resolver([], []);

		$this->assertSame('', $resolver->schemaSlug(entity: $this->entity('11', '28')));
	}//end testUnresolvableSchemaStillYieldsEmptyString()

	/**
	 * A non-entity object must not fatal — `is_callable()` is true for ANY name
	 * on a `__call` class, so the resolver must be exception-safe rather than
	 * relying on the probe as a membership test.
	 *
	 * @return void
	 */
	public function testPlainObjectWithoutAccessorsYieldsEmptyString(): void {
		$resolver = $this->resolver(['28' => 'application'], ['11' => 'openbuild']);

		$this->assertSame('', $resolver->schemaSlug(entity: new \stdClass()));
		$this->assertSame('', $resolver->registerSlug(entity: new \stdClass()));
	}//end testPlainObjectWithoutAccessorsYieldsEmptyString()
}//end class
