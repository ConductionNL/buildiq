<?php

/**
 * CopilotAssistService answer check unit test.
 *
 * What the checker refuses in a model answer before it reaches the dialog:
 * text that is not a JSON object, a missing explanation or value, a condition
 * that is not a string or does not parse, a decision row without conditions or
 * a decision, a field mapping that is a list. Also what it accepts: a fenced
 * JSON answer, trimmed decision cells and an empty payload template.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The answer checker, called directly on model texts.
 *
 * @covers \OCA\Buildiq\Service\Copilot\CopilotAssistService
 * @uses   \OCA\Buildiq\Exception\CopilotException
 * @uses   \OCA\Buildiq\Service\FeelCellCondition
 * @uses   \OCA\Buildiq\Service\FeelLexer
 * @uses   \OCA\Buildiq\Service\FeelParser
 * @uses   \OCA\Buildiq\Service\RegistrationFormTargetSchemaReader
 */
final class CopilotAssistAnswerCheckTest extends TestCase {

	/**
	 * The service; checking an answer touches only the FEEL grammars.
	 *
	 * @param CopilotService|null  $copilot  Availability and the write-role check.
	 * @param CopilotTextTask|null $textTask The language model seam.
	 *
	 * @return CopilotAssistService
	 */
	private function service(?CopilotService $copilot = null, ?CopilotTextTask $textTask = null): CopilotAssistService {
		return new CopilotAssistService(
			copilotService: ($copilot ?? $this->createMock(CopilotService::class)),
			textTask: ($textTask ?? $this->createMock(CopilotTextTask::class)),
			feelParser: new FeelParser(),
			cellCondition: new FeelCellCondition(),
			schemaReader: new RegistrationFormTargetSchemaReader(
				registerMapper: $this->createMock(RegisterMapper::class),
				schemaMapper: $this->createMock(SchemaMapper::class),
				logger: $this->createMock(LoggerInterface::class),
			),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end service()

	/**
	 * Check one answer in write mode.
	 *
	 * @param string                  $target The editor field.
	 * @param string                  $raw    The model's text.
	 * @param array<string, string[]> $names  The names the prompt carried.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string}
	 */
	private function write(string $target, string $raw, array $names = []): array {
		return $this->service()->checkAnswer(target: $target, mode: 'write', raw: $raw, names: $names);
	}//end write()

	/**
	 * Prose, a JSON list and broken JSON are not JSON objects.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNotAJsonObjectIsRefused(): void {
		foreach (['Sure, here is the condition.', '["a", "b"]', '{"explanation": '] as $raw) {
			$this->assertSame([null, 'The answer was not a JSON object.'], $this->write(target: 'feel-condition', raw: $raw), $raw);
		}
	}//end testAnAnswerThatIsNotAJsonObjectIsRefused()

	/**
	 * An empty or non-string explanation is refused, also in explain mode.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAnExplanationIsRefused(): void {
		$this->assertSame([null, 'The answer has no explanation.'], $this->write(target: 'feel-condition', raw: '{"value": "age > 18", "explanation": "  "}'));
		$this->assertSame(
			[null, 'The answer has no explanation.'],
			$this->service()->checkAnswer(target: 'feel-condition', mode: 'explain', raw: '{"explanation": 42}', names: [])
		);
	}//end testAnAnswerWithoutAnExplanationIsRefused()

	/**
	 * A write answer without a value is refused.
	 *
	 * @return void
	 */
	public function testAWriteAnswerWithoutAValueIsRefused(): void {
		$this->assertSame([null, 'The answer has no value.'], $this->write(target: 'feel-condition', raw: '{"explanation": "Adults only."}'));
	}//end testAWriteAnswerWithoutAValueIsRefused()

	/**
	 * A fenced JSON answer is read; the condition and explanation come back trimmed.
	 *
	 * @return void
	 */
	public function testAFencedAnswerIsRead(): void {
		$raw = "```json\n{\"value\": \" age >= 18 \", \"explanation\": \" Adults only. \"}\n```";

		$this->assertSame(
			[['mode' => 'write', 'value' => 'age >= 18', 'explanation' => 'Adults only.'], ''],
			$this->write(target: 'feel-condition', raw: $raw)
		);
	}//end testAFencedAnswerIsRead()

	/**
	 * A condition must be a non-empty string that parses.
	 *
	 * @return void
	 */
	public function testAConditionThatIsNotAStringOrDoesNotParseIsRefused(): void {
		$this->assertSame(
			[null, 'The condition must be a non-empty string.'],
			$this->write(target: 'feel-condition', raw: '{"value": 18, "explanation": "x"}')
		);

		[$answer, $error] = $this->write(target: 'feel-condition', raw: '{"value": "age >", "explanation": "x"}');
		$this->assertNull($answer);
		$this->assertStringStartsWith('The condition does not parse: ', $error);
	}//end testAConditionThatIsNotAStringOrDoesNotParseIsRefused()

	/**
	 * A decision row needs a conditions object and a decision.
	 *
	 * @return void
	 */
	public function testADecisionRowWithoutConditionsOrADecisionIsRefused(): void {
		$names = ['columns' => ['age']];

		$this->assertSame(
			[null, 'The rule must be an object with "conditions" and "decision".'],
			$this->write(target: 'decision-rule', raw: '{"value": {"decision": "yes"}, "explanation": "x"}', names: $names)
		);
		$this->assertSame(
			[null, 'The rule has no decision.'],
			$this->write(target: 'decision-rule', raw: '{"value": {"conditions": {"age": ">= 18"}, "decision": " "}, "explanation": "x"}', names: $names)
		);
	}//end testADecisionRowWithoutConditionsOrADecisionIsRefused()

	/**
	 * An accepted decision row has its cells and decision trimmed.
	 *
	 * @return void
	 */
	public function testAnAcceptedDecisionRowIsTrimmed(): void {
		$this->assertSame(
			[['mode' => 'write', 'value' => ['conditions' => ['age' => '>= 18'], 'decision' => 'adult'], 'explanation' => 'x'], ''],
			$this->write(
				target: 'decision-rule',
				raw: '{"value": {"conditions": {"age": " >= 18 "}, "decision": " adult "}, "explanation": "x"}',
				names: ['columns' => ['age']]
			)
		);
	}//end testAnAcceptedDecisionRowIsTrimmed()

	/**
	 * A field mapping given as a list is refused.
	 *
	 * @return void
	 */
	public function testAFieldMappingThatIsAListIsRefused(): void {
		$this->assertSame(
			[null, 'The field mapping must be a JSON object.'],
			$this->write(target: 'field-mapping', raw: '{"value": "[\"name\"]", "explanation": "x"}', names: ['properties' => ['name']])
		);
	}//end testAFieldMappingThatIsAListIsRefused()

	/**
	 * An empty payload template comes back as an empty JSON object.
	 *
	 * @return void
	 */
	public function testAnEmptyPayloadTemplateIsAnEmptyObject(): void {
		$this->assertSame(
			[['mode' => 'write', 'value' => '{}', 'explanation' => 'Nothing to send.'], ''],
			$this->write(target: 'payload-template', raw: '{"value": {}, "explanation": "Nothing to send."}')
		);
	}//end testAnEmptyPayloadTemplateIsAnEmptyObject()

	/**
	 * The copilot for app `permits`, available, writable for alice.
	 *
	 * @return CopilotService&MockObject
	 */
	private function writableCopilot(): CopilotService&MockObject {
		$copilot = $this->createMock(CopilotService::class);
		$copilot->method('health')->willReturn(['available' => true]);
		$copilot->expects($this->once())->method('requireWritableApp')
			->with('permits', 'alice')
			->willReturn(['uuid' => 'app-1', 'slug' => 'permits', 'dataRegisters' => []]);

		return $copilot;
	}//end writableCopilot()

	/**
	 * A current value over the limit is refused before the app is resolved.
	 *
	 * @return void
	 */
	public function testACurrentValueOverTheLimitIsRefusedFirst(): void {
		$copilot = $this->createMock(CopilotService::class);
		$copilot->expects($this->never())->method('requireWritableApp');

		try {
			$this->service(copilot: $copilot)->assist(
				target: 'feel-condition',
				mode: 'explain',
				request: '',
				current: str_repeat('a', 2001),
				appSlug: 'permits',
				context: [],
				userId: 'alice'
			);
			$this->fail('Expected a refusal');
		} catch (CopilotException $e) {
			$this->assertSame(['invalid_arguments', 'The current value is longer than 2000 characters.'], [$e->getErrorCode(), $e->getMessage()]);
		}
	}//end testACurrentValueOverTheLimitIsRefusedFirst()

	/**
	 * A field mapping without a target schema is refused before the model runs.
	 *
	 * @return void
	 */
	public function testAFieldMappingWithoutATargetSchemaIsRefusedBeforeTheModel(): void {
		$textTask = $this->createMock(CopilotTextTask::class);
		$textTask->expects($this->never())->method('run');

		try {
			$this->service(copilot: $this->writableCopilot(), textTask: $textTask)->assist(
				target: 'field-mapping',
				mode: 'write',
				request: 'Map the applicant',
				current: '',
				appSlug: 'permits',
				context: ['targetSchema' => '  '],
				userId: 'alice'
			);
			$this->fail('Expected a refusal');
		} catch (CopilotException $e) {
			$this->assertSame(
				['invalid_arguments', 'Pick a target schema before asking for a field mapping.', 422],
				[$e->getErrorCode(), $e->getMessage(), $e->getHttpStatus()]
			);
		}
	}//end testAFieldMappingWithoutATargetSchemaIsRefusedBeforeTheModel()

	/**
	 * Context columns that are not a list count as none, so no rule is asked for.
	 *
	 * @return void
	 */
	public function testColumnsThatAreNotAListCountAsNone(): void {
		try {
			$this->service(copilot: $this->writableCopilot())->assist(
				target: 'decision-rule',
				mode: 'write',
				request: 'Adults get yes',
				current: '',
				appSlug: 'permits',
				context: ['columns' => 'age'],
				userId: 'alice'
			);
			$this->fail('Expected a refusal');
		} catch (CopilotException $e) {
			$this->assertSame('Add an input column before asking for a rule.', $e->getMessage());
		}
	}//end testColumnsThatAreNotAListCountAsNone()

	/**
	 * At most 200 column names are kept, non-strings skipped: a rule on the
	 * 201st column is refused, twice, as a column the table does not have.
	 *
	 * @return void
	 */
	public function testColumnNamesAreCappedAtTwoHundred(): void {
		$columns = [42, null];
		for ($i = 0; $i <= 200; $i++) {
			$columns[] = 'c' . $i;
		}

		$textTask = $this->createMock(CopilotTextTask::class);
		$textTask->method('manager')->willReturn(new stdClass());
		$textTask->expects($this->exactly(2))->method('run')
			->willReturn('{"value": {"conditions": {"c200": "> 1"}, "decision": "yes"}, "explanation": "x"}');

		try {
			$this->service(copilot: $this->writableCopilot(), textTask: $textTask)->assist(
				target: 'decision-rule',
				mode: 'write',
				request: 'Use the last column',
				current: '',
				appSlug: 'permits',
				context: ['columns' => $columns],
				userId: 'alice'
			);
			$this->fail('Expected a refusal');
		} catch (CopilotException $e) {
			$this->assertSame(
				['assist_invalid', "The rule uses the column 'c200', which the table does not have."],
				[$e->getErrorCode(), $e->getMessage()]
			);
		}
	}//end testColumnNamesAreCappedAtTwoHundred()
}//end class
