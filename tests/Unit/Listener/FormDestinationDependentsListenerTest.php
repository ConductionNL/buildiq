<?php

/**
 * Unit tests for FormDestinationDependentsListener.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers which forms are announced, what each carries, and that unpublishing
 * sets the form back to draft.
 */
final class FormDestinationDependentsListenerTest extends TestCase {
	/**
	 * The objects saved through the double, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Only published forms on the changed schema, in a register that holds it, are announced.
	 *
	 * @return void
	 */
	public function testOnlyPublishedFormsOnTheChangedSchemaAreAnnounced(): void {
		$listener = $this->listenerOver(
			rows: [
				$this->form(['id' => 'rf-1']),
				$this->form(['id' => 'rf-2', 'status' => 'draft']),
				$this->form(['id' => 'rf-3', 'schema' => 'ticket']),
				$this->form(['id' => 'rf-4', 'register' => 'pipelinq']),
			]
		);
		$event = new FormDestinationDependentsEvent($this->schema());

		$listener->handle($event);

		self::assertSame(['rf-1'], array_map(static fn ($f): string => $f->id, $event->getForms()));
	}//end testOnlyPublishedFormsOnTheChangedSchemaAreAnnounced()

	/**
	 * An announced form carries buildiq's app id, its title, author, audience and mapping.
	 *
	 * @return void
	 */
	public function testAnAnnouncedFormCarriesWhatTheSchemaSaveNeeds(): void {
		$listener = $this->listenerOver(
			rows: [$this->form(['isPublic' => true, 'presets' => [['field' => 'caseType', 'value' => 'x']]])]
		);
		$event = new FormDestinationDependentsEvent($this->schema());

		$listener->handle($event);
		$form = $event->getForms()[0];

		self::assertSame('buildiq', $form->app);
		self::assertSame('Aanvraag', $form->title);
		self::assertSame('alice', $form->author);
		self::assertSame('public', $form->audience);
		self::assertSame(['fields' => [['field' => 'title', 'property' => 'title', 'type' => 'text']], 'fixed' => ['caseType' => 'x']], $form->mapping);
	}//end testAnAnnouncedFormCarriesWhatTheSchemaSaveNeeds()

	/**
	 * Unpublishing sets the form back to draft in buildiq's own register.
	 *
	 * @return void
	 */
	public function testUnpublishingSetsTheFormBackToDraft(): void {
		$listener = $this->listenerOver(rows: [$this->form()]);
		$event = new FormDestinationDependentsEvent($this->schema());
		$listener->handle($event);

		($event->getForms()[0]->unpublish)();

		self::assertSame('draft', $this->saved[0]['status']);
		self::assertSame('rf-1', $this->saved[0]['id']);
		self::assertArrayNotHasKey('@self', $this->saved[0]);
	}//end testUnpublishingSetsTheFormBackToDraft()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$listener = $this->listenerOver(rows: [$this->form()]);

		$listener->handle(new Event());

		self::assertSame([], $this->saved);
	}//end testAnotherEventIsIgnored()

	/**
	 * The changed schema: `case`, id 7.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('case');
		$schema->method('getId')->willReturn(7);

		return $schema;
	}//end schema()

	/**
	 * One published registration form into dossiq `case`, as findAll returns it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function form(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'rf-1',
				'name' => 'Aanvraag',
				'status' => 'published',
				'register' => 'dossiq',
				'schema' => 'case',
				'audience' => 'client',
				'fields' => [['name' => 'title', 'type' => 'text']],
				'@self' => ['owner' => 'alice'],
			],
			$overrides
		);
	}//end form()

	/**
	 * The listener over these stored rows; dossiq holds schema 7, pipelinq does not.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored forms.
	 *
	 * @return FormDestinationDependentsListener The listener.
	 */
	private function listenerOver(array $rows): FormDestinationDependentsListener {
		$this->saved = [];
		$objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($rows);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->saved[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$dossiq = $this->createMock(Register::class);
		$dossiq->method('getSchemas')->willReturn([7, 8]);
		$pipelinq = $this->createMock(Register::class);
		$pipelinq->method('getSchemas')->willReturn([9]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(static fn (string|int $id) => ($id === 'dossiq' ? $dossiq : $pipelinq));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

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
			objectService: $objectService,
			registerMapper: $registerMapper,
			appConfig: $appConfig,
			forms: $forms,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listenerOver()
}//end class
