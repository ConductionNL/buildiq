<?php

/**
 * CopilotAssistService unit test.
 *
 * The builder assist for each of its four targets: a checked proposal comes
 * back with its explanation, a refused answer gets exactly one repair
 * round-trip, a second refusal is the 422 `assist_invalid` envelope with the
 * checker's message, explain mode returns no value, and the app scope is
 * checked before any text task runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Copilot
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

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use OCA\Buildiq\Exception\CopilotException;
use OCA\Buildiq\Service\Copilot\CopilotAssistService;
use OCA\Buildiq\Service\Copilot\CopilotTextTask;
use OCA\Buildiq\Service\CopilotService;
use OCA\Buildiq\Service\FeelCellCondition;
use OCA\Buildiq\Service\FeelParser;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Buildiq\Service\Copilot\CopilotAssistService
 * @uses   \OCA\Buildiq\Exception\CopilotException
 * @uses   \OCA\Buildiq\Service\FeelCellCondition
 * @uses   \OCA\Buildiq\Service\FeelParser
 * @uses   \OCA\Buildiq\Service\RegistrationFormTargetSchemaReader
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class CopilotAssistServiceTest extends TestCase {

	/**
	 * Availability and the write-role check.
	 *
	 * @var CopilotService&MockObject
	 */
	private CopilotService&MockObject $copilot;

	/**
	 * The language model seam.
	 *
	 * @var CopilotTextTask&MockObject
	 */
	private CopilotTextTask&MockObject $textTask;

	/**
	 * Reads the app's versions.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * A service whose app `permits` has one version register holding schema `permit`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->copilot = $this->createMock(CopilotService::class);
		$this->copilot->method('health')->willReturn(['available' => true]);
		$this->textTask = $this->createMock(CopilotTextTask::class);
		$this->textTask->method('manager')->willReturn(new stdClass());
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('searchObjectsBySlug')->willReturn([
			['uuid' => 'v-1', 'slug' => 'main', 'register' => 'openbuild-permits-main'],
		]);
	}//end setUp()

	/**
	 * The service under test, over a register holding only `permit` with `name` and `ownerName`.
	 *
	 * @return CopilotAssistService
	 */
	private function service(): CopilotAssistService {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('permit');
		$schema->method('getProperties')->willReturn(['name' => ['type' => 'string'], 'ownerName' => ['type' => 'string']]);

		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([7]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function (string $slug) use ($register): Register {
				if ($slug !== 'openbuild-permits-main') {
					throw new RuntimeException('No register ' . $slug);
				}

				return $register;
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new CopilotAssistService(
			copilotService: $this->copilot,
			textTask: $this->textTask,
			feelParser: new FeelParser(),
			cellCondition: new FeelCellCondition(),
			schemaReader: new RegistrationFormTargetSchemaReader(
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
				logger: $this->createMock(LoggerInterface::class),
			),
			objectService: $this->objects,
		);
	}//end service()

	/**
	 * The app `permits` resolves for the caller.
	 *
	 * @return void
	 */
	private function appResolves(): void {
		$this->copilot->expects($this->once())->method('requireWritableApp')
			->with('permits', 'alice')
			->willReturn(['uuid' => 'app-1', 'slug' => 'permits', 'dataRegisters' => []]);
	}//end appResolves()

	/**
	 * The model answers with these texts, in order.
	 *
	 * @param string ...$answers The answers.
	 *
	 * @return void
	 */
	private function modelAnswers(string ...$answers): void {
		$this->textTask->expects($this->exactly(count($answers)))->method('run')->willReturnOnConsecutiveCalls(...$answers);
	}//end modelAnswers()

	/**
	 * A FEEL condition that parses comes back with its explanation, from one call.
	 *
	 * @return void
	 */
	public function testItProposesAConditionThatParses(): void {
		$this->appResolves();
		$this->modelAnswers('```json' . "\n" . '{"value": "applicant.age < 18", "explanation": "Applicants younger than 18."}' . "\n```");

		$result = $this->service()->assist(
			target: 'feel-condition',
			mode: 'write',
			request: 'applicants younger than 18',
			current: '',
			appSlug: 'permits',
			context: ['paths' => ['applicant.age']],
			userId: 'alice'
		);

		$this->assertSame(['mode' => 'write', 'value' => 'applicant.age < 18', 'explanation' => 'Applicants younger than 18.'], $result);
	}//end testItProposesAConditionThatParses()

	/**
	 * The prompt carries the names from the context and the request, and nothing else.
	 *
	 * @return void
	 */
	public function testThePromptCarriesTheContextNames(): void {
		$this->appResolves();
		$this->textTask->expects($this->once())->method('run')
			->with(
				$this->anything(),
				$this->callback(
					static fn (string $prompt): bool => str_contains($prompt, 'Payload paths: applicant.age, applicant.country')
						&& str_contains($prompt, 'applicants younger than 18')
						&& str_contains($prompt, 'No function calls') === true
				),
				'alice',
				'permits'
			)
			->willReturn('{"value": "applicant.age < 18", "explanation": "Under 18."}');

		$this->service()->assist(
			target: 'feel-condition',
			mode: 'write',
			request: 'applicants younger than 18',
			current: '',
			appSlug: 'permits',
			context: ['paths' => ['applicant.age', ' applicant.country ', 42, 'applicant.age']],
			userId: 'alice'
		);
	}//end testThePromptCarriesTheContextNames()

	/**
	 * A condition FeelParser refuses gets one repair round-trip, whose prompt names the error.
	 *
	 * @return void
	 */
	public function testARefusedConditionIsRepairedOnce(): void {
		$this->appResolves();
		$prompts = [];
		$this->textTask->expects($this->exactly(2))->method('run')->willReturnCallback(
			static function (object $manager, string $prompt) use (&$prompts): string {
				$prompts[] = $prompt;
				return count($prompts) === 1
					? '{"value": "max(applicant.age) < 18", "explanation": "x"}'
					: '{"value": "applicant.age < 18", "explanation": "Under 18."}';
			}
		);

		$result = $this->service()->assist(
			target: 'feel-condition',
			mode: 'write',
			request: 'under 18',
			current: '',
			appSlug: 'permits',
			context: [],
			userId: 'alice'
		);

		$this->assertSame('applicant.age < 18', $result['value']);
		$this->assertStringContainsString('max(applicant.age) < 18', $prompts[1]);
		$this->assertStringContainsString('The condition does not parse', $prompts[1]);
	}//end testARefusedConditionIsRepairedOnce()

	/**
	 * Two refused answers are the 422 assist_invalid envelope carrying the checker's message.
	 *
	 * @return void
	 */
	public function testTwoRefusedAnswersAre422AssistInvalid(): void {
		$this->appResolves();
		$this->modelAnswers('not json', '{"value": "= 18", "explanation": "x"}');

		try {
			$this->service()->assist(
				target: 'feel-condition',
				mode: 'write',
				request: 'under 18',
				current: '',
				appSlug: 'permits',
				context: [],
				userId: 'alice'
			);
			$this->fail('A twice-refused answer must not come back.');
		} catch (CopilotException $e) {
			$this->assertSame('assist_invalid', $e->getErrorCode());
			$this->assertSame(422, $e->getHttpStatus());
			$this->assertStringStartsWith('The condition does not parse', $e->getMessage());
		}
	}//end testTwoRefusedAnswersAre422AssistInvalid()

	/**
	 * A decision row comes back with trimmed cells and its decision.
	 *
	 * @return void
	 */
	public function testItProposesADecisionRow(): void {
		$this->appResolves();
		$this->modelAnswers('{"value": {"conditions": {"age": " >= 18 ", "country": "in (\'NL\', \'BE\')"}, "decision": "approve"}, "explanation": "Adults from NL or BE."}');

		$result = $this->service()->assist(
			target: 'decision-rule',
			mode: 'write',
			request: 'approve adults from NL or BE',
			current: '',
			appSlug: 'permits',
			context: ['columns' => ['age', 'country']],
			userId: 'alice'
		);

		$this->assertSame(['conditions' => ['age' => '>= 18', 'country' => "in ('NL', 'BE')"], 'decision' => 'approve'], $result['value']);
	}//end testItProposesADecisionRow()

	/**
	 * A row naming a column the table lacks, then a row with a lone `=`, is refused with the cell message.
	 *
	 * @return void
	 */
	public function testADecisionRowWithAnUnknownColumnOrBadCellIsRefused(): void {
		$this->appResolves();
		$this->modelAnswers(
			'{"value": {"conditions": {"income": "> 1"}, "decision": "approve"}, "explanation": "x"}',
			'{"value": {"conditions": {"age": "=18"}, "decision": "approve"}, "explanation": "x"}'
		);

		$this->expectExceptionObject(new CopilotException(errorCode: 'assist_invalid', message: "The cell for 'age' is not a valid condition.", httpStatus: 422));

		$this->service()->assist(
			target: 'decision-rule',
			mode: 'write',
			request: 'approve adults',
			current: '',
			appSlug: 'permits',
			context: ['columns' => ['age']],
			userId: 'alice'
		);
	}//end testADecisionRowWithAnUnknownColumnOrBadCellIsRefused()

	/**
	 * A decision row needs the table's input columns, refused before any text task.
	 *
	 * @return void
	 */
	public function testADecisionRowWithoutColumnsIsRefusedBeforeTheModel(): void {
		$this->appResolves();
		$this->textTask->expects($this->never())->method('run');

		$this->expectExceptionMessage('Add an input column before asking for a rule.');

		$this->service()->assist(
			target: 'decision-rule',
			mode: 'write',
			request: 'approve adults',
			current: '',
			appSlug: 'permits',
			context: [],
			userId: 'alice'
		);
	}//end testADecisionRowWithoutColumnsIsRefusedBeforeTheModel()

	/**
	 * A mapping onto the target schema's properties comes back as the textarea's JSON.
	 *
	 * @return void
	 */
	public function testItProposesAFieldMappingOntoTheTargetSchema(): void {
		$this->appResolves();
		$this->textTask->expects($this->once())->method('run')
			->with($this->anything(), $this->stringContains('Properties of the target schema: name, ownerName'))
			->willReturn('{"value": {"name": "{{ object.title }}"}, "explanation": "Copies the title."}');

		$result = $this->service()->assist(
			target: 'field-mapping',
			mode: 'write',
			request: 'copy the title into the name',
			current: '',
			appSlug: 'permits',
			context: ['targetSchema' => 'permit', 'register' => 'openbuild-permits-main'],
			userId: 'alice'
		);

		$this->assertSame("{\n    \"name\": \"{{ object.title }}\"\n}", $result['value']);
	}//end testItProposesAFieldMappingOntoTheTargetSchema()

	/**
	 * A mapping that writes a property the schema lacks, twice, is 422 naming it.
	 *
	 * @return void
	 */
	public function testAMappingToAMissingPropertyIsRefusedNamingIt(): void {
		$this->appResolves();
		$this->modelAnswers(
			'{"value": {"ownerEmail": "{{ object.email }}"}, "explanation": "x"}',
			'{"value": "{\"ownerEmail\": \"x\", \"name\": \"y\"}", "explanation": "x"}'
		);

		$this->expectExceptionObject(new CopilotException(errorCode: 'assist_invalid', message: 'The target schema has no property ownerEmail.', httpStatus: 422));

		$this->service()->assist(
			target: 'field-mapping',
			mode: 'write',
			request: 'store the email',
			current: '',
			appSlug: 'permits',
			context: ['targetSchema' => 'permit'],
			userId: 'alice'
		);
	}//end testAMappingToAMissingPropertyIsRefusedNamingIt()

	/**
	 * A schema in another app's register is not found, and no text task runs.
	 *
	 * @return void
	 */
	public function testAForeignRegisterIsNotFoundBeforeTheModel(): void {
		$this->appResolves();
		$this->textTask->expects($this->never())->method('run');

		try {
			$this->service()->assist(
				target: 'field-mapping',
				mode: 'write',
				request: 'map it',
				current: '',
				appSlug: 'permits',
				context: ['targetSchema' => 'permit', 'register' => 'openbuild-other-main'],
				userId: 'alice'
			);
			$this->fail('A foreign register must be refused.');
		} catch (CopilotException $e) {
			$this->assertSame(404, $e->getHttpStatus());
			$this->assertSame("This app has no schema 'permit'.", $e->getMessage());
		}
	}//end testAForeignRegisterIsNotFoundBeforeTheModel()

	/**
	 * A schema slug the app's registers do not hold is not found.
	 *
	 * @return void
	 */
	public function testASchemaOutsideTheAppIsNotFound(): void {
		$this->appResolves();
		$this->textTask->expects($this->never())->method('run');

		$this->expectExceptionObject(new CopilotException(errorCode: 'not_found', message: "This app has no schema 'invoice'.", httpStatus: 404));

		$this->service()->assist(
			target: 'field-mapping',
			mode: 'write',
			request: 'map it',
			current: '',
			appSlug: 'permits',
			context: ['targetSchema' => 'invoice'],
			userId: 'alice'
		);
	}//end testASchemaOutsideTheAppIsNotFound()

	/**
	 * A payload template must be JSON; an object comes back pretty-printed.
	 *
	 * @return void
	 */
	public function testItProposesAPayloadTemplate(): void {
		$this->appResolves();
		$this->modelAnswers(
			'{"value": "{not json", "explanation": "x"}',
			'{"value": {"id": "{{ object.uuid }}", "tags": ["a"]}, "explanation": "Sends the id."}'
		);

		$result = $this->service()->assist(
			target: 'payload-template',
			mode: 'write',
			request: 'send the id',
			current: '',
			appSlug: 'permits',
			context: [],
			userId: 'alice'
		);

		$this->assertSame(['id' => '{{ object.uuid }}', 'tags' => ['a']], json_decode($result['value'], true));
		$this->assertSame('Sends the id.', $result['explanation']);
	}//end testItProposesAPayloadTemplate()

	/**
	 * Explain mode returns the description and no value, even when the model sends one.
	 *
	 * @return void
	 */
	public function testExplainReturnsNoValue(): void {
		$this->appResolves();
		$this->textTask->expects($this->once())->method('run')
			->with($this->anything(), $this->logicalAnd($this->stringContains('>= 18'), $this->stringContains('Do not propose a new value')))
			->willReturn('{"explanation": "Approve applicants aged 18 or over.", "value": "x"}');

		$result = $this->service()->assist(
			target: 'decision-rule',
			mode: 'explain',
			request: '',
			current: '{"conditions": {"age": ">= 18"}, "decision": "approve"}',
			appSlug: 'permits',
			context: ['columns' => ['age']],
			userId: 'alice'
		);

		$this->assertSame(['mode' => 'explain', 'explanation' => 'Approve applicants aged 18 or over.'], $result);
	}//end testExplainReturnsNoValue()

	/**
	 * A caller without write access gets the 403 from the role check and no text task runs.
	 *
	 * @return void
	 */
	public function testAViewerIsRefusedBeforeTheModel(): void {
		$this->copilot->method('requireWritableApp')->willThrowException(
			new CopilotException(errorCode: 'forbidden', message: 'No access.', httpStatus: 403)
		);
		$this->textTask->expects($this->never())->method('run');

		$this->expectExceptionObject(new CopilotException(errorCode: 'forbidden', message: 'No access.', httpStatus: 403));

		$this->service()->assist(
			target: 'feel-condition',
			mode: 'write',
			request: 'under 18',
			current: '',
			appSlug: 'permits',
			context: [],
			userId: 'bob'
		);
	}//end testAViewerIsRefusedBeforeTheModel()

	/**
	 * Bad arguments are 422 invalid_arguments before the app is even looked up.
	 *
	 * @return void
	 */
	public function testBadArgumentsAreRefusedFirst(): void {
		$this->copilot->expects($this->never())->method('requireWritableApp');
		$service = $this->service();

		$cases = [
			['sql', 'write', 'x', '', 'permits', 'target must be one of'],
			['feel-condition', 'rewrite', 'x', '', 'permits', 'mode must be write or explain.'],
			['feel-condition', 'write', 'x', '', '', 'appSlug is required.'],
			['feel-condition', 'write', '  ', '', 'permits', 'The request must be between 1 and 2000 characters.'],
			['feel-condition', 'write', str_repeat('a', 2001), '', 'permits', 'The request must be between 1 and 2000 characters.'],
			['feel-condition', 'explain', '', ' ', 'permits', 'There is nothing to explain yet.'],
		];

		foreach ($cases as [$target, $mode, $request, $current, $appSlug, $message]) {
			try {
				$service->assist(target: $target, mode: $mode, request: $request, current: $current, appSlug: $appSlug, context: [], userId: 'alice');
				$this->fail('Expected a refusal for ' . $target . '/' . $mode);
			} catch (CopilotException $e) {
				$this->assertSame('invalid_arguments', $e->getErrorCode());
				$this->assertStringStartsWith($message, $e->getMessage());
			}
		}
	}//end testBadArgumentsAreRefusedFirst()

	/**
	 * No provider is the 503 envelope, after the access check and before any text task.
	 *
	 * @return void
	 */
	public function testNoProviderIs503(): void {
		$copilot = $this->createMock(CopilotService::class);
		$copilot->method('health')->willReturn(['available' => false, 'reason' => 'no_provider']);
		$copilot->expects($this->once())->method('requireWritableApp')->willReturn(['slug' => 'permits']);
		$this->copilot = $copilot;
		$this->textTask->expects($this->never())->method('run');

		$this->expectExceptionObject(new CopilotException(errorCode: 'no_provider', message: 'The AI copilot is not available on this server.', httpStatus: 503));

		$this->service()->assist(
			target: 'payload-template',
			mode: 'write',
			request: 'send it',
			current: '',
			appSlug: 'permits',
			context: [],
			userId: 'alice'
		);
	}//end testNoProviderIs503()
}//end class
