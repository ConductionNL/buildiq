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
}//end class
