<?php

/**
 * Inbound Trigger Declarations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The integriq objects that start an automation's flow from outside
 * (logic-email-and-webhook-triggers D1 to D6). "Email received" writes one
 * `event_subscription` on the mailbox whose action is the compiled flow;
 * "Webhook called" writes an `endpoint` at `buildiq/<app>/<automation>`, the
 * `rule` of type `flow` it runs, and the `consumer` whose credential integriq
 * checks. Buildiq serves no route of its own: integriq reads the mailbox, serves
 * the address, checks the caller and starts the flow (D4). The uuids written are
 * recorded in the automation's provenance, so disable and remove act on exactly
 * those objects and never on hand-made ones.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCP\App\IAppManager;
use OCP\IUserSession;

/**
 * Builds, writes and removes the integriq declarations of an inbound trigger.
 */
class InboundTriggerDeclarations {

	/**
	 * The trigger a received mail fires.
	 *
	 * @var string
	 */
	public const EMAIL = 'email-received';

	/**
	 * The trigger a webhook call fires.
	 *
	 * @var string
	 */
	public const WEBHOOK = 'webhook-called';

	/**
	 * Both inbound triggers.
	 *
	 * @var array<int, string>
	 */
	public const TRIGGERS = [self::EMAIL, self::WEBHOOK];

	/**
	 * The CloudEvents type integriq gives a message received on a mailbox source.
	 *
	 * @var string
	 */
	public const MESSAGE_RECEIVED_TYPE = 'nl.conduction.integriq.message.received';

	/**
	 * Webhook authorization choices and the integriq consumer type each one becomes.
	 * Only kinds integriq checks on an inbound call are offered; a kind it would
	 * not check is refused rather than written (an unchecked endpoint is anonymous).
	 *
	 * @var array<string, string>
	 */
	public const AUTHORIZATIONS = ['api-key' => 'apikey'];

	/**
	 * The burst limit on a webhook consumer: calls per window, window in seconds (Risks: a leaked key).
	 *
	 * @var array{requestsPerWindow: int, windowSeconds: int}
	 */
	public const RATE_LIMIT = ['requestsPerWindow' => 60, 'windowSeconds' => 60];

	/**
	 * The refusal without integriq (D6).
	 *
	 * @var string
	 */
	public const NO_INTEGRIQ_REFUSAL = 'Email and webhook triggers need the Integriq app.';

	/**
	 * The refusal for a mail trigger without a mailbox.
	 *
	 * @var string
	 */
	public const NO_MAILBOX_REFUSAL = 'Pick the mailbox this automation listens to.';

	/**
	 * The refusal for an authorization integriq does not check.
	 *
	 * @var string
	 */
	public const AUTHORIZATION_REFUSAL = 'Pick how the calling system signs in: an API key.';

	/**
	 * The refusal without the app or automation slug the address is made of.
	 *
	 * @var string
	 */
	public const NO_ADDRESS_REFUSAL = 'This automation has no app or name to make its address from.';

	/**
	 * The refusal when no maker is signed in to own the run.
	 *
	 * @var string
	 */
	public const NO_MAKER_REFUSAL = 'Apply this automation while signed in, so its runs act as you.';

	/**
	 * @param IntegriqObjectStore $store       Writes the integriq objects.
	 * @param IAppManager         $appManager  Asks whether integriq is installed.
	 * @param IUserSession        $userSession The maker applying the automation (D5).
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
	 */
	public function __construct(
		private readonly IntegriqObjectStore $store,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Whether a trigger type starts from outside (D1).
	 *
	 * @param string $triggerType The trigger type.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
	 */
	public static function isInbound(string $triggerType): bool {
		return in_array($triggerType, self::TRIGGERS, true);
	}//end isInbound()

	/**
	 * The path integriq serves a webhook automation at (D3).
	 *
	 * @param string $appSlug        The owning application's slug.
	 * @param string $automationSlug The automation's slug.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException When either slug is missing.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-a-webhook-starts-the-automations-flow-req-bqtr-003
	 */
	public static function endpointPath(string $appSlug, string $automationSlug): string {
		if ($appSlug === '' || $automationSlug === '') {
			throw new UnsupportedAutomationCombinationException(message: self::NO_ADDRESS_REFUSAL);
		}

		return 'buildiq/' . $appSlug . '/' . $automationSlug;
	}//end endpointPath()

	/**
	 * Check an inbound trigger's own settings, without I/O (compile time).
	 *
	 * @param array<string, mixed> $trigger The trigger block.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException When a setting is missing or not supported.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
	 */
	public static function check(array $trigger): void {
		$type = (string)($trigger['type'] ?? '');
		if ($type === self::EMAIL && trim((string)($trigger['source'] ?? '')) === '') {
			throw new UnsupportedAutomationCombinationException(message: self::NO_MAILBOX_REFUSAL);
		}

		if ($type === self::WEBHOOK && isset(self::AUTHORIZATIONS[(string)($trigger['authorization'] ?? 'api-key')]) === false) {
			throw new UnsupportedAutomationCombinationException(message: self::AUTHORIZATION_REFUSAL);
		}
	}//end check()

	/**
	 * Write the declarations of an inbound automation and return its provenance entry.
	 *
	 * Replaces the objects the last apply wrote (same uuids), and removes the
	 * prior ones when the automation is no longer inbound.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string|null          $flowUuid   The compiled flow, or null without one.
	 * @param array<string, mixed> $prior      The `inbound` provenance of the last apply.
	 *
	 * @return array<string, string> The new `inbound` provenance: schema slug => uuid; [] when not inbound.
	 *
	 * @throws UnsupportedAutomationCombinationException When integriq is absent or refuses a write (fail closed).
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
	 */
	public function apply(array $automation, ?string $flowUuid, array $prior): array {
		$trigger = (array)($automation['trigger'] ?? []);
		$type = (string)($trigger['type'] ?? '');
		if (self::isInbound(triggerType: $type) === false || $flowUuid === null || $flowUuid === '') {
			$this->remove(prior: $prior);
			return [];
		}

		$this->assertReady(trigger: $trigger);

		if ($type === self::EMAIL) {
			$this->removeExcept(prior: $prior, keep: ['event_subscription']);
			$uuid = $this->store->save(
				schema: 'event_subscription',
				object: $this->subscription(automation: $automation, flowUuid: $flowUuid),
				uuid: self::priorUuid(prior: $prior, schema: 'event_subscription')
			);
			return ['event_subscription' => $uuid];
		}

		$this->removeExcept(prior: $prior, keep: ['endpoint', 'rule', 'consumer']);
		$reference = 'aut-' . (string)($automation['slug'] ?? '');
		$path = self::endpointPath(appSlug: (string)($automation['applicationSlug'] ?? ''), automationSlug: (string)($automation['slug'] ?? ''));

		$written = [];
		$written['consumer'] = $this->store->save(
			schema: 'consumer',
			object: $this->consumer(automation: $automation, reference: $reference),
			uuid: self::priorUuid(prior: $prior, schema: 'consumer')
		);
		$written['rule'] = $this->store->save(
			schema: 'rule',
			object: self::rule(reference: $reference, flowUuid: $flowUuid),
			uuid: self::priorUuid(prior: $prior, schema: 'rule')
		);
		$written['endpoint'] = $this->store->save(
			schema: 'endpoint',
			object: self::endpoint(automation: $automation, reference: $reference, path: $path),
			uuid: self::priorUuid(prior: $prior, schema: 'endpoint')
		);

		return $written;
	}//end apply()

	/**
	 * Remove the declarations an earlier apply wrote.
	 *
	 * @param array<string, mixed> $prior The `inbound` provenance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
	 */
	public function remove(array $prior): void {
		$this->removeExcept(prior: $prior, keep: []);
	}//end remove()

	/**
	 * Delete every prior declaration whose schema is not kept, endpoint first so
	 * the address stops answering before its rule and consumer go.
	 *
	 * @param array<string, mixed> $prior The `inbound` provenance.
	 * @param array<int, string>   $keep  Schemas the coming write replaces.
	 *
	 * @return void
	 */
	private function removeExcept(array $prior, array $keep): void {
		foreach (['endpoint', 'rule', 'consumer', 'event_subscription'] as $schema) {
			$uuid = self::priorUuid(prior: $prior, schema: $schema);
			if ($uuid !== null && in_array($schema, $keep, true) === false) {
				$this->store->delete(schema: $schema, uuid: $uuid);
			}
		}
	}//end removeExcept()

	/**
	 * Refuse, before anything is written, when the declarations cannot be made:
	 * integriq or the object API missing (D6), a setting missing, or no maker (D5).
	 *
	 * @param array<string, mixed> $trigger The trigger block.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException When the trigger cannot be applied.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
	 */
	public function assertReady(array $trigger): void {
		if ($this->appManager->isEnabledForUser('integriq') === false) {
			throw new UnsupportedAutomationCombinationException(message: self::NO_INTEGRIQ_REFUSAL);
		}

		if ($this->store->available() === false) {
			throw new UnsupportedAutomationCombinationException(message: IntegriqObjectStore::UNAVAILABLE_REFUSAL);
		}

		self::check(trigger: $trigger);
		$this->makerId();
	}//end assertReady()

	/**
	 * The mail subscription (D2): the mailbox, the received type, the maker's
	 * filters, the flow as action, the maker as owner (D5), paused while disabled.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string               $flowUuid   The compiled flow.
	 *
	 * @return array<string, mixed>
	 */
	private function subscription(array $automation, string $flowUuid): array {
		$trigger = (array)($automation['trigger'] ?? []);
		$subscription = [
			'reference' => 'aut-' . (string)($automation['slug'] ?? ''),
			'source' => trim((string)($trigger['source'] ?? '')),
			'types' => [self::MESSAGE_RECEIVED_TYPE],
			'style' => 'push',
			'status' => 'active',
			'action' => ['kind' => 'flow', 'flowId' => $flowUuid],
			'userId' => $this->makerId(),
		];
		if ((bool)($automation['enabled'] ?? true) === false) {
			$subscription['status'] = 'paused';
		}

		$filters = self::mailFilters(trigger: $trigger);
		if ($filters !== []) {
			$subscription['filters'] = $filters;
		}

		return $subscription;
	}//end subscription()

	/**
	 * The sender and subject filters, as integriq's `jsonlogic` filter dialect, keyed so they store as an object.
	 *
	 * @param array<string, mixed> $trigger The trigger block.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function mailFilters(array $trigger): array {
		$filters = [];
		$from = trim((string)($trigger['from'] ?? ''));
		if ($from !== '') {
			$filters['from'] = ['jsonlogic' => ['==' => [['var' => 'data.from'], $from]]];
		}

		$subject = trim((string)($trigger['subjectContains'] ?? ''));
		if ($subject !== '') {
			$filters['subject'] = ['jsonlogic' => ['in' => [$subject, ['var' => 'data.subject']]]];
		}

		return $filters;
	}//end mailFilters()

	/**
	 * The webhook consumer (D3): the chosen authorization, the burst limit, and
	 * the maker as the account a run acts as (D5). No secret: integriq issues and shows it.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string               $reference  The `aut-<slug>` reference.
	 *
	 * @return array<string, mixed>
	 */
	private function consumer(array $automation, string $reference): array {
		$trigger = (array)($automation['trigger'] ?? []);
		return [
			'name' => 'Buildiq: ' . (string)($automation['name'] ?? $automation['slug'] ?? ''),
			'description' => 'Calls the webhook of automation ' . $reference . '.',
			'authorizationType' => self::AUTHORIZATIONS[(string)($trigger['authorization'] ?? 'api-key')],
			'userId' => $this->makerId(),
			'rateLimit' => self::RATE_LIMIT,
		];
	}//end consumer()

	/**
	 * The endpoint rule that starts the flow (D3).
	 *
	 * @param string $reference The `aut-<slug>` reference.
	 * @param string $flowUuid  The compiled flow.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(string $reference, string $flowUuid): array {
		return [
			'name' => $reference,
			'slug' => $reference,
			'reference' => $reference,
			'type' => 'flow',
			'action' => 'flow',
			'timing' => 'pre-request',
			'configuration' => ['flowId' => $flowUuid],
		];
	}//end rule()

	/**
	 * The endpoint (D3): POST at the automation's path, running its rule.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string               $reference  The `aut-<slug>` reference.
	 * @param string               $path       The path.
	 *
	 * @return array<string, mixed>
	 */
	private static function endpoint(array $automation, string $reference, string $path): array {
		return [
			'name' => 'Buildiq: ' . (string)($automation['name'] ?? $automation['slug'] ?? ''),
			'slug' => $reference,
			'reference' => $reference,
			'endpoint' => $path,
			'method' => 'POST',
			'rules' => [$reference],
		];
	}//end endpoint()

	/**
	 * The user id of the maker applying the automation (D5).
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException Without a signed-in maker: a run
	 *                                                  must never act as nobody.
	 */
	private function makerId(): string {
		$user = $this->userSession->getUser();
		if ($user === null || $user->getUID() === '') {
			throw new UnsupportedAutomationCombinationException(message: self::NO_MAKER_REFUSAL);
		}

		return $user->getUID();
	}//end makerId()

	/**
	 * The uuid a prior apply recorded for a schema.
	 *
	 * @param array<string, mixed> $prior  The `inbound` provenance.
	 * @param string               $schema The schema slug.
	 *
	 * @return string|null
	 */
	private static function priorUuid(array $prior, string $schema): ?string {
		$uuid = ($prior[$schema] ?? null);
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		return null;
	}//end priorUuid()
}//end class
