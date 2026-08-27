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
}//end class
