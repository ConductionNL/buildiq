<?php

/**
 * Unit tests for the save-time half of forms-live-values-and-checks
 * (REQ-BQLV-005): the binding index, the recomputer and the listener.
 *
 * The events are OpenRegister's ObjectCreatingEvent and ObjectUpdatingEvent
 * as the test stubs define them, with the accessors of the real classes on
 * openregister development (getObject / getNewObject, stopPropagation,
 * setErrors, setModifiedData). The rule engine is a double of buildiq's own
 * RuleEngineService: its decision tables are covered by RuleEngineServiceTest,
 * and here only the calls the save makes and what it does with the answers
 * matter.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Listener\FormLiveValuesListener;
use OCA\Buildiq\Service\FormLiveBindingIndex;
use OCA\Buildiq\Service\FormLiveValuesRecomputer;
use OCA\Buildiq\Service\ObjectSchemaSlugResolver;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for FormLiveValuesListener, FormLiveBindingIndex and FormLiveValuesRecomputer.
 */
class FormLiveValuesListenerTest extends TestCase {

	/**
	 * In-memory app config values.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * The rule engine double.
	 *
	 * @var RuleEngineService&MockObject
	 */
	private RuleEngineService&MockObject $engine;

	/**
	 * The listener under test.
	 *
	 * @var FormLiveValuesListener
	 */
	private FormLiveValuesListener $listener;

	/**
	 * The index under test.
	 *
	 * @var FormLiveBindingIndex
	 */
	private FormLiveBindingIndex $index;

	/**
	 * Build the listener over an in-memory app config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$app . '.' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app . '.' . $key] = $value;
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->engine = $this->createMock(RuleEngineService::class);
		$this->index = new FormLiveBindingIndex(appConfig: $appConfig, logger: $logger);
		$this->listener = new FormLiveValuesListener(
			slugs: new ObjectSchemaSlugResolver(container: $this->createMock(ContainerInterface::class), logger: $logger),
			index: $this->index,
			recomputer: new FormLiveValuesRecomputer(engine: $this->engine, logger: $logger),
			logger: $logger
		);

	}//end setUp()

	/**
	 * A manifest with a permit form: a fee calculated from the attendees, and
	 * a blocking eligibility check.
	 *
	 * @return array<string,mixed>
	 */
	private function permitVersion(): array {
		return [
			'slug' => 'production',
			'register' => 'openbuild-permits-production',
			'manifest' => [
				'pages' => [
					['id' => 'home', 'type' => 'index', 'config' => []],
					[
						'id' => 'apply',
						'type' => 'form',
						'config' => [
							'submitEndpoint' => '/apps/openregister/api/objects/{registerSlug}/permit',
							'fields' => [
								['key' => 'attendees', 'label' => 'Attendees', 'type' => 'number'],
								[
									'key' => 'fee',
									'label' => 'Fee',
									'type' => 'number',
									'calculate' => ['ruleSet' => 'event-fee', 'output' => 'fee', 'inputs' => ['attendees']],
								],
								['key' => 'applicantEmail', 'label' => 'E-mail', 'type' => 'string', 'default' => '@me.email'],
							],
							'eligibility' => [
								'ruleSet' => 'permit-eligibility',
								'passWhen' => ['output' => 'decision', 'equals' => 'approved'],
								'explainWith' => 'reason',
								'blockSubmit' => true,
							],
						],
					],
				],
			],
		];

	}//end permitVersion()

	/**
	 * An object entity in a register and schema, given by slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $register, string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setUuid('11111111-2222-3333-4444-555555555555');
		$entity->setObject($data);
		return $entity;

	}//end entity()

	/**
	 * Save the permit app's version, so the index knows its form.
	 *
	 * @return void
	 */
	private function savePermitVersion(): void {
		$this->listener->handle(new ObjectUpdatingEvent($this->entity('buildiq', 'applicationVersion', $this->permitVersion())));

	}//end savePermitVersion()

	/**
	 * Saving an app version indexes its form's calculated fields and check,
	 * with the register token replaced by the version's register.
	 *
	 * @return void
	 */
	public function testSavingAVersionIndexesItsLiveForm(): void {
		$this->savePermitVersion();

		$bindings = $this->index->bindingsFor(registerKeys: ['openbuild-permits-production'], schemaKeys: ['permit']);

		self::assertCount(1, $bindings);
		self::assertSame(
			[['field' => 'fee', 'ruleSet' => 'event-fee', 'output' => 'fee', 'inputs' => ['attendees']]],
			$bindings[0]['calculate']
		);
		self::assertSame('permit-eligibility', $bindings[0]['eligibility']['ruleSet']);
		self::assertSame([], $this->index->bindingsFor(registerKeys: ['openbuild-permits-production'], schemaKeys: ['other']));

	}//end testSavingAVersionIndexesItsLiveForm()

	/**
	 * A version whose forms have nothing live leaves no entry behind.
	 *
	 * @return void
	 */
	public function testAVersionWithoutLiveFieldsIsDropped(): void {
		$this->savePermitVersion();
		$plain = $this->permitVersion();
		$plain['manifest']['pages'][1]['config']['fields'] = [['key' => 'a', 'label' => 'A', 'type' => 'string']];
		unset($plain['manifest']['pages'][1]['config']['eligibility']);

		$this->listener->handle(new ObjectUpdatingEvent($this->entity('buildiq', 'applicationVersion', $plain)));

		self::assertTrue($this->index->isEmpty());

	}//end testAVersionWithoutLiveFieldsIsDropped()

	/**
	 * REQ-BQLV-005 scenario: a fee altered in the browser to 0 is replaced
	 * by the amount the rule set gives, and the evaluation is logged (not a
	 * preview).
	 *
	 * @return void
	 */
	public function testAFeeChangedInTheBrowserIsCorrected(): void {
		$this->savePermitVersion();
		$this->engine->method('evaluate')->willReturnCallback(
			static function (string $slug, array $payload, ?string $version = null, bool $dryRun = false, bool $maskPii = true, bool $preview = false): array {
				self::assertFalse($preview, 'a save-time evaluation is logged');
				if ($slug === 'event-fee') {
					self::assertSame(['attendees' => 250], $payload);
					return ['result' => ['fee' => 120], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []];
				}

				return ['result' => ['decision' => 'approved', 'reason' => ''], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []];
			}
		);

		$event = new ObjectCreatingEvent($this->entity('openbuild-permits-production', 'permit', ['attendees' => 250, 'fee' => 0]));
		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['fee' => 120], $event->getModifiedData());

	}//end testAFeeChangedInTheBrowserIsCorrected()

	/**
	 * A blocking eligibility check that fails on the server refuses the save
	 * with the rule set's explanation.
	 *
	 * @return void
	 */
	public function testAFailingBlockingCheckRefusesTheSave(): void {
		$this->savePermitVersion();
		$this->engine->method('evaluate')->willReturnCallback(
			static function (string $slug): array {
				if ($slug === 'event-fee') {
					return ['result' => ['fee' => 40], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []];
				}

				return ['result' => ['decision' => 'deny', 'reason' => 'Eligibility criteria not met'], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []];
			}
		);

		$event = new ObjectUpdatingEvent($this->entity('openbuild-permits-production', 'permit', ['attendees' => 40]));
		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(422, $event->getErrors()['status']);
		self::assertSame('buildiq.form_live.not_eligible', $event->getErrors()['code']);
		self::assertSame('Eligibility criteria not met', $event->getErrors()['message']);

	}//end testAFailingBlockingCheckRefusesTheSave()

	/**
	 * A calculation the server cannot run refuses the save rather than store
	 * the browser's value.
	 *
	 * @return void
	 */
	public function testAnUnrunnableCalculationRefusesTheSave(): void {
		$this->savePermitVersion();
		$this->engine->method('evaluate')->willThrowException(new RuntimeException('RuleSet "event-fee" not found.', 404));

		$event = new ObjectCreatingEvent($this->entity('openbuild-permits-production', 'permit', ['attendees' => 250, 'fee' => 0]));
		$this->listener->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('buildiq.form_live.calculation_failed', $event->getErrors()['code']);

	}//end testAnUnrunnableCalculationRefusesTheSave()

	/**
	 * An object in a schema no live form targets is left alone and costs no
	 * evaluation.
	 *
	 * @return void
	 */
	public function testAnUnrelatedObjectIsLeftAlone(): void {
		$this->savePermitVersion();
		$this->engine->expects($this->never())->method('evaluate');

		$event = new ObjectCreatingEvent($this->entity('openbuild-permits-production', 'note', ['text' => 'x']));
		$this->listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());

	}//end testAnUnrelatedObjectIsLeftAlone()

	/**
	 * The listener is registered for both save events by the app itself, so
	 * the guard has a call site (a guard with a full suite and no wiring
	 * protects nothing).
	 *
	 * @return void
	 */
	public function testTheAppRegistersTheListenerForBothSaveEvents(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		self::assertContains([ObjectCreatingEvent::class, FormLiveValuesListener::class], $registered);
		self::assertContains([ObjectUpdatingEvent::class, FormLiveValuesListener::class], $registered);

	}//end testTheAppRegistersTheListenerForBothSaveEvents()
}//end class
