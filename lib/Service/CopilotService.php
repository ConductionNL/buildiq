<?php

/**
 * OpenBuild CopilotService
 *
 * Orchestrates the prompt-to-app copilot: probes LLM availability, turns a
 * natural-language brief into a validated JSON plan restricted to the
 * OpenBuild MCP tool catalogue, predicts the manifest impact of that plan,
 * and executes an approved plan atomically through the exact same handler
 * classes the MCP surface uses (`OpenBuildToolProvider::invokeTool()`) — no
 * duplicated builder logic (design.md Decision 1).
 *
 * LLM access rides Nextcloud's Task Processing API (`OCP\TaskProcessing`,
 * `TextToText` task type, NC 30+, design.md Decision 2). The interface is
 * resolved lazily through the DI container and guarded by
 * `interface_exists()` so this class loads cleanly on NC 28/29, where the
 * copilot is simply unavailable (503).
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
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenBuild\Exception\CopilotException;
use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenBuild\Service\Copilot\CopilotPlanValidator;
use OCA\OpenBuild\Service\Copilot\CopilotPromptBuilder;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

// NOTE: OCP\TaskProcessing\Task and OCP\TaskProcessing\TaskTypes\TextToText
// ship only on NC 30+. A `use` import merely aliases the name — PHP does not
// resolve/autoload it until the class is actually referenced — so importing
// them here is safe even on NC 28/29; every call site that constructs or
// references them is reached only after health()/assertAvailable() has
// already confirmed `OCP\TaskProcessing\IManager` exists.
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Prompt-to-app copilot orchestrator (REQ-OBAIC-001 through REQ-OBAIC-007).
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
 */
class CopilotService
{

    private const REGISTER_SLUG = 'openbuild';

    private const APPLICATION_SCHEMA = 'application';

    private const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

    private const AGENT_SCHEMA = 'agent';

    /**
     * Task type id for `OCP\TaskProcessing\TaskTypes\TextToText::ID`.
     *
     * Kept as a local string constant (rather than referencing the class
     * directly at the top of the file) so this class stays loadable on
     * NC 28/29, where the `OCP\TaskProcessing` namespace does not exist.
     *
     * @var string
     */
    private const TEXT_TO_TEXT_TASK_TYPE_ID = 'core:text2text';

    /**
     * Hard timeout for the synchronous plan-call poll loop (design.md Decision 2).
     *
     * @var float
     */
    private const LLM_TIMEOUT_SECONDS = 120.0;

    /**
     * Poll interval while waiting for the TaskProcessing task to finish.
     *
     * @var int
     */
    private const POLL_INTERVAL_MICROSECONDS = 500000;

    /**
     * Roles that grant write access to an Application (mirrors AbstractToolHandler).
     *
     * @var array<int, string>
     */
    private const WRITE_ROLES = ['owners', 'editors'];

    /**
     * Manifest-mutating tools whose predicted impact is tracked by
     * {@see predictManifests()}.
     *
     * @var array<int, string>
     */
    private const MANIFEST_MUTATING_TOOLS = ['openbuild.upsertPage', 'openbuild.addWidget', 'openbuild.upsertMenuItem'];

    /**
     * Tools that require an existing-app RBAC check at execute time (every
     * write tool except createApp, which creates its own app and grants the
     * caller ownership instead).
     *
     * @var array<int, string>
     */
    private const EXISTING_APP_WRITE_TOOLS = [
        'openbuild.upsertSchema',
        'openbuild.upsertPage',
        'openbuild.addWidget',
        'openbuild.upsertMenuItem',
        'openbuild.promoteVersion',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface         $container                  DI container, used to lazily resolve
     *                                                               `OCP\TaskProcessing\IManager` (NC
     *                                                               30+ only).
     * @param LoggerInterface            $logger                     PSR logger for diagnostics.
     * @param ObjectService              $objectService              OpenRegister object surface (reads only
     *                                                               — writes flow through
     *                                                               `invokeTool()`).
     * @param IUserManager               $userManager                Resolves a uid string to an `IUser` for RBAC.
     * @param IGroupManager              $groupManager               Group manager (admin bypass logging).
     * @param PermissionResolver         $permissionResolver         Shared permission-grammar resolver.
     * @param OpenBuildToolProvider      $toolProvider               MCP dispatcher — the single
     *                                                               execution path.
     * @param CopilotPlanValidator       $planValidator              Structural plan validator.
     * @param CopilotPromptBuilder       $promptBuilder              System-prompt builder.
     * @param ApplicationDeletionService $applicationDeletionService Compensates a plan-created app on rollback.
     * @param AgentRunLogger             $agentRunLogger             Persists the transparent AgentRun record for
     *                                                               every agent-scoped plan/execute/discard turn.
     * @param AuditTrailMapper|null      $auditTrailMapper           Optional OR audit-trail writer for admin-bypass parity (L2).
     *
     * @return void
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly PermissionResolver $permissionResolver,
        private readonly OpenBuildToolProvider $toolProvider,
        private readonly CopilotPlanValidator $planValidator,
        private readonly CopilotPromptBuilder $promptBuilder,
        private readonly ApplicationDeletionService $applicationDeletionService,
        private readonly AgentRunLogger $agentRunLogger,
        private readonly ?AuditTrailMapper $auditTrailMapper=null,
    ) {
    }//end __construct()

    /**
     * Probe copilot availability.
     *
     * @return array{available: bool, reason?: string}
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     */
    public function health(): array
    {
        if (interface_exists('OCP\\TaskProcessing\\IManager') === false) {
            return ['available' => false, 'reason' => 'unsupported_server'];
        }

        $manager = $this->resolveTaskProcessingManager();
        if ($manager === null) {
            return ['available' => false, 'reason' => 'unsupported_server'];
        }

        try {
            $taskTypes = $manager->getAvailableTaskTypes();
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild Copilot: getAvailableTaskTypes failed: '.$e->getMessage());
            return ['available' => false, 'reason' => 'no_provider'];
        }

        if (is_array($taskTypes) === false || isset($taskTypes[self::TEXT_TO_TEXT_TASK_TYPE_ID]) === false) {
            return ['available' => false, 'reason' => 'no_provider'];
        }

        return ['available' => true];
    }//end health()

    /**
     * Turn a natural-language brief into a validated plan. Performs zero
     * builder writes (an agent-scoped rejected plan still writes exactly
     * one `AgentRun` audit record — never an Application/ApplicationVersion/
     * schema/manifest write).
     *
     * @param string      $brief   User's natural-language brief (1-2000 chars).
     * @param string|null $appSlug Optional target app slug. Ignored (overridden by the resolved
     *                             agent's `applicationSlug`) when `$agentId` is given — an agent's
     *                             app scope is never taken from client input (agent-workspace
     *                             design.md "scoped to the Application the agent belongs to").
     * @param string      $userId  Acting user's UID.
     * @param string|null $agentId Optional `Agent` id narrowing the effective tool allow-list and
     *                             prefixing the agent's instructions onto the system prompt
     *                             (agent-workspace design.md Decision 1).
     *
     * @return array{summary: string, steps: array<int, array<string, mixed>>, manifests: array<string, array{current: array, predicted: array}>}
     *
     * @throws CopilotException On unavailability, invalid input, RBAC denial, an unknown agent, or an unparsable/invalid plan.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
     */
    public function plan(string $brief, ?string $appSlug, string $userId, ?string $agentId=null): array
    {
        $this->assertAvailable();
        $this->assertValidBrief(brief: $brief);

        $agent = null;
        if ($agentId !== null && $agentId !== '') {
            $agent   = $this->requireAgent(agentId: $agentId);
            $appSlug = (string) ($agent['applicationSlug'] ?? '');
        }

        try {
            return $this->planWithinContext(brief: $brief, appSlug: $appSlug, userId: $userId, agent: $agent);
        } catch (CopilotException $e) {
            if ($agent !== null) {
                $this->agentRunLogger->log(
                    agent: $agent,
                    userId: $userId,
                    prompt: $brief,
                    plan: [],
                    toolCalls: [],
                    outcome: 'plan-rejected'
                );
            }

            throw $e;
        }//end try
    }//end plan()

    /**
     * Core plan logic, wrapped by {@see plan()} so an agent-scoped failure
     * can be logged exactly once regardless of which validation layer rejects it.
     *
     * @param string                    $brief   User's natural-language brief.
     * @param string|null               $appSlug Resolved target app slug (already overridden from the
     *                                           agent when `$agent` is non-null).
     * @param string                    $userId  Acting user's UID.
     * @param array<string, mixed>|null $agent   The resolved `Agent` record, or null for the bare copilot path.
     *
     * @return array{summary: string, steps: array<int, array<string, mixed>>, manifests: array<string, array{current: array, predicted: array}>}
     *
     * @throws CopilotException On RBAC denial or an unparsable/invalid/over-cap plan.
     */
    private function planWithinContext(string $brief, ?string $appSlug, string $userId, ?array $agent): array
    {
        $targetContext = null;
        if ($appSlug !== null && $appSlug !== '') {
            $app = $this->requireExistingVirtualApp(appSlug: $appSlug);
            $this->assertWriteRoleOnApp(app: $app, userId: $userId);
            $targetContext = [
                'appSlug'         => $appSlug,
                'manifestSummary' => $this->summariseManifest(manifest: (array) ($app['manifest'] ?? [])),
            ];
        }

        $effectiveDescriptors = $this->toolProvider->getToolDescriptors();
        $instructionsPrefix   = null;
        if ($agent !== null) {
            $agentEnabledTools    = (array) ($agent['enabledTools'] ?? []);
            $effectiveDescriptors = $this->narrowDescriptors(descriptors: $effectiveDescriptors, enabledTools: $agentEnabledTools);
            $instructionsPrefix   = (string) ($agent['instructions'] ?? '');
        }

        $plan = $this->requestPlanFromLlm(
            brief: $brief,
            appSlug: $appSlug,
            userId: $userId,
            targetContext: $targetContext,
            toolDescriptors: $effectiveDescriptors,
            instructionsPrefix: $instructionsPrefix
        );

        $violations = $this->planValidator->validate(plan: $plan, toolDescriptors: $effectiveDescriptors);
        if ($violations !== []) {
            throw new CopilotException(
                errorCode: 'plan_invalid',
                message: (string) ($violations[0]['message'] ?? 'The generated plan is invalid.'),
                httpStatus: 422,
                context: ['violations' => $violations]
            );
        }

        if ($agent !== null) {
            $this->assertWithinMaxActionsPerRun(agent: $agent, plan: $plan);
        }

        $manifests = $this->predictManifests(plan: $plan, appSlug: $appSlug);

        return [
            'summary'   => (string) ($plan['summary'] ?? ''),
            'steps'     => (array) ($plan['steps'] ?? []),
            'manifests' => $manifests,
        ];
    }//end planWithinContext()

    /**
     * Discard a reviewed proposal without executing it — still writes a transparent
     * `AgentRun` record (agent-workspace spec "A discarded proposal is still logged").
     * Only meaningful for the agent-scoped chat surface: the bare copilot panel
     * never calls this (D3 — omitted agent props, zero behavioural change).
     *
     * @param string               $agentId The `Agent` id this turn belongs to.
     * @param string               $userId  Acting user's UID.
     * @param string               $prompt  The user's natural-language brief for this turn.
     * @param array<string, mixed> $plan    The plan `{summary, steps[]}` that was reviewed and discarded.
     *
     * @return array<string, mixed> The persisted `AgentRun` record.
     *
     * @throws CopilotException On an unknown agent or RBAC denial.
     *
     * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
     */
    public function discard(string $agentId, string $userId, string $prompt, array $plan): array
    {
        $agent   = $this->requireAgent(agentId: $agentId);
        $appSlug = (string) ($agent['applicationSlug'] ?? '');
        if ($appSlug !== '') {
            $app = $this->requireExistingVirtualApp(appSlug: $appSlug);
            $this->assertWriteRoleOnApp(app: $app, userId: $userId);
        }

        return $this->agentRunLogger->log(
            agent: $agent,
            userId: $userId,
            prompt: $prompt,
            plan: $plan,
            toolCalls: [],
            outcome: 'discarded'
        );
    }//end discard()

    /**
     * Predict the manifest impact of a plan without writing anything.
     *
     * Applies every manifest-mutating step (upsertPage, addWidget,
     * upsertMenuItem) to an in-memory copy of each touched version's
     * manifest and enforces the manifest caps on the predicted result.
     *
     * @param array<string, mixed> $plan    Decoded plan `{summary, steps[]}`.
     * @param string|null          $appSlug Optional top-level target app slug (steps may override via `appSlug`).
     *
     * @return array<string, array{current: array<string, mixed>, predicted: array<string, mixed>}>
     *
     * @throws CopilotException (422 plan_invalid) When a predicted manifest exceeds a cap.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     */
    public function predictManifests(array $plan, ?string $appSlug): array
    {
        $steps           = (array) ($plan['steps'] ?? []);
        $createdAppSlugs = $this->collectCreatedAppSlugs(steps: $steps);
        $manifests       = [];

        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            if (in_array(needle: $tool, haystack: self::MANIFEST_MUTATING_TOOLS, strict: true) === false) {
                continue;
            }

            $args        = (array) ($step['arguments'] ?? []);
            $targetSlug  = (string) ($args['appSlug'] ?? ($appSlug ?? ''));
            $versionSlug = (string) ($args['versionSlug'] ?? 'development');
            $key         = $targetSlug.'@'.$versionSlug;

            if (isset($manifests[$key]) === false) {
                $baseline        = $this->loadBaselineManifest(
                    appSlug: $targetSlug,
                    versionSlug: $versionSlug,
                    isPlanCreated: isset($createdAppSlugs[$targetSlug]) === true
                );
                $manifests[$key] = ['current' => $baseline, 'predicted' => $baseline];
            }

            $predicted = $this->applyStepToManifest(tool: $tool, args: $args, manifest: $manifests[$key]['predicted']);

            $capMessage = $this->manifestCapViolation(manifest: $predicted, pageId: (string) ($args['pageId'] ?? ''));
            if ($capMessage !== null) {
                throw new CopilotException(errorCode: 'plan_invalid', message: $capMessage, httpStatus: 422);
            }

            $manifests[$key]['predicted'] = $predicted;
        }//end foreach

        return $manifests;
    }//end predictManifests()

    /**
     * Execute an approved plan atomically through the MCP handler layer.
     *
     * Re-validates the plan (the server never trusts the client's review),
     * snapshots every touched version's manifest, dispatches each step in
     * order (createApp first, promoteVersion last) through
     * `OpenBuildToolProvider::invokeTool()`, and on any step failure rolls
     * every snapshot back and deletes a plan-created application. When
     * `$agentId` is given, the resolved agent's tool allow-list is
     * re-applied to the revalidation and a transparent `AgentRun` record is
     * persisted regardless of outcome (agent-workspace design.md Decision 2).
     *
     * @param array<string, mixed> $plan    The reviewed plan `{summary, steps[]}`, echoed back verbatim.
     * @param string               $userId  Acting user's UID.
     * @param string|null          $agentId Optional `Agent` id this plan was planned with — re-resolved
     *                                      server-side, never trusted from the client beyond the id.
     * @param string               $prompt  The original user brief for this turn, echoed back so the
     *                                      resulting `AgentRun` record carries it. Ignored when
     *                                      `$agentId` is null.
     *
     * @return array{results: array<int, array<string, mixed>>}
     *
     * @throws CopilotException On revalidation failure, an unknown agent, RBAC denial, or a mid-plan step failure.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
     */
    public function execute(array $plan, string $userId, ?string $agentId=null, string $prompt=''): array
    {
        $agent = $this->resolveAgentForExecute(plan: $plan, userId: $userId, prompt: $prompt, agentId: $agentId);

        $steps = (array) ($plan['steps'] ?? []);

        // Re-run predictManifests() for its manifest-cap check (design.md
        // Decision 4 step 1: "re-runs validation layers 2-3").
        $this->predictManifests(plan: $plan, appSlug: null);

        $createdAppSlugs = $this->collectCreatedAppSlugs(steps: $steps);
        $this->assertExecuteRbac(steps: $steps, createdAppSlugs: $createdAppSlugs, userId: $userId);

        $snapshots = $this->snapshotTouchedVersions(steps: $steps, createdAppSlugs: $createdAppSlugs);
        $ordered   = $this->orderSteps(steps: $steps);

        return $this->dispatchOrderedSteps(
            ordered: $ordered,
            snapshots: $snapshots,
            agent: $agent,
            userId: $userId,
            prompt: $prompt,
            plan: $plan
        );
    }//end execute()

    /**
     * Resolve+revalidate the optional agent scope for an execute() call
     * (narrowed allow-list re-check, `maxActionsPerRun` re-check), logging a
     * `plan-rejected` AgentRun on any rejection before rethrowing. Split out
     * of {@see execute()} to keep its own complexity within the project's
     * PHPMD thresholds.
     *
     * @param array<string, mixed> $plan    The reviewed plan `{summary, steps[]}`.
     * @param string               $userId  Acting user's UID.
     * @param string               $prompt  The original user brief for this turn (for the AgentRun log).
     * @param string|null          $agentId Optional `Agent` id this plan was planned with.
     *
     * @return array<string, mixed>|null The resolved `Agent` record, or null for the bare copilot path.
     *
     * @throws CopilotException On an unknown agent or a revalidation failure.
     */
    private function resolveAgentForExecute(array $plan, string $userId, string $prompt, ?string $agentId): ?array
    {
        $agent = null;
        if ($agentId !== null && $agentId !== '') {
            $agent = $this->requireAgent(agentId: $agentId);
        }

        $effectiveDescriptors = $this->toolProvider->getToolDescriptors();
        if ($agent !== null) {
            $agentEnabledTools    = (array) ($agent['enabledTools'] ?? []);
            $effectiveDescriptors = $this->narrowDescriptors(descriptors: $effectiveDescriptors, enabledTools: $agentEnabledTools);
        }

        try {
            $violations = $this->planValidator->validate(plan: $plan, toolDescriptors: $effectiveDescriptors);
            if ($violations !== []) {
                throw new CopilotException(
                    errorCode: 'plan_invalid',
                    message: (string) ($violations[0]['message'] ?? 'The plan is invalid.'),
                    httpStatus: 422,
                    context: ['violations' => $violations]
                );
            }

            if ($agent !== null) {
                $this->assertWithinMaxActionsPerRun(agent: $agent, plan: $plan);
            }
        } catch (CopilotException $e) {
            if ($agent !== null) {
                $this->agentRunLogger->log(agent: $agent, userId: $userId, prompt: $prompt, plan: $plan, toolCalls: [], outcome: 'plan-rejected');
            }

            throw $e;
        }//end try

        return $agent;
    }//end resolveAgentForExecute()

    /**
     * Dispatch every ordered step through `invokeTool()`, collecting results
     * + tool calls, and on any failure roll back every snapshot. When
     * `$agent` is non-null, persists the transparent `AgentRun` record
     * (outcome `applied` or `rolled-back`). Split out of {@see execute()} to
     * keep its own complexity within the project's PHPMD thresholds.
     *
     * @param array<int, array<string, mixed>> $ordered   Plan steps in dispatch order.
     * @param array<int, array<string, mixed>> $snapshots Manifest snapshots to restore on failure.
     * @param array<string, mixed>|null        $agent     The resolved `Agent` record, or null for the bare copilot path.
     * @param string                           $userId    Acting user's UID.
     * @param string                           $prompt    The original user brief for this turn (for the AgentRun log).
     * @param array<string, mixed>             $plan      The reviewed plan (for the AgentRun log).
     *
     * @return array{results: array<int, array<string, mixed>>}
     *
     * @throws CopilotException On a mid-plan step failure.
     */
    private function dispatchOrderedSteps(array $ordered, array $snapshots, ?array $agent, string $userId, string $prompt, array $plan): array
    {
        $results        = [];
        $toolCalls      = [];
        $createdAppUuid = null;
        $createdAppSlug = null;

        try {
            foreach ($ordered as $index => $step) {
                $tool        = (string) ($step['tool'] ?? '');
                $args        = (array) ($step['arguments'] ?? []);
                $result      = $this->toolProvider->invokeTool($tool, $args);
                $toolCalls[] = ['tool' => $tool, 'arguments' => $args, 'result' => $result];

                if (($result['isError'] ?? false) === true) {
                    throw new CopilotException(
                        errorCode: 'execution_failed',
                        message: (string) ($result['message'] ?? 'A plan step failed.'),
                        httpStatus: 422,
                        stepIndex: $index,
                        context: ['step' => $step, 'handler' => $result]
                    );
                }

                if ($tool === 'openbuild.createApp') {
                    $createdAppUuid = (string) ($result['app']['uuid'] ?? '');
                    $createdAppSlug = (string) ($result['app']['slug'] ?? ($args['slug'] ?? ''));
                }

                $results[] = $result;
            }//end foreach

            if ($agent !== null) {
                $this->agentRunLogger->log(agent: $agent, userId: $userId, prompt: $prompt, plan: $plan, toolCalls: $toolCalls, outcome: 'applied');
            }

            return ['results' => $results];
        } catch (Throwable $e) {
            $this->rollback(snapshots: $snapshots, createdAppUuid: $createdAppUuid, createdAppSlug: $createdAppSlug);

            if ($agent !== null) {
                $this->agentRunLogger->log(
                    agent: $agent,
                    userId: $userId,
                    prompt: $prompt,
                    plan: $plan,
                    toolCalls: $toolCalls,
                    outcome: 'rolled-back'
                );
            }

            if ($e instanceof CopilotException) {
                throw $e;
            }

            $this->logger->error('OpenBuild Copilot: execute failed: '.$e->getMessage(), ['exception' => $e]);
            throw new CopilotException(
                errorCode: 'execution_failed',
                message: 'Failed to execute the plan. See server logs for details.',
                httpStatus: 422,
                previous: $e
            );
        }//end try
    }//end dispatchOrderedSteps()

    /**
     * Assert the copilot is available, throwing the health-mapped exception otherwise.
     *
     * @return void
     *
     * @throws CopilotException (503) When unavailable.
     */
    private function assertAvailable(): void
    {
        $health = $this->health();
        if ($health['available'] === true) {
            return;
        }

        throw new CopilotException(
            errorCode: (string) ($health['reason'] ?? 'unsupported_server'),
            message: 'The AI copilot is not available on this server.',
            httpStatus: 503
        );
    }//end assertAvailable()

    /**
     * Validate the brief length (1-2000 chars).
     *
     * @param string $brief The user's brief.
     *
     * @return void
     *
     * @throws CopilotException (422) When the brief is empty or too long.
     */
    private function assertValidBrief(string $brief): void
    {
        $length = mb_strlen($brief);
        if ($length < 1 || $length > 2000) {
            throw new CopilotException(
                errorCode: 'invalid_arguments',
                message: 'Brief must be between 1 and 2000 characters.',
                httpStatus: 422
            );
        }
    }//end assertValidBrief()

    /**
     * Resolve an existing virtual (non-hybrid) app by slug, or throw.
     *
     * @param string $appSlug Target app slug.
     *
     * @return array<string, mixed>
     *
     * @throws CopilotException (404 not_found / 422 unsupported_target).
     */
    private function requireExistingVirtualApp(string $appSlug): array
    {
        $app = $this->resolveApplicationBySlug(appSlug: $appSlug);
        if ($app === null) {
            throw new CopilotException(
                errorCode: 'not_found',
                message: "No virtual app found for slug '{$appSlug}'.",
                httpStatus: 404
            );
        }

        if ((string) ($app['appType'] ?? 'virtual') === 'hybrid') {
            throw new CopilotException(
                errorCode: 'unsupported_target',
                message: "Application '{$appSlug}' is a hybrid app; the copilot only edits virtual apps.",
                httpStatus: 422
            );
        }

        return $app;
    }//end requireExistingVirtualApp()

    /**
     * Resolve an Application by slug via OR's object surface.
     *
     * @param string $appSlug Application slug.
     *
     * @return array<string, mixed>|null
     */
    private function resolveApplicationBySlug(string $appSlug): ?array
    {
        $apps = $this->objectService->searchObjectsBySlug(
            self::REGISTER_SLUG,
            self::APPLICATION_SCHEMA,
            ['slug' => $appSlug],
            _rbac: true,
            _multitenancy: false
        );

        if (is_array($apps) === false || $apps === []) {
            return null;
        }

        return $this->toArray(item: $apps[0]);
    }//end resolveApplicationBySlug()

    /**
     * Resolve an `Agent` by id server-side, or throw. Never trusts anything
     * about the agent beyond the id the client supplied — every other field
     * (`applicationSlug`, `enabledTools`, `instructions`, `maxActionsPerRun`)
     * is read from the resolved record (agent-workspace design.md Decision 1).
     *
     * @param string $agentId The `Agent` object id/uuid.
     *
     * @return array<string, mixed>
     *
     * @throws CopilotException (404 not_found) When no such agent exists.
     */
    private function requireAgent(string $agentId): array
    {
        try {
            $entity = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
        } catch (Throwable $e) {
            $entity = null;
        }

        if ($entity === null) {
            throw new CopilotException(
                errorCode: 'not_found',
                message: "No agent found for id '{$agentId}'.",
                httpStatus: 404
            );
        }

        return $this->toArray(item: $entity);
    }//end requireAgent()

    /**
     * Narrow the full tool catalogue to the server-side intersection with an
     * agent's `enabledTools` — the ONLY place the effective allow-list is
     * computed. Can only ever shrink the catalogue, never add to it
     * (agent-workspace design.md Decision 1 / spec "An agent's tool scope
     * can never exceed the base copilot catalogue").
     *
     * @param array<int, array<string, mixed>> $descriptors  Full tool catalogue.
     * @param array<int, mixed>                $enabledTools Agent's `enabledTools` list.
     *
     * @return array<int, array<string, mixed>> The narrowed descriptor list.
     */
    private function narrowDescriptors(array $descriptors, array $enabledTools): array
    {
        $enabledSet = array_flip(array_map('strval', $enabledTools));

        return array_values(
            array_filter(
                $descriptors,
                static fn (array $descriptor): bool => isset($enabledSet[(string) ($descriptor['id'] ?? '')]) === true
            )
        );
    }//end narrowDescriptors()

    /**
     * Enforce `maxActionsPerRun` at plan-acceptance time (agent-workspace
     * design.md Decision 4) — the same 422-with-named-violated-cap shape the
     * existing manifest-cap rejection uses.
     *
     * @param array<string, mixed> $agent The resolved `Agent` record.
     * @param array<string, mixed> $plan  Decoded plan `{summary, steps[]}`.
     *
     * @return void
     *
     * @throws CopilotException (422 plan_invalid) When the step count exceeds the cap.
     */
    private function assertWithinMaxActionsPerRun(array $agent, array $plan): void
    {
        $cap   = (int) ($agent['maxActionsPerRun'] ?? 10);
        $steps = (array) ($plan['steps'] ?? []);
        if ($cap > 0 && count($steps) > $cap) {
            throw new CopilotException(
                errorCode: 'plan_invalid',
                message: "Plan exceeds this agent's cap of {$cap} action(s) per run (max_actions_per_run).",
                httpStatus: 422,
                context: ['violatedCap' => 'max_actions_per_run']
            );
        }
    }//end assertWithinMaxActionsPerRun()

    /**
     * Verify the caller holds an owners/editors role on the Application
     * (admin bypass permitted and logged, mirroring `AbstractToolHandler::requireWriteRole`).
     *
     * @param array<string, mixed> $app    Application data.
     * @param string               $userId Acting user's UID.
     *
     * @return void
     *
     * @throws CopilotException (403) On denial.
     */
    private function assertWriteRoleOnApp(array $app, string $userId): void
    {
        $caller = $this->userManager->get($userId);
        if ($caller instanceof IUser === false) {
            throw new CopilotException(errorCode: 'forbidden', message: 'You must be signed in.', httpStatus: 403);
        }

        $permissions = (array) ($app['permissions'] ?? []);
        $userGroups  = $this->permissionResolver->resolveUserGroups(user: $caller);
        $allowed     = $this->permissionResolver->matchesCaller(
            permissions: $permissions,
            caller: $caller,
            userGroups: $userGroups,
            allowAdminBypass: true,
            roles: self::WRITE_ROLES
        );

        if ($allowed === false) {
            throw new CopilotException(
                errorCode: 'forbidden',
                message: "You do not have owner or editor access to application '".(string) ($app['slug'] ?? '')."'.",
                httpStatus: 403
            );
        }

        // Record only a *genuine* admin bypass: an admin who would NOT pass the
        // owner/editor check without admin-group membership. An admin who also
        // holds a real role is exercising a legitimate grant, not a bypass —
        // auditing it would produce false compliance records
        // (harden-rules-authz-and-audit-parity, L2 / #5).
        $genuineBypass = $this->groupManager->isAdmin($userId) === true
            && $this->permissionResolver->matchesCaller(
                permissions: $permissions,
                caller: $caller,
                userGroups: $userGroups,
                allowAdminBypass: false,
                roles: self::WRITE_ROLES
            ) === false;

        if ($genuineBypass === true) {
            $context = [
                'event'   => 'rbac.admin_bypass',
                'actor'   => $userId,
                'appSlug' => (string) ($app['slug'] ?? ''),
                'channel' => 'copilot',
            ];

            // Audit-trail parity with the HTTP/MCP paths (REQ-OBRBAC-007): record
            // the bypass to the OR per-object audit trail when the mapper + app
            // entity are available; fail soft to a PSR log otherwise
            // (harden-rules-authz-and-audit-parity, L2).
            $entity = null;
            try {
                $entity = $this->objectService->find((string) ($app['uuid'] ?? ($app['id'] ?? '')));
            } catch (\Throwable $e) {
                $entity = null;
            }

            if ($this->auditTrailMapper !== null && $entity instanceof ObjectEntity) {
                try {
                    $this->auditTrailMapper->createAuditTrailEntry(object: $entity, action: 'rbac.admin_bypass', context: $context);
                    $this->logger->info('OpenBuild Copilot: rbac.admin_bypass', $context);
                    return;
                } catch (\Throwable $e) {
                    $this->logger->critical(
                        'OpenBuild Copilot: rbac.admin_bypass audit-trail write failed',
                        array_merge($context, ['exception' => $e->getMessage()])
                    );
                    return;
                }
            }//end if

            $this->logger->info('OpenBuild Copilot: rbac.admin_bypass', $context);
        }//end if
    }//end assertWriteRoleOnApp()

    /**
     * Resolve `OCP\TaskProcessing\IManager` from the container, or null when unavailable.
     *
     * @return object|null
     */
    private function resolveTaskProcessingManager(): ?object
    {
        try {
            return $this->container->get('OCP\\TaskProcessing\\IManager');
        } catch (Throwable $e) {
            return null;
        }
    }//end resolveTaskProcessingManager()

    /**
     * Request a plan from the LLM, with exactly one repair round-trip on parse failure.
     *
     * @param string                                $brief              User's brief.
     * @param string|null                           $appSlug            Optional target app slug (for the task's customId).
     * @param string                                $userId             Acting user's UID.
     * @param array<string, mixed>|null             $targetContext      Optional target-app context for the prompt.
     * @param array<int, array<string, mixed>>|null $toolDescriptors    Optional narrowed tool catalogue (agent-workspace
     *                                                                  design.md Decision 1) — defaults to the full
     *                                                                  catalogue when null.
     * @param string|null                           $instructionsPrefix Optional agent instructions prefixed onto the prompt.
     *
     * @return array<string, mixed> Decoded plan.
     *
     * @throws CopilotException (422 plan_invalid) When both attempts fail to parse.
     */
    private function requestPlanFromLlm(
        string $brief,
        ?string $appSlug,
        string $userId,
        ?array $targetContext,
        ?array $toolDescriptors=null,
        ?string $instructionsPrefix=null,
    ): array {
        $manager = $this->resolveTaskProcessingManager();
        if ($manager === null) {
            throw new CopilotException(errorCode: 'unsupported_server', message: 'The AI copilot is not available.', httpStatus: 503);
        }

        $prompt = $this->promptBuilder->build(
            brief: $brief,
            targetContext: $targetContext,
            toolDescriptors: $toolDescriptors,
            instructionsPrefix: $instructionsPrefix
        );
        [$plan, $raw, $parseError] = $this->runPlanAttempt(manager: $manager, prompt: $prompt, userId: $userId, appSlug: $appSlug);
        if ($plan !== null) {
            return $plan;
        }

        // Exactly one repair round-trip (spec REQ-OBAIC-002) — never a third LLM call.
        $repairPrompt          = $this->promptBuilder->buildRepairPrompt(
            brief: $brief,
            previousOutput: $raw,
            parseError: $parseError,
            targetContext: $targetContext,
            toolDescriptors: $toolDescriptors,
            instructionsPrefix: $instructionsPrefix
        );
        [$plan, , $parseError] = $this->runPlanAttempt(manager: $manager, prompt: $repairPrompt, userId: $userId, appSlug: $appSlug);
        if ($plan !== null) {
            return $plan;
        }

        throw new CopilotException(
            errorCode: 'plan_invalid',
            message: 'The AI could not produce a valid plan. Please rephrase your request.',
            httpStatus: 422
        );
    }//end requestPlanFromLlm()

    /**
     * Run a single LLM call attempt and try to decode the result as a plan object.
     *
     * @param object      $manager `OCP\TaskProcessing\IManager` instance.
     * @param string      $prompt  Fully-built system prompt.
     * @param string      $userId  Acting user's UID.
     * @param string|null $appSlug Optional target app slug, carried as the task's customId for observability.
     *
     * @return array{0: array<string, mixed>|null, 1: string, 2: string} `[plan|null, rawOutput, parseError]`.
     */
    private function runPlanAttempt(object $manager, string $prompt, string $userId, ?string $appSlug=null): array
    {
        try {
            $raw = $this->runTextToTextTask(manager: $manager, prompt: $prompt, userId: $userId, appSlug: $appSlug);
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild Copilot: LLM task failed: '.$e->getMessage());
            return [null, '', $e->getMessage()];
        }

        $stripped = $this->stripCodeFences(text: $raw);

        try {
            $decoded = json_decode($stripped, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [null, $raw, $e->getMessage()];
        }

        if (is_array($decoded) === false || isset($decoded['steps']) === false) {
            return [null, $raw, 'Decoded JSON is not a plan object with a "steps" array.'];
        }

        return [$decoded, $raw, ''];
    }//end runPlanAttempt()

    /**
     * Schedule a `TextToText` TaskProcessing task and poll to completion.
     *
     * @param object      $manager `OCP\TaskProcessing\IManager` instance.
     * @param string      $prompt  The prompt to send as task input.
     * @param string      $userId  Acting user's UID.
     * @param string|null $appSlug Optional target app slug, carried as the task's customId.
     *
     * @return string The task's `output` text.
     *
     * @throws RuntimeException On task failure, cancellation, or timeout.
     */
    private function runTextToTextTask(object $manager, string $prompt, string $userId, ?string $appSlug=null): string
    {
        $task = new Task(
            TextToText::ID,
            ['input' => $prompt],
            'openbuild',
            $userId,
            $appSlug,
        );

        $manager->scheduleTask($task);
        $taskId = $task->getId();
        if ($taskId === null) {
            throw new RuntimeException('TaskProcessing did not assign a task id.');
        }

        $deadline = microtime(as_float: true) + self::LLM_TIMEOUT_SECONDS;

        while (true) {
            $current = $manager->getTask($taskId);
            $status  = $current->getStatus();

            if ($status === Task::STATUS_SUCCESSFUL) {
                $output = $current->getOutput();
                return (string) ($output['output'] ?? '');
            }

            if ($status === Task::STATUS_FAILED || $status === Task::STATUS_CANCELLED) {
                throw new RuntimeException('LLM task failed: '.((string) $current->getErrorMessage()));
            }

            if (microtime(as_float: true) > $deadline) {
                throw new RuntimeException('LLM task timed out after '.((int) self::LLM_TIMEOUT_SECONDS).'s.');
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }//end while
    }//end runTextToTextTask()

    /**
     * Strip ```json ... ``` / ``` ... ``` code fences from an LLM response, if present.
     *
     * @param string $text Raw LLM output.
     *
     * @return string
     */
    private function stripCodeFences(string $text): string
    {
        $trimmed = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }//end stripCodeFences()

    /**
     * Build a compact manifest summary for the prompt (counts only — never the full blob).
     *
     * @param array<string, mixed> $manifest Current manifest.
     *
     * @return array{pageCount: int, menuCount: int, pageIds: array<int, string>}
     */
    private function summariseManifest(array $manifest): array
    {
        $pages = (array) ($manifest['pages'] ?? []);
        $menu  = (array) ($manifest['menu'] ?? []);

        return [
            'pageCount' => count($pages),
            'menuCount' => count($menu),
            'pageIds'   => array_values(
                array_filter(array_map(static fn ($page): string => (string) ($page['id'] ?? ''), $pages))
            ),
        ];
    }//end summariseManifest()

    /**
     * Collect the slugs of apps this plan itself would create.
     *
     * @param array<int, array<string, mixed>> $steps Plan steps.
     *
     * @return array<string, bool> Set of created app slugs.
     */
    private function collectCreatedAppSlugs(array $steps): array
    {
        $slugs = [];
        foreach ($steps as $step) {
            if ((string) ($step['tool'] ?? '') === 'openbuild.createApp') {
                $slug = (string) ($step['arguments']['slug'] ?? '');
                if ($slug !== '') {
                    $slugs[$slug] = true;
                }
            }
        }

        return $slugs;
    }//end collectCreatedAppSlugs()

    /**
     * Load the baseline manifest for a (appSlug, versionSlug) pair — an empty
     * scaffold for a plan-created app, or the persisted current manifest otherwise.
     *
     * @param string $appSlug       Target app slug.
     * @param string $versionSlug   Target version slug.
     * @param bool   $isPlanCreated Whether this app is created earlier in the same plan.
     *
     * @return array<string, mixed>
     */
    private function loadBaselineManifest(string $appSlug, string $versionSlug, bool $isPlanCreated): array
    {
        if ($isPlanCreated === true) {
            return ['version' => '1.0.0', 'menu' => [], 'pages' => []];
        }

        $version = $this->loadRawVersion(appSlug: $appSlug, versionSlug: $versionSlug);
        if ($version === null) {
            return ['version' => '1.0.0', 'menu' => [], 'pages' => []];
        }

        return (array) ($version['manifest'] ?? ['version' => '1.0.0', 'menu' => [], 'pages' => []]);
    }//end loadBaselineManifest()

    /**
     * Load a raw ApplicationVersion by (appSlug, versionSlug), or null when not found.
     *
     * @param string $appSlug     Application slug.
     * @param string $versionSlug ApplicationVersion slug.
     *
     * @return array<string, mixed>|null
     */
    private function loadRawVersion(string $appSlug, string $versionSlug): ?array
    {
        $app = $this->resolveApplicationBySlug(appSlug: $appSlug);
        if ($app === null) {
            return null;
        }

        $appUuid  = $this->extractUuid(item: $app);
        $versions = $this->objectService->searchObjectsBySlug(
            self::REGISTER_SLUG,
            self::APPLICATION_VERSION_SCHEMA,
            ['application' => $appUuid, 'slug' => $versionSlug],
            _rbac: true,
            _multitenancy: false
        );

        if (is_array($versions) === false || $versions === []) {
            return null;
        }

        return $this->toArray(item: $versions[0]);
    }//end loadRawVersion()

    /**
     * Apply one manifest-mutating tool step to an in-memory manifest copy.
     *
     * @param string               $tool     Tool id.
     * @param array<string, mixed> $args     Step arguments.
     * @param array<string, mixed> $manifest Manifest to mutate (copy).
     *
     * @return array<string, mixed> The mutated manifest.
     */
    private function applyStepToManifest(string $tool, array $args, array $manifest): array
    {
        return match ($tool) {
            'openbuild.upsertPage' => $this->applyUpsertPage(args: $args, manifest: $manifest),
            'openbuild.addWidget' => $this->applyAddWidget(args: $args, manifest: $manifest),
            'openbuild.upsertMenuItem' => $this->applyUpsertMenuItem(args: $args, manifest: $manifest),
            default => $manifest,
        };
    }//end applyStepToManifest()

    /**
     * Predict an `upsertPage` step (mirrors UpsertPageHandler's case-insensitive upsert).
     *
     * @param array<string, mixed> $args     Step arguments.
     * @param array<string, mixed> $manifest Manifest to mutate (copy).
     *
     * @return array<string, mixed>
     */
    private function applyUpsertPage(array $args, array $manifest): array
    {
        $pageId  = (string) ($args['pageId'] ?? '');
        $newPage = [
            'id'     => $pageId,
            'route'  => (string) ($args['route'] ?? ''),
            'type'   => (string) ($args['type'] ?? ''),
            'title'  => (string) ($args['title'] ?? ''),
            'config' => (array) ($args['config'] ?? []),
        ];

        $pages    = (array) ($manifest['pages'] ?? []);
        $replaced = false;
        $pageIdLc = strtolower($pageId);
        foreach ($pages as $i => $existing) {
            if (is_array($existing) === true && strtolower((string) ($existing['id'] ?? '')) === $pageIdLc) {
                $pages[$i] = $newPage;
                $replaced  = true;
                break;
            }
        }

        if ($replaced === false) {
            $pages[] = $newPage;
        }

        $manifest['pages'] = array_values($pages);
        return $manifest;
    }//end applyUpsertPage()

    /**
     * Predict an `addWidget` step (mirrors AddWidgetHandler's append-to-page semantics).
     *
     * @param array<string, mixed> $args     Step arguments.
     * @param array<string, mixed> $manifest Manifest to mutate (copy).
     *
     * @return array<string, mixed>
     */
    private function applyAddWidget(array $args, array $manifest): array
    {
        $pageId = (string) ($args['pageId'] ?? '');
        $pages  = (array) ($manifest['pages'] ?? []);

        $pageIdLc = strtolower($pageId);
        foreach ($pages as $i => $page) {
            if (is_array($page) === false || strtolower((string) ($page['id'] ?? '')) !== $pageIdLc) {
                continue;
            }

            $pageConfig = (array) ($page['config'] ?? []);
            $widgets    = (array) ($pageConfig['widgets'] ?? []);
            $widgets[]  = ['type' => (string) ($args['widgetType'] ?? ''), 'config' => (array) ($args['widgetConfig'] ?? [])];
            $pageConfig['widgets'] = $widgets;
            $page['config']        = $pageConfig;
            $pages[$i] = $page;
            break;
        }

        $manifest['pages'] = $pages;
        return $manifest;
    }//end applyAddWidget()

    /**
     * Predict an `upsertMenuItem` step (mirrors UpsertMenuItemHandler's case-insensitive upsert).
     *
     * @param array<string, mixed> $args     Step arguments.
     * @param array<string, mixed> $manifest Manifest to mutate (copy).
     *
     * @return array<string, mixed>
     */
    private function applyUpsertMenuItem(array $args, array $manifest): array
    {
        $id      = (string) ($args['id'] ?? '');
        $newItem = [
            'id'    => $id,
            'label' => (string) ($args['label'] ?? ''),
            'icon'  => (string) ($args['icon'] ?? ''),
            'route' => (string) ($args['route'] ?? ''),
            'order' => (int) ($args['order'] ?? 100),
        ];

        $menu     = (array) ($manifest['menu'] ?? []);
        $replaced = false;
        $idLc     = strtolower($id);
        foreach ($menu as $i => $existing) {
            if (is_array($existing) === true && strtolower((string) ($existing['id'] ?? '')) === $idLc) {
                $menu[$i] = $newItem;
                $replaced = true;
                break;
            }
        }

        if ($replaced === false) {
            $menu[] = $newItem;
        }

        $manifest['menu'] = array_values($menu);
        return $manifest;
    }//end applyUpsertMenuItem()

    /**
     * Check a predicted manifest against the H4 caps (256 KB / 100 pages / 30 menu
     * items / 50 widgets per page — mirrors AbstractToolHandler::checkManifestCaps()).
     *
     * @param array<string, mixed> $manifest Predicted manifest.
     * @param string               $pageId   Page id touched by the current step (widgets cap), or ''.
     *
     * @return string|null Violation message, or null when all caps pass.
     */
    private function manifestCapViolation(array $manifest, string $pageId): ?string
    {
        $json = json_encode($manifest);
        if ($json !== false && strlen($json) > (256 * 1024)) {
            return 'Manifest exceeds maximum size of 256 KB.';
        }

        $pages = (array) ($manifest['pages'] ?? []);
        if (count($pages) > 100) {
            return 'Manifest exceeds maximum of 100 pages.';
        }

        $menu = (array) ($manifest['menu'] ?? []);
        if (count($menu) > 30) {
            return 'Manifest exceeds maximum of 30 menu items.';
        }

        return $this->widgetCapViolation(pages: $pages, pageId: $pageId);
    }//end manifestCapViolation()

    /**
     * Check the 50-widgets-per-page cap for one page (split out of
     * {@see manifestCapViolation()} to keep its cyclomatic complexity down).
     *
     * @param array<int, mixed> $pages  Manifest pages.
     * @param string            $pageId Page id touched by the current step, or ''.
     *
     * @return string|null Violation message, or null when the cap passes (or no page is targeted).
     */
    private function widgetCapViolation(array $pages, string $pageId): ?string
    {
        if ($pageId === '') {
            return null;
        }

        $pageIdLc = strtolower($pageId);
        foreach ($pages as $page) {
            if (is_array($page) === false || strtolower((string) ($page['id'] ?? '')) !== $pageIdLc) {
                continue;
            }

            $widgets = (array) (($page['config']['widgets'] ?? []));
            if (count($widgets) > 50) {
                return 'Page exceeds maximum of 50 widgets.';
            }

            break;
        }

        return null;
    }//end widgetCapViolation()

    /**
     * Assert RBAC for every existing-app-targeting step in the plan (design.md Decision 6).
     *
     * @param array<int, array<string, mixed>> $steps           Plan steps.
     * @param array<string, bool>              $createdAppSlugs Slugs created earlier in this same plan.
     * @param string                           $userId          Acting user's UID.
     *
     * @return void
     *
     * @throws CopilotException (404/422/403).
     */
    private function assertExecuteRbac(array $steps, array $createdAppSlugs, string $userId): void
    {
        $checked = [];
        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            if (in_array(needle: $tool, haystack: self::EXISTING_APP_WRITE_TOOLS, strict: true) === false) {
                continue;
            }

            $args       = (array) ($step['arguments'] ?? []);
            $targetSlug = (string) ($args['appSlug'] ?? '');
            if ($targetSlug === '' || isset($createdAppSlugs[$targetSlug]) === true || isset($checked[$targetSlug]) === true) {
                continue;
            }

            $app = $this->requireExistingVirtualApp(appSlug: $targetSlug);
            $this->assertWriteRoleOnApp(app: $app, userId: $userId);
            $checked[$targetSlug] = true;
        }//end foreach
    }//end assertExecuteRbac()

    /**
     * Snapshot the current manifest of every EXISTING (appSlug, versionSlug) pair
     * a manifest-mutating step touches, so a failed execute can restore them.
     *
     * @param array<int, array<string, mixed>> $steps           Plan steps.
     * @param array<string, bool>              $createdAppSlugs Slugs created earlier in this same plan (nothing to snapshot).
     *
     * @return array<int, array{appSlug: string, versionSlug: string, version: array<string, mixed>|null, manifest: array<string, mixed>|null}>
     */
    private function snapshotTouchedVersions(array $steps, array $createdAppSlugs): array
    {
        $seen      = [];
        $snapshots = [];

        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            if (in_array(needle: $tool, haystack: self::MANIFEST_MUTATING_TOOLS, strict: true) === false) {
                continue;
            }

            $args        = (array) ($step['arguments'] ?? []);
            $targetSlug  = (string) ($args['appSlug'] ?? '');
            $versionSlug = (string) ($args['versionSlug'] ?? 'development');
            $key         = $targetSlug.'@'.$versionSlug;

            if ($targetSlug === '' || isset($createdAppSlugs[$targetSlug]) === true || isset($seen[$key]) === true) {
                continue;
            }

            $seen[$key] = true;
            $version    = $this->loadRawVersion(appSlug: $targetSlug, versionSlug: $versionSlug);

            $snapshotManifest = null;
            if ($version !== null) {
                $snapshotManifest = (array) ($version['manifest'] ?? []);
            }

            $snapshots[] = [
                'appSlug'     => $targetSlug,
                'versionSlug' => $versionSlug,
                'version'     => $version,
                'manifest'    => $snapshotManifest,
            ];
        }//end foreach

        return $snapshots;
    }//end snapshotTouchedVersions()

    /**
     * Order steps so `createApp` (when present) runs first and `promoteVersion` last.
     *
     * @param array<int, array<string, mixed>> $steps Plan steps in original order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderSteps(array $steps): array
    {
        $createSteps  = [];
        $promoteSteps = [];
        $otherSteps   = [];

        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            if ($tool === 'openbuild.createApp') {
                $createSteps[] = $step;
                continue;
            }

            if ($tool === 'openbuild.promoteVersion') {
                $promoteSteps[] = $step;
                continue;
            }

            $otherSteps[] = $step;
        }

        return array_values(array_merge($createSteps, $otherSteps, $promoteSteps));
    }//end orderSteps()

    /**
     * Restore every snapshotted manifest and delete a plan-created application (best-effort).
     *
     * @param array<int, array<string, mixed>> $snapshots      Manifest snapshots, each
     *                                                         `{appSlug, versionSlug, version, manifest}`
     *                                                         (see {@see snapshotTouchedVersions()}).
     * @param string|null                      $createdAppUuid Uuid of an app created by this plan, if any.
     * @param string|null                      $createdAppSlug Slug of an app created by this plan, if any.
     *
     * @return void
     */
    private function rollback(array $snapshots, ?string $createdAppUuid, ?string $createdAppSlug): void
    {
        foreach ($snapshots as $snapshot) {
            $version = $snapshot['version'];
            if ($version === null || $snapshot['manifest'] === null) {
                continue;
            }

            try {
                $versionUuid = $this->extractUuid(item: $version);
                $payload     = $version;
                $payload['manifest'] = $snapshot['manifest'];
                unset($payload['@self'], $payload['id'], $payload['uuid']);

                $this->objectService->saveObject(
                    object: $payload,
                    register: self::REGISTER_SLUG,
                    schema: self::APPLICATION_VERSION_SCHEMA,
                    uuid: $versionUuid,
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuild Copilot: rollback failed to restore manifest for '
                        .$snapshot['appSlug'].'@'.$snapshot['versionSlug'].': '.$e->getMessage()
                );
            }//end try
        }//end foreach

        if ($createdAppUuid === null || $createdAppUuid === '') {
            return;
        }

        try {
            $this->applicationDeletionService->deleteApplication(
                appUuid: $createdAppUuid,
                appSlug: (string) $createdAppSlug,
                deleteData: false
            );
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild Copilot: rollback failed to delete created app '.$createdAppUuid.': '.$e->getMessage());
        }
    }//end rollback()

    /**
     * Coerce an OR entity, array, or generic value into an associative array.
     *
     * @param mixed $item Value to coerce.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            $serialised = $item->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return (array) $item;
    }//end toArray()

    /**
     * Extract a UUID from a normalised OR object array.
     *
     * @param array<string, mixed> $item Normalised OR object as an associative array.
     *
     * @return string
     */
    private function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;
    }//end extractUuid()
}//end class
