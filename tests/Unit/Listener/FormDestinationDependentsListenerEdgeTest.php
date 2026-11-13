<?php

/**
 * Unit tests for the FormDestinationDependentsListener paths its main test
 * leaves out: a schema without a slug, a form list that cannot be read,
 * entity rows, forms whose register is missing or unreadable, a form with no
 * author, and unpublishing into the fallback register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\FormDestinationDependentsListener;
use OCA\Buildiq\Service\FormDestinationCheck;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\Buildiq\Service\RegistrationFormTargetWarnings;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge cases of FormDestinationDependentsListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FormDestinationDependentsListenerEdgeTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Errors logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * The register slug the app config answers.
	 *
	 * @var string
	 */
	private string $registerSlug = 'buildiq';

	/**
	 * Fresh object service per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->errors = [];
	}//end setUp()

	/**
	 * A schema without a slug announces nothing and lists nothing.
	 *
	 * @return void
	 */
	public function testASchemaWithoutASlugIsIgnored(): void {
		$this->objects->expects($this->never())->method('findAll');
		$event = new FormDestinationDependentsEvent($this->schema(slug: ''));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getForms());
	}//end testASchemaWithoutASlugIsIgnored()

	/**
	 * A form list that cannot be read is logged as an error and announces nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableFormListIsLogged(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$failure = new RuntimeException('register gone');
		$this->objects->method('findAll')->willThrowException($failure);
		$event = new FormDestinationDependentsEvent($this->schema(slug: 'Case'));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getForms());
		$this->assertSame(
			[['Buildiq: could not list registration forms for a schema change: register gone', ['exception' => $failure]]],
			$this->errors
		);
	}//end testAnUnreadableFormListIsLogged()

	/**
	 * Entity rows are read with their uuid and owner; rows that are neither entity nor array are skipped.
	 *
	 * @return void
	 */
	public function testEntityRowsCarryTheirUuidAndOwner(): void {
		$this->objects->expects($this->once())
			->method('setRegister')
			->with('buildiq')
			->willReturnSelf();
		$this->objects->method('findAll')
			->with(['filters' => ['status' => 'published'], 'limit' => 1000], false, false)
			->willReturn([$this->entityRow(), 'not a row']);
		$event = new FormDestinationDependentsEvent($this->schema(slug: 'case'));

		$this->listener()->handle($event);

		$forms = $event->getForms();
		$this->assertCount(1, $forms);
		$this->assertSame('rf-uuid', $forms[0]->id);
		$this->assertSame('bob', $forms[0]->author);
		$this->assertNull($forms[0]->audience);
	}//end testEntityRowsCarryTheirUuidAndOwner()

	/**
	 * A form whose register is empty, unknown to the mapper, or does not hold the schema is not announced.
	 *
	 * @return void
	 */
	public function testAFormInAnUnreadableRegisterIsSkipped(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willReturn(
			[
				$this->form(['id' => 'rf-1', 'register' => '']),
				$this->form(['id' => 'rf-2', 'register' => 'missing']),
				$this->form(['id' => 'rf-3']),
			]
		);
		$event = new FormDestinationDependentsEvent($this->schema(slug: 'case'));

		$this->listener()->handle($event);

		$this->assertSame(['rf-3'], array_map(static fn ($f): string => $f->id, $event->getForms()));
		$this->assertNull($event->getForms()[0]->author);
	}//end testAFormInAnUnreadableRegisterIsSkipped()

	/**
	 * An emptied register setting falls back to buildiq, for listing and for unpublishing.
	 *
	 * @return void
	 */
	public function testAnEmptiedRegisterSettingFallsBackToBuildiq(): void {
		$this->registerSlug = '';
		$this->objects->expects($this->once())->method('setRegister')->with('buildiq')->willReturnSelf();
		$this->objects->method('findAll')->willReturn([$this->form(['id' => 'rf-3'])]);
		$this->objects->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(static fn (array $object): bool => $object['status'] === 'draft' && $object['id'] === 'rf-3'),
				[],
				'buildiq',
				'registrationForm',
				'rf-3',
				false,
				false
			)
			->willReturn($this->createMock(ObjectEntityInterface::class));
		$event = new FormDestinationDependentsEvent($this->schema(slug: 'case'));

		$this->listener()->handle($event);
		($event->getForms()[0]->unpublish)();
	}//end testAnEmptiedRegisterSettingFallsBackToBuildiq()

	/**
	 * A published form on `case` in dossiq, without an owner.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function form(array $overrides): array {
		return array_merge(
			['name' => 'Aanvraag', 'status' => 'published', 'register' => 'dossiq', 'schema' => 'case', 'fields' => []],
			$overrides
		);
	}//end form()

	/**
	 * A stored form as an entity, with no id in its data.
	 *
	 * @return ObjectEntityInterface The entity.
	 */
	private function entityRow(): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($this->form([]));
		$entity->method('getUuid')->willReturn('rf-uuid');
		$entity->method('getOwner')->willReturn('bob');
		return $entity;
	}//end entityRow()

	/**
	 * The changed schema, id 7.
	 *
	 * @param string $slug Its slug.
	 *
	 * @return Schema The schema.
	 */
	private function schema(string $slug): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getId')->willReturn(7);
		return $schema;
	}//end schema()

	/**
	 * The listener; dossiq holds schema 7, any other register is unknown.
	 *
	 * @return FormDestinationDependentsListener The listener.
	 */
	private function listener(): FormDestinationDependentsListener {
		$dossiq = $this->createMock(Register::class);
		$dossiq->method('getSchemas')->willReturn(['7']);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function (string|int $id) use ($dossiq): Register {
				if ($id !== 'dossiq') {
					throw new RuntimeException('no register ' . $id);
				}

				return $dossiq;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('buildiq', 'register', 'buildiq')->willReturnCallback(fn (): string => $this->registerSlug);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, $context];
			}
		);

		$forms = new RegistrationFormTargetWarnings(
			check: new FormDestinationCheck(
				container: $this->createMock(ContainerInterface::class),
				schemas: new RegistrationFormTargetSchemaReader(
					registerMapper: $registerMapper,
					schemaMapper: $this->createMock(SchemaMapper::class),
					logger: $this->createMock(LoggerInterface::class),
				),
				logger: $this->createMock(LoggerInterface::class),
			),
		);

		return new FormDestinationDependentsListener(
			objectService: $this->objects,
			registerMapper: $registerMapper,
			appConfig: $appConfig,
			forms: $forms,
			logger: $logger,
		);
	}//end listener()
}//end class
