<?php

/**
 * Unit tests for CopilotPlanValidator (spec ai-copilot REQ-OBAIC-002).
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
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\Copilot\CopilotPlanValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CopilotPlanValidator.
 */
class CopilotPlanValidatorTest extends TestCase {

	private CopilotPlanValidator $validator;

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $descriptors;

	/**
	 * Set up the validator and a minimal tool catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new CopilotPlanValidator();
		$this->descriptors = [
			[
				'id' => 'buildiq.createApp',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
						'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
						'preset' => ['type' => 'string', 'enum' => ['single', 'dev-prod', 'dev-staging-prod']],
					],
					'required' => ['slug', 'name'],
				],
			],
			[
				'id' => 'buildiq.upsertPage',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'appSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$'],
						'pageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
						'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
						'type' => ['type' => 'string', 'enum' => ['dashboard', 'index', 'detail', 'form']],
						'route' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
					],
					'required' => ['appSlug', 'pageId', 'title', 'type', 'route'],
				],
			],
		];
	}//end setUp()

	/**
	 * A step whose tool is not on the catalogue is rejected.
	 *
	 * @return void
	 */
	public function testRejectsToolOutsideAllowList(): void {
		$plan = ['summary' => 'x', 'steps' => [['tool' => 'buildiq.deleteApp', 'arguments' => []]]];
		$violations = $this->validator->validate(plan: $plan, toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertSame(0, $violations[0]['stepIndex']);
		self::assertStringContainsString('unknown tool', $violations[0]['message']);
	}//end testRejectsToolOutsideAllowList()

	/**
	 * Missing required arguments produce a violation naming the missing key.
	 *
	 * @return void
	 */
	public function testRejectsMissingRequiredArgument(): void {
		$plan = [
			'summary' => 'x',
			'steps' => [
				['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'my-app', 'pageId' => 'home', 'title' => 'Home', 'type' => 'index']],
			],
		];
		$violations = $this->validator->validate(plan: $plan, toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertStringContainsString("missing required argument 'route'", $violations[0]['message']);
	}//end testRejectsMissingRequiredArgument()

	/**
	 * An enum violation is reported.
	 *
	 * @return void
	 */
	public function testRejectsEnumViolation(): void {
		$plan = [
			'summary' => 'x',
			'steps' => [
				[
					'tool' => 'buildiq.upsertPage',
					'arguments' => [
						'appSlug' => 'my-app',
						'pageId' => 'home',
						'title' => 'Home',
						'type' => 'not-a-type',
						'route' => '/',
					],
				],
			],
		];
		$violations = $this->validator->validate(plan: $plan, toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertStringContainsString('must be one of', $violations[0]['message']);
	}//end testRejectsEnumViolation()

	/**
	 * A pattern violation on a slug-shaped argument is reported.
	 *
	 * @return void
	 */
	public function testRejectsPatternViolation(): void {
		$plan = [
			'summary' => 'x',
			'steps' => [
				['tool' => 'buildiq.createApp', 'arguments' => ['slug' => 'Not Valid!', 'name' => 'My App']],
			],
		];
		$violations = $this->validator->validate(plan: $plan, toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertStringContainsString('does not match the required pattern', $violations[0]['message']);
	}//end testRejectsPatternViolation()

	/**
	 * A length violation (below minLength) is reported.
	 *
	 * @return void
	 */
	public function testRejectsMinLengthViolation(): void {
		$plan = [
			'summary' => 'x',
			'steps' => [
				['tool' => 'buildiq.createApp', 'arguments' => ['slug' => 'my-app', 'name' => 'A']],
			],
		];
		$violations = $this->validator->validate(plan: $plan, toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertStringContainsString('at least', $violations[0]['message']);
	}//end testRejectsMinLengthViolation()

	/**
	 * A fully valid multi-step plan passes with zero violations.
	 *
	 * @return void
	 */
	public function testValidMultiStepPlanPasses(): void {
		$plan = [
			'summary' => 'A tool library',
			'steps' => [
				['tool' => 'buildiq.createApp', 'arguments' => ['slug' => 'tool-library', 'name' => 'Tool Library', 'preset' => 'dev-prod']],
				['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'tool-library', 'pageId' => 'home', 'title' => 'Home', 'type' => 'index', 'route' => '/']],
			],
		];
		$violations = $this->validator->validate(plan: $plan, toolDescriptors: $this->descriptors);
		self::assertSame([], $violations);
	}//end testValidMultiStepPlanPasses()

	/**
	 * A plan missing the `steps` array entirely is rejected.
	 *
	 * @return void
	 */
	public function testRejectsPlanWithoutSteps(): void {
		$violations = $this->validator->validate(plan: ['summary' => 'x'], toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertSame(-1, $violations[0]['stepIndex']);
	}//end testRejectsPlanWithoutSteps()

	/**
	 * A plan with an empty `steps` array is rejected.
	 *
	 * @return void
	 */
	public function testRejectsEmptySteps(): void {
		$violations = $this->validator->validate(plan: ['summary' => 'x', 'steps' => []], toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertSame(-1, $violations[0]['stepIndex']);
	}//end testRejectsEmptySteps()

	/**
	 * A non-object step is rejected without throwing.
	 *
	 * @return void
	 */
	public function testRejectsNonObjectStep(): void {
		$violations = $this->validator->validate(plan: ['summary' => 'x', 'steps' => ['not-an-object']], toolDescriptors: $this->descriptors);
		self::assertNotEmpty($violations);
		self::assertSame(0, $violations[0]['stepIndex']);
	}//end testRejectsNonObjectStep()

	/**
	 * Every constraint kind, every step shape and every message, in order.
	 *
	 * @return void
	 */
	public function testEveryViolationInOrder(): void {
		$descriptors = [
			['id' => ''],
			[
				'id' => 't.all',
				'inputSchema' => [
					'properties' => [
						's' => ['type' => 'string', 'pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 3],
						'i' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
						'n' => ['type' => 'number', 'minimum' => 0.5],
						'b' => ['type' => 'boolean'],
						'l' => ['type' => 'array'],
						'o' => ['type' => 'object'],
						'e' => ['enum' => ['x', 'y']],
						'u' => ['type' => 'custom'],
						'z' => 'not-a-schema',
					],
					'required' => ['s', 'missing1', 7],
				],
			],
			['id' => 't.none'],
		];
		$steps = [
			'nope',
			['tool' => 'unknown'],
			['tool' => 't.all', 'arguments' => 'x'],
			['tool' => 't.all', 'arguments' => ['s' => 'b', 'i' => '3', 'n' => 0.1, 'b' => 1, 'l' => ['k' => 1], 'o' => 'str', 'e' => 'z', 'u' => 1, 'z' => 5]],
			['tool' => 't.all', 'arguments' => ['s' => 'aaaa', 'i' => 9, 'n' => 7]],
			['tool' => 't.all', 'arguments' => ['s' => 'a', 'i' => 0, 'l' => [1, 2], 'o' => [], 'e' => 'x', 'b' => true]],
			['tool' => 't.none'],
		];

		$messages = array_map(
			static fn (array $violation): string => $violation['stepIndex'] . ' ' . $violation['message'],
			$this->validator->validate(['steps' => $steps], $descriptors)
		);

		self::assertSame(
			[
				'0 Step is not an object.',
				"1 Step 1: unknown tool 'unknown'. Allowed tools: t.all, t.none.",
				"2 Step 2: 'arguments' must be an object.",
				"3 Step 3: missing required argument 'missing1'.",
				"3 Step 3: missing required argument '7'.",
				"3 Step 3: argument 's' does not match the required pattern.",
				"3 Step 3: argument 'i' must be of type integer.",
				"3 Step 3: argument 'n' must be >= 0.5.",
				"3 Step 3: argument 'b' must be of type boolean.",
				"3 Step 3: argument 'l' must be of type array.",
				"3 Step 3: argument 'o' must be of type object.",
				"3 Step 3: argument 'e' must be one of: x, y.",
				"4 Step 4: missing required argument 'missing1'.",
				"4 Step 4: missing required argument '7'.",
				"4 Step 4: argument 's' must be at most 3 characters.",
				"4 Step 4: argument 'i' must be <= 5.",
				"5 Step 5: missing required argument 'missing1'.",
				"5 Step 5: missing required argument '7'.",
				"5 Step 5: argument 's' must be at least 2 characters.",
				"5 Step 5: argument 'i' must be >= 1.",
			],
			$messages
		);
	}//end testEveryViolationInOrder()

	/**
	 * A two-step plan: a page at index 0 and a menu item at index 1, plus these stories.
	 *
	 * @param mixed $stories The stories.
	 *
	 * @return array<string, mixed>
	 */
	private function planWithStories(mixed $stories): array {
		return [
			'summary' => 'Permits',
			'stories' => $stories,
			'steps' => [
				['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'permits', 'pageId' => 'mine', 'title' => 'Mijn aanvragen', 'type' => 'index', 'route' => '/mine']],
				['tool' => 'buildiq.upsertMenuItem', 'arguments' => []],
			],
		];
	}//end planWithStories()

	/**
	 * The story messages only, without the step messages of the shared plan.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return string[]
	 */
	private function storyMessages(array $plan): array {
		$descriptors = array_merge($this->descriptors, [['id' => 'buildiq.upsertMenuItem']]);
		return array_values(
			array_filter(
				array_column($this->validator->validate(plan: $plan, toolDescriptors: $descriptors), 'message'),
				static fn (string $message): bool => str_starts_with($message, 'Story') || str_starts_with($message, 'Plan "stories"')
			)
		);
	}//end storyMessages()

	/**
	 * A story with a role, a goal and a page it is served by passes; so does a plan without stories.
	 *
	 * @return void
	 */
	public function testAValidStoryPasses(): void {
		$story = ['id' => 's1', 'role' => 'applicant', 'goal' => 'track my permit', 'benefit' => 'I know where it stands', 'servedBy' => [0]];

		$this->assertSame([], $this->storyMessages($this->planWithStories([$story])));
		$plan = $this->planWithStories([]);
		unset($plan['stories']);
		$this->assertSame([], $this->storyMessages($plan));
	}//end testAValidStoryPasses()

	/**
	 * A story without a goal, or with a blank role, is refused.
	 *
	 * @return void
	 */
	public function testAStoryWithoutAGoalIsRefused(): void {
		$this->assertSame(
			['Story 0: has no goal.', 'Story 1: has no role.'],
			$this->storyMessages($this->planWithStories([['role' => 'applicant', 'servedBy' => [0]], ['role' => '  ', 'goal' => 'apply']]))
		);
	}//end testAStoryWithoutAGoalIsRefused()

	/**
	 * servedBy pointing at a menu step, a missing step or a non-integer is refused.
	 *
	 * @return void
	 */
	public function testServedByMustPointAtASchemaOrPageStep(): void {
		$this->assertSame(
			[
				'Story 0: servedBy 1 does not point at a schema or page step.',
				'Story 0: servedBy 5 does not point at a schema or page step.',
				'Story 0: servedBy "0" does not point at a schema or page step.',
			],
			$this->storyMessages($this->planWithStories([['role' => 'applicant', 'goal' => 'apply', 'servedBy' => [1, 5, '0']]]))
		);
	}//end testServedByMustPointAtASchemaOrPageStep()

	/**
	 * Stories that are not a list, a story that is not an object, and bad field types are refused.
	 *
	 * @return void
	 */
	public function testMalformedStoriesAreRefused(): void {
		$this->assertSame(['Plan "stories" must be a list.'], $this->storyMessages($this->planWithStories('none')));
		$this->assertSame(['Plan "stories" must be a list.'], $this->storyMessages($this->planWithStories(['a' => []])));
		$this->assertSame(
			['Story 0: is not an object.', 'Story 1: benefit must be a string.', 'Story 1: servedBy must be a list of step indexes.'],
			$this->storyMessages($this->planWithStories(['x', ['role' => 'r', 'goal' => 'g', 'benefit' => 3, 'servedBy' => 0]]))
		);
	}//end testMalformedStoriesAreRefused()
}//end class
