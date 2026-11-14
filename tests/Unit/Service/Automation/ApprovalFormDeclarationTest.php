<?php

/**
 * Unit tests for ApprovalFormDeclaration: the approval form refused by
 * field, refused when OpenRegister cannot carry it, and its chain shape.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\ApprovalFormDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApprovalFormDeclaration.
 *
 * @covers \OCA\Buildiq\Service\Automation\ApprovalFormDeclaration
 * @uses   \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 */
final class ApprovalFormDeclarationTest extends TestCase {

	/**
	 * An approval asking for these fields.
	 *
	 * @param array<int,mixed> $fields The form fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function actions(array $fields): array {
		return [['type' => 'send-notification'], ['type' => 'approval', 'assigneeGroup' => 'g', 'form' => ['fields' => $fields]]];
	}//end actions()

	/**
	 * A declaration over `bedrag` and `motivering` with this capability answer.
	 *
	 * @param boolean $carried Whether OpenRegister carries the form.
	 * @param integer $asked   Set to how often the capability was asked.
	 *
	 * @return ApprovalFormDeclaration
	 */
	private static function declaration(bool $carried, int &$asked): ApprovalFormDeclaration {
		return new ApprovalFormDeclaration(
			schemaSlug: 'klacht',
			properties: static fn (): array => ['bedrag' => ['type' => 'number'], 'motivering' => ['type' => 'string']],
			carried: static function () use ($carried, &$asked): bool {
				$asked++;
				return $carried;
			}
		);
	}//end declaration()

	/**
	 * REQ-BQTF-002: a valid form is refused when OpenRegister cannot carry it into the task.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-the-form-is-compiled-into-the-approval-chain-or-refused-req-bqtf-002
	 */
	public function testAValidFormIsRefusedWhenOpenRegisterCannotCarryIt(): void {
		$asked = 0;

		try {
			self::declaration(carried: false, asked: $asked)->check(actions: self::actions(fields: [['field' => 'bedrag', 'required' => true]]));
			$this->fail('Expected the form to be refused');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertSame('Approval forms need OpenRegister to carry a form into the task', $e->getMessage());
		}

		$this->assertSame(1, $asked);
	}//end testAValidFormIsRefusedWhenOpenRegisterCannotCarryIt()

	/**
	 * REQ-BQTF-002: a valid form passes when OpenRegister carries it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-the-form-is-compiled-into-the-approval-chain-or-refused-req-bqtf-002
	 */
	public function testAValidFormPassesWhenOpenRegisterCarriesIt(): void {
		$asked = 0;

		self::declaration(carried: true, asked: $asked)->check(actions: self::actions(fields: [['field' => 'bedrag', 'required' => true], ['field' => 'motivering']]));

		$this->assertSame(1, $asked);
	}//end testAValidFormPassesWhenOpenRegisterCarriesIt()

	/**
	 * A field the form cannot render is refused by name before the capability is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public function testAnUnknownFieldIsRefusedBeforeTheCapabilityIsAsked(): void {
		$asked = 0;

		try {
			self::declaration(carried: true, asked: $asked)->check(actions: self::actions(fields: [['field' => 'onbekend']]));
			$this->fail('Expected the field to be refused');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertSame('The approval form cannot ask for "onbekend": it is not a property in schema "klacht".', $e->getMessage());
		}

		$this->assertSame(0, $asked);
	}//end testAnUnknownFieldIsRefusedBeforeTheCapabilityIsAsked()

	/**
	 * Without a form neither the schema nor the capability is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public function testNoFormAsksNothing(): void {
		$asked = 0;
		$declaration = new ApprovalFormDeclaration(
			schemaSlug: 'klacht',
			properties: function (): array {
				$this->fail('The schema must not be read without a form');
			},
			carried: static function () use (&$asked): bool {
				$asked++;
				return false;
			}
		);

		$declaration->check(actions: self::actions(fields: []));

		$this->assertSame(0, $asked);
	}//end testNoFormAsksNothing()

	/**
	 * REQ-BQTF-002: the chain position's form is `{kind: fields, fields}` in the declared order; none without fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-the-form-is-compiled-into-the-approval-chain-or-refused-req-bqtf-002
	 */
	public function testTheChainFormKeepsTheDeclaredOrder(): void {
		$this->assertSame(
			[
				'kind' => 'fields',
				'fields' => [['field' => 'motivering', 'required' => false], ['field' => 'bedrag', 'required' => true]],
			],
			ApprovalFormDeclaration::chainForm(action: ['form' => ['fields' => [['field' => ' motivering '], ['field' => 'bedrag', 'required' => true]]]])
		);
		$this->assertNull(ApprovalFormDeclaration::chainForm(action: ['form' => ['fields' => []]]));
		$this->assertNull(ApprovalFormDeclaration::chainForm(action: ['type' => 'approval']));
		$this->assertNull(ApprovalFormDeclaration::chainForm(action: ['form' => ['fields' => 'bedrag']]));
	}//end testTheChainFormKeepsTheDeclaredOrder()
}//end class
