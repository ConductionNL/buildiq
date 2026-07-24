<?php

/**
 * OpenBuild CopilotController
 *
 * REST surface for the AI copilot / prompt-to-app flow (spec `ai-copilot`),
 * extended with optional agent-scoping (spec `agent-workspace`). Four
 * endpoints, all `#[NoAdminRequired]`:
 *
 *   GET  /api/copilot/health  — provider availability probe (REQ-OBAIC-001).
 *   POST /api/copilot/plan    — brief -> validated plan, zero writes (REQ-OBAIC-002/003).
 *   POST /api/copilot/execute — approved plan -> atomic execution (REQ-OBAIC-004/005).
 *   POST /api/copilot/discard — log a discarded proposal's AgentRun (agent-workspace only).
 *
 * `plan`/`execute` additionally accept an optional `agentId` — when present,
 * `CopilotService` resolves the `Agent` server-side and narrows the
 * effective tool allow-list / prefixes its instructions (agent-workspace
 * design.md Decision 1). `discard` exists ONLY for the agent-scoped chat
 * surface — the bare copilot panel never calls it (no request is sent on a
 * bare-copilot discard, unchanged from before this change).
 *
 * Per-object authorization (existing-app RBAC, hybrid-app rejection, plan
 * validation, agent resolution) happens inside CopilotService — this
 * controller only handles auth-session presence, request-payload
 * extraction, and exception-to-HTTP mapping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
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

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Exception\CopilotException;
use OCA\OpenBuild\Service\CopilotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing the copilot health/plan/execute endpoints.
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
class CopilotController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request        The current HTTP request.
     * @param LoggerInterface $logger         PSR logger for diagnostics.
     * @param CopilotService  $copilotService Copilot orchestrator.
     * @param IUserSession    $userSession    Current Nextcloud user session.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly CopilotService $copilotService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/copilot/health — provider availability probe.
     *
     * @return JSONResponse 200 `{status: "ok"}` when available, 503 `{status, reason}` otherwise.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     */
    #[NoAdminRequired]
    public function health(): JSONResponse
    {
        $health = $this->copilotService->health();
        if ($health['available'] === true) {
            return new JSONResponse(data: ['status' => 'ok'], statusCode: Http::STATUS_OK);
        }

        return new JSONResponse(
            data: ['status' => 'unavailable', 'reason' => (string) ($health['reason'] ?? 'unsupported_server')],
            statusCode: Http::STATUS_SERVICE_UNAVAILABLE
        );
    }//end health()

    /**
     * POST /api/copilot/plan — turn a brief into a validated, reviewable plan.
     *
     * Body: `{ brief: string, appSlug?: string, agentId?: string }`. Performs
     * zero builder writes (an agent-scoped rejected plan still writes one
     * `AgentRun` audit record — see `CopilotService::plan()`).
     *
     * @return JSONResponse 200 `{summary, steps, manifests}`, or an error envelope.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     * @spec openspec/changes/agent-workspace/specs/ai-copilot/spec.md
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 3600)]
    public function plan(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $brief   = (string) $this->request->getParam('brief', '');
        $appSlug = $this->resolveOptionalString(raw: $this->request->getParam('appSlug', null));
        $agentId = $this->resolveOptionalString(raw: $this->request->getParam('agentId', null));

        try {
            $result = $this->copilotService->plan(brief: $brief, appSlug: $appSlug, userId: $user->getUID(), agentId: $agentId);
            return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
        } catch (CopilotException $e) {
            return $this->mapExceptionToResponse(error: $e);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild Copilot: plan() unhandled exception: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to generate a plan. See server logs for details.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end plan()

    /**
     * POST /api/copilot/execute — execute a reviewed plan atomically.
     *
     * Body: `{ summary: string, steps: array, agentId?: string, prompt?: string }`
     * — the plan echoed back verbatim. `agentId`/`prompt` are only meaningful
     * together (the original brief for this turn, needed to write a
     * complete `AgentRun` record).
     *
     * @return JSONResponse 200 `{results}`, or an error envelope.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     * @spec openspec/changes/agent-workspace/specs/ai-copilot/spec.md
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 3600)]
    public function execute(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $plan    = $this->collectPlanPayload();
        $agentId = $this->resolveOptionalString(raw: $this->request->getParam('agentId', null));
        $prompt  = (string) $this->request->getParam('prompt', '');

        try {
            $result = $this->copilotService->execute(plan: $plan, userId: $user->getUID(), agentId: $agentId, prompt: $prompt);
            return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
        } catch (CopilotException $e) {
            return $this->mapExceptionToResponse(error: $e);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild Copilot: execute() unhandled exception: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to execute the plan. See server logs for details.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end execute()

    /**
     * POST /api/copilot/discard — log a discarded agent-chat proposal.
     *
     * Body: `{ agentId: string, prompt: string, summary: string, steps: array }`.
     * Exists only for the agent-scoped chat surface (agent-workspace spec "A
     * discarded proposal is still logged") — the bare copilot panel never
     * calls this endpoint, so a bare-copilot discard remains request-free
     * exactly as before this change.
     *
     * @return JSONResponse 200 `{status: "logged"}`, or an error envelope.
     *
     * @spec openspec/changes/agent-workspace/specs/agent-workspace/spec.md
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 3600)]
    public function discard(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $agentId = (string) $this->request->getParam('agentId', '');
        if ($agentId === '') {
            return new JSONResponse(
                data: ['error' => 'invalid_arguments', 'message' => 'agentId is required.'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $prompt = (string) $this->request->getParam('prompt', '');
        $plan   = $this->collectPlanPayload();

        try {
            $this->copilotService->discard(agentId: $agentId, userId: $user->getUID(), prompt: $prompt, plan: $plan);
            return new JSONResponse(data: ['status' => 'logged'], statusCode: Http::STATUS_OK);
        } catch (CopilotException $e) {
            return $this->mapExceptionToResponse(error: $e);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild Copilot: discard() unhandled exception: '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to log the discarded run. See server logs for details.'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end discard()

    /**
     * Normalise a raw string request param (`appSlug`, `agentId`) to `null` when absent/blank.
     *
     * @param mixed $raw Raw request param value.
     *
     * @return string|null
     */
    private function resolveOptionalString(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }//end resolveOptionalString()

    /**
     * Read the `{summary, steps}` plan payload from the request body.
     *
     * @return array<string, mixed>
     */
    private function collectPlanPayload(): array
    {
        $summary = (string) $this->request->getParam('summary', '');
        $steps   = $this->request->getParam('steps', []);
        if (is_array($steps) === false) {
            $steps = [];
        }

        return ['summary' => $summary, 'steps' => $steps];
    }//end collectPlanPayload()

    /**
     * Map a CopilotException to its JSONResponse envelope.
     *
     * @param CopilotException $error The thrown copilot exception.
     *
     * @return JSONResponse
     */
    private function mapExceptionToResponse(CopilotException $error): JSONResponse
    {
        $body = [
            'error'   => $error->getErrorCode(),
            'message' => $error->getMessage(),
        ];

        if ($error->getStepIndex() !== null) {
            $body['stepIndex'] = $error->getStepIndex();
        }

        if ($error->getContext() !== []) {
            $body += $error->getContext();
        }

        if ($error->getHttpStatus() >= Http::STATUS_INTERNAL_SERVER_ERROR) {
            $this->logger->error('OpenBuild Copilot: '.$error->getErrorCode().': '.$error->getMessage(), ['exception' => $error]);
        }

        return new JSONResponse(data: $body, statusCode: $error->getHttpStatus());
    }//end mapExceptionToResponse()
}//end class
