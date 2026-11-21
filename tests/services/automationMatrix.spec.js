/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the automation-designer v1 compilation matrix.
 *
 * Spec: automation-designer (REQ-AUTD-003 / design.md Decision 2), extended
 * by automation-approval-steps (REQ-AUTD-003 / design.md Decision 1) and
 * automation-document-action (REQ-AUTD-003 / design.md Decision 2).
 */
import { describe, expect, it } from 'vitest'
import {
	ACTION_TYPES,
	blockedActionReason,
	blockedConditionReason,
	CONDITION_ALLOWED_TRIGGERS,
	isActionAllowed,
	isConditionAllowed,
	MATRIX,
	TRIGGER_TYPES,
} from '../../src/services/automationMatrix.js'

describe('automationMatrix — MATRIX cell-for-cell', () => {
	it('object-created/updated/deleted allow notification, the flow actions, approval and generateDocument', () => {
		const row = [
			'send-notification',
			'object-op',
			'webhook',
			'rule-decision',
			'approval',
			'generateDocument',
		]
		// Ai-llm-steps-and-computed-fields: an AI step runs on created and on
		// updated (watching its source fields), never on a deletion.
		// Logic-shared-flows-and-automations D4: "Run another automation" is a
		// flow step, offered wherever a flow runs.
		expect(MATRIX['object-created']).toEqual([...row, 'ai-step', 'run-automation'])
		expect(MATRIX['object-updated']).toEqual([...row, 'ai-step', 'run-automation'])
		expect(MATRIX['object-deleted']).toEqual([...row, 'run-automation'])
	})

	it('lifecycle-transition allows send-notification, object-op, webhook, approval, generateDocument', () => {
		expect(MATRIX['lifecycle-transition']).toEqual([
			'send-notification',
			'object-op',
			'webhook',
			'approval',
			'generateDocument',
		])
	})

	it('schedule allows only run-synchronization', () => {
		expect(MATRIX.schedule).toEqual(['run-synchronization'])
	})

	it('manual allows send-notification, object-op, webhook, rule-decision, ai-step — NOT run-synchronization (documented deviation)', () => {
		expect(MATRIX.manual).toEqual(['send-notification', 'object-op', 'webhook', 'rule-decision', 'ai-step', 'run-automation', 'script-step'])
		expect(MATRIX.manual).not.toContain('run-synchronization')
	})

	it('every trigger type in TRIGGER_TYPES has a matrix row', () => {
		TRIGGER_TYPES.forEach((trigger) => {
			expect(MATRIX[trigger]).toBeDefined()
		})
	})

	it('every allowed action in MATRIX is a known ACTION_TYPES member', () => {
		Object.values(MATRIX).forEach((allowed) => {
			allowed.forEach((action) => {
				expect(ACTION_TYPES).toContain(action)
			})
		})
	})
})

describe('isActionAllowed', () => {
	it('allows a matrix-listed combination', () => {
		expect(isActionAllowed('object-created', 'send-notification')).toBe(true)
		expect(isActionAllowed('lifecycle-transition', 'webhook')).toBe(true)
		expect(isActionAllowed('schedule', 'run-synchronization')).toBe(true)
		expect(isActionAllowed('manual', 'object-op')).toBe(true)
	})

	it('blocks an unlisted combination', () => {
		expect(isActionAllowed('object-created', 'run-synchronization')).toBe(false)
		expect(isActionAllowed('lifecycle-transition', 'rule-decision')).toBe(false)
		expect(isActionAllowed('schedule', 'send-notification')).toBe(false)
		expect(isActionAllowed('manual', 'run-synchronization')).toBe(false)
	})

	it('approval is allowed on event/lifecycle-transition triggers, blocked on schedule/manual', () => {
		expect(isActionAllowed('object-created', 'approval')).toBe(true)
		expect(isActionAllowed('object-updated', 'approval')).toBe(true)
		expect(isActionAllowed('object-deleted', 'approval')).toBe(true)
		expect(isActionAllowed('lifecycle-transition', 'approval')).toBe(true)
		expect(isActionAllowed('schedule', 'approval')).toBe(false)
		expect(isActionAllowed('manual', 'approval')).toBe(false)
	})

	it('generateDocument is allowed on event/lifecycle-transition triggers, blocked on schedule/manual', () => {
		expect(isActionAllowed('object-created', 'generateDocument')).toBe(true)
		expect(isActionAllowed('object-updated', 'generateDocument')).toBe(true)
		expect(isActionAllowed('object-deleted', 'generateDocument')).toBe(true)
		expect(isActionAllowed('lifecycle-transition', 'generateDocument')).toBe(
			true,
		)
		expect(isActionAllowed('schedule', 'generateDocument')).toBe(false)
		expect(isActionAllowed('manual', 'generateDocument')).toBe(false)
	})

	it('ai-step is allowed on created, updated and manual, blocked elsewhere', () => {
		expect(isActionAllowed('object-created', 'ai-step')).toBe(true)
		expect(isActionAllowed('object-updated', 'ai-step')).toBe(true)
		expect(isActionAllowed('manual', 'ai-step')).toBe(true)
		expect(isActionAllowed('object-deleted', 'ai-step')).toBe(false)
		expect(isActionAllowed('lifecycle-transition', 'ai-step')).toBe(false)
		expect(isActionAllowed('schedule', 'ai-step')).toBe(false)
	})

	it('blocks an unknown trigger type', () => {
		expect(isActionAllowed('not-a-real-trigger', 'send-notification')).toBe(
			false,
		)
	})
})

describe('isConditionAllowed — condition allowed only on manual', () => {
	it('allows a condition on manual', () => {
		expect(isConditionAllowed('manual')).toBe(true)
		expect(CONDITION_ALLOWED_TRIGGERS).toEqual(['manual'])
	})

	it('blocks a condition on every other trigger type', () => {
		TRIGGER_TYPES.filter((t) => t !== 'manual').forEach((trigger) => {
			expect(isConditionAllowed(trigger)).toBe(false)
		})
	})
})

describe('blockedActionReason / blockedConditionReason', () => {
	it('returns empty string for an allowed combination', () => {
		expect(blockedActionReason('object-created', 'send-notification')).toBe('')
		expect(blockedActionReason('object-created', 'approval')).toBe('')
		expect(blockedConditionReason('manual')).toBe('')
	})

	it('returns a non-empty reason for a blocked combination', () => {
		expect(blockedActionReason('object-created', 'run-synchronization')).not.toBe('')
		expect(blockedActionReason('object-created', 'webhook')).toBe('')
		expect(blockedActionReason('manual', 'approval')).not.toBe('')
		expect(blockedActionReason('schedule', 'generateDocument')).not.toBe('')
		expect(blockedConditionReason('schedule')).not.toBe('')
	})

	it('logic-script-step D1: a script step is offered on the manual, mail and webhook triggers only, as the compiler matrix', () => {
		expect(ACTION_TYPES).toContain('script-step')
		TRIGGER_TYPES.forEach((trigger) => {
			const expected = ['manual', 'email-received', 'webhook-called'].includes(trigger)
			expect(isActionAllowed(trigger, 'script-step')).toBe(expected)
		})
	})
})
