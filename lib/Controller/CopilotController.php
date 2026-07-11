<?php

/**
 * OpenBuild CopilotController
 *
 * REST surface for the AI copilot / prompt-to-app flow (spec `ai-copilot`).
 * Three endpoints, all `#[NoAdminRequired]`:
 *
 *   GET  /api/copilot/health  — provider availability probe (REQ-OBAIC-001).
 *   POST /api/copilot/plan    — brief -> validated plan, zero writes (REQ-OBAIC-002/003).
 *   POST /api/copilot/execute — approved plan -> atomic execution (REQ-OBAIC-004/005).
 *
 * Per-object authorization (existing-app RBAC, hybrid-app rejection, plan
 * validation) happens inside CopilotService — this controller only handles
 * auth-session presence, request-payload extraction, and exception-to-HTTP
 * mapping.
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
     * Body: `{ brief: string, appSlug?: string }`. Performs zero writes.
     *
     * @return JSONResponse 200 `{summary, steps, manifests}`, or an error envelope.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
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
        $appSlug = $this->resolveOptionalAppSlug(raw: $this->request->getParam('appSlug', null));

        try {
            $result = $this->copilotService->plan(brief: $brief, appSlug: $appSlug, userId: $user->getUID());
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
     * Body: `{ summary: string, steps: array }` — the plan echoed back verbatim.
     *
     * @return JSONResponse 200 `{results}`, or an error envelope.
     *
     * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 3600)]
    public function execute(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $plan = $this->collectPlanPayload();

        try {
            $result = $this->copilotService->execute(plan: $plan, userId: $user->getUID());
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
     * Normalise the raw `appSlug` request param to `null` when absent/blank.
     *
     * @param mixed $raw Raw request param value.
     *
     * @return string|null
     */
    private function resolveOptionalAppSlug(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (string) $raw;
    }//end resolveOptionalAppSlug()

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
