<?php

/**
 * OpenBuild RuleActionDispatcher
 *
 * Completes the ADR-031 §Exceptions rules-engine code path: the concrete
 * side-effect dispatcher `ConditionActionExecutor` has always accepted as an
 * optional `?callable $dispatcher` but that `RuleEngineService` never wired
 * (verified defect at `RuleEngineService.php:142`, spec REQ-AUTD-010). This
 * class is that dispatcher, invoked as `$dispatcher($type, $params,
 * $payload)` for every non-dry-run side-effecting action.
 *
 * Supported action types (`ConditionActionExecutor::SIDE_EFFECT_ACTIONS`):
 *   - `send-notification` — creates a Nextcloud notification via
 *     `OCP\Notification\IManager`. Params: `subject` (string, required),
 *     `recipientUid` (string, single NC user) and/or `recipientUids`
 *     (string[]). A recipient-less call is a no-op (logged), not an error —
 *     mirrors the executor's tolerant-by-design posture.
 *   - `object-op` — writes through OpenRegister's real `ObjectService`
 *     (ADR-022), never a bespoke persistence path. Params: `schema`
 *     (required), `operation` (`create`|`update`, default `create`),
 *     `object` (the field-mapped payload, required), `register` (default
 *     `openbuild`), `id` (required when `operation: update`). When no NC
 *     session user is active (background/dry-evaluation contexts),
 *     {@see JobOwnerImpersonator} is used so the write is attributed the
 *     same way OR attributes any other write.
 *   - `webhook` — POSTs the compiled target via `OCP\Http\Client\IClientService`.
 *     Params: `url` (required), `payload` (object, default `[]`).
 *   - `start-workflow` — reserved: no workflow engine exists in openbuild
 *     (design.md non-goal); logged and treated as a no-op so the action type
 *     stays declaratively valid without inventing a new imperative engine.
 *   - `call-rule-set` — recursively invokes `RuleEngineService::evaluate()`
 *     for the referenced RuleSet. Resolved lazily through the PSR container
 *     (mirrors {@see JobOwnerImpersonator}) to avoid a constructor cycle
 *     between this class and RuleEngineService.
 *
 * Any other action type logs a warning and is treated as a no-op — the
 * caller (`ConditionActionExecutor`) already separates "unknown action type"
 * errors from dispatched side effects; this class never throws for an
 * unrecognised type so one bad action cannot abort a rule's remaining
 * actions when `continueOnError` is false but the type itself is merely
 * unsupported rather than a hard failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#2.3
 * @spec openspec/changes/automation-designer/specs/automation-designer/spec.md#req-autd-010
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wired dispatcher for ConditionActionExecutor side-effecting actions.
 */
class RuleActionDispatcher
{
    /**
     * App identifier stamped on every created NC notification.
     */
    private const NOTIFICATION_APP = 'openbuild';

    /**
     * Constructor.
     *
     * @param ObjectService        $objectService     OpenRegister object service (object-op).
     * @param IManager             $notificationManager NC notification manager (send-notification).
     * @param IClientService       $httpClientService  NC HTTP client factory (webhook).
     * @param IUserSession         $userSession        Current NC user session (notification actor + object-op attribution).
     * @param JobOwnerImpersonator $ownerImpersonator  Impersonates an object's owner for owner-less write contexts.
     * @param ContainerInterface   $container          PSR container — lazily resolves RuleEngineService for
     *                                                 `call-rule-set` to avoid a constructor cycle.
     * @param LoggerInterface      $logger             PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IManager $notificationManager,
        private readonly IClientService $httpClientService,
        private readonly IUserSession $userSession,
        private readonly JobOwnerImpersonator $ownerImpersonator,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Dispatch one side-effecting action.
     *
     * @param string              $type    Action type (see class docblock).
     * @param array<string,mixed> $params  Action-specific parameters.
     * @param array<string,mixed> $payload The working payload at dispatch time.
     *
     * @return mixed Action-specific result (ignored by the executor); never throws.
     */
    public function __invoke(string $type, array $params, array $payload): mixed
    {
        try {
            return match ($type) {
                'send-notification' => $this->dispatchNotification(params: $params),
                'object-op' => $this->dispatchObjectOp(params: $params),
                'webhook' => $this->dispatchWebhook(params: $params),
                'start-workflow' => $this->dispatchStartWorkflow(params: $params),
                'call-rule-set' => $this->dispatchCallRuleSet(params: $params, payload: $payload),
                default => $this->logUnknown(type: $type),
            };
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: RuleActionDispatcher failed for action "'.$type.'": '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }//end try

    }//end __invoke()

    /**
     * send-notification — create an NC notification for the resolved recipient(s).
     *
     * @param array<string,mixed> $params Action parameters.
     *
     * @return int Number of notifications created.
     */
    private function dispatchNotification(array $params): int
    {
        $subject = (string) ($params['subject'] ?? '');
        if ($subject === '') {
            $subject = 'Automation notification';
        }

        $recipients = [];
        if (isset($params['recipientUid']) === true && is_string($params['recipientUid']) === true && $params['recipientUid'] !== '') {
            $recipients[] = $params['recipientUid'];
        }

        if (isset($params['recipientUids']) === true && is_array($params['recipientUids']) === true) {
            foreach ($params['recipientUids'] as $uid) {
                if (is_string($uid) === true && $uid !== '') {
                    $recipients[] = $uid;
                }
            }
        }

        if ($recipients === []) {
            $this->logger->info('OpenBuild: send-notification action had no resolvable recipient — skipped.');
            return 0;
        }

        $sent = 0;
        foreach (array_unique($recipients) as $uid) {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(self::NOTIFICATION_APP)
                ->setUser($uid)
                ->setDateTime(new \DateTime())
                ->setObject('automation', (string) ($params['objectId'] ?? 'n/a'))
                ->setSubject('automation-action', ['subject' => $subject]);

            $this->notificationManager->notify($notification);
            $sent++;
        }

        return $sent;

    }//end dispatchNotification()

    /**
     * object-op — create or update an object via OpenRegister's ObjectService.
     *
     * @param array<string,mixed> $params Action parameters.
     *
     * @return array<string,mixed>|null The saved object, normalised, or null on skip.
     */
    private function dispatchObjectOp(array $params): ?array
    {
        $schema = (string) ($params['schema'] ?? '');
        if ($schema === '') {
            $this->logger->warning('OpenBuild: object-op action missing "schema" — skipped.');
            return null;
        }

        $register  = (string) ($params['register'] ?? 'openbuild');
        $operation = (string) ($params['operation'] ?? 'create');
        $object    = is_array($params['object'] ?? null) ? $params['object'] : [];
        $id        = (string) ($params['id'] ?? '');

        if ($operation === 'update' && $id === '') {
            $this->logger->warning('OpenBuild: object-op update action missing "id" — skipped.');
            return null;
        }

        $write = function () use ($object, $register, $schema, $operation, $id) {
            if ($operation === 'update') {
                return $this->objectService->saveObject(object: $object, register: $register, schema: $schema, uuid: $id);
            }

            return $this->objectService->saveObject(object: $object, register: $register, schema: $schema);
        };

        if ($this->userSession->getUser() === null && $id !== '') {
            $saved = $this->ownerImpersonator->runAsOwner(objectId: $id, work: $write);
        } else {
            $saved = $write();
        }

        return $this->normalise(object: $saved);

    }//end dispatchObjectOp()

    /**
     * webhook — POST the compiled target via NC's HTTP client service.
     *
     * @param array<string,mixed> $params Action parameters.
     *
     * @return int|null The response status code, or null on skip/failure.
     */
    private function dispatchWebhook(array $params): ?int
    {
        $url = (string) ($params['url'] ?? '');
        if ($url === '') {
            $this->logger->warning('OpenBuild: webhook action missing "url" — skipped.');
            return null;
        }

        $payload = is_array($params['payload'] ?? null) ? $params['payload'] : [];

        $client   = $this->httpClientService->newClient();
        $response = $client->post($url, ['json' => $payload, 'timeout' => 10]);

        return $response->getStatusCode();

    }//end dispatchWebhook()

    /**
     * start-workflow — reserved: no workflow engine exists in openbuild
     * (design.md non-goal). Logged, never throws.
     *
     * @param array<string,mixed> $params Action parameters.
     *
     * @return null
     */
    private function dispatchStartWorkflow(array $params): null
    {
        $this->logger->info(
            'OpenBuild: start-workflow action dispatched but no workflow engine is wired in openbuild — no-op.',
            ['workflowId' => ($params['workflowId'] ?? null)]
        );
        return null;

    }//end dispatchStartWorkflow()

    /**
     * call-rule-set — recursively evaluate the referenced RuleSet.
     *
     * Resolved lazily via the PSR container to avoid a constructor cycle
     * with RuleEngineService (mirrors JobOwnerImpersonator's pattern).
     *
     * @param array<string,mixed> $params  Action parameters (`ruleSetSlug`).
     * @param array<string,mixed> $payload The payload to forward.
     *
     * @return array<string,mixed>|null The nested evaluation result, or null on skip/failure.
     */
    private function dispatchCallRuleSet(array $params, array $payload): ?array
    {
        $ruleSetSlug = (string) ($params['ruleSetSlug'] ?? '');
        if ($ruleSetSlug === '') {
            $this->logger->warning('OpenBuild: call-rule-set action missing "ruleSetSlug" — skipped.');
            return null;
        }

        if ($this->container->has(RuleEngineService::class) === false) {
            $this->logger->warning('OpenBuild: call-rule-set could not resolve RuleEngineService — skipped.');
            return null;
        }

        /** @var RuleEngineService $engine */
        $engine = $this->container->get(RuleEngineService::class);
        return $engine->evaluate(ruleSetSlug: $ruleSetSlug, payload: $payload);

    }//end dispatchCallRuleSet()

    /**
     * Log and no-op an unrecognised action type.
     *
     * @param string $type The unrecognised action type.
     *
     * @return null
     */
    private function logUnknown(string $type): null
    {
        $this->logger->warning('OpenBuild: RuleActionDispatcher received an unrecognised action type "'.$type.'".');
        return null;

    }//end logUnknown()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>|null
     */
    private function normalise(mixed $object): ?array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return null;

    }//end normalise()
}//end class
