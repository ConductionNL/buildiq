<?php

/**
 * OpenBuilt WizardCreationException
 *
 * Thrown by ApplicationCreationService when the atomic creation flow fails
 * at any step (validation, Application/ApplicationVersion create, register
 * provisioning, chain wiring, productionVersion set).
 *
 * The exception carries the step name, rollback status, and any orphaned
 * resources that could not be cleaned up during rollback — enough for the
 * controller to build the spec-defined 500/422 error envelope (REQ-OBWIZ-007).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenBuilt\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Exception;

use RuntimeException;
use Throwable;

/**
 * Wizard-creation failure (REQ-OBWIZ-007).
 *
 * Carries enough metadata for the controller to return the spec-defined
 * JSON body without parsing the message string.
 */
class WizardCreationException extends RuntimeException
{

    /**
     * Machine-readable error code.
     *
     * `validation_error` — payload failed server-side validation (→ 422).
     * `wizard_rollback`  — creation failed mid-flight (→ 500).
     *
     * @var string
     */
    private string $errorCode;

    /**
     * The creation step that failed (e.g. `validate`, `create-application`,
     * `create-version-development`, `register-provision-staging`, etc.).
     *
     * @var string
     */
    private string $failedAtStep;

    /**
     * `complete` — rollback succeeded for all created resources.
     * `partial`  — one or more resources could not be deleted during rollback.
     * `none`     — no rollback was needed (validation-only failure).
     *
     * @var string
     */
    private string $rollbackStatus;

    /**
     * Resources that could not be cleaned up during a partial rollback.
     * Each entry is a string identifying the register name or object UUID.
     *
     * @var array<int,string>
     */
    private array $orphanedResources;

    /**
     * Constructor.
     *
     * @param string            $errorCode         Machine-readable error code
     * @param string            $failedAtStep      Step identifier where failure occurred
     * @param string            $message           Human-readable diagnostic
     * @param string            $rollbackStatus    `complete`, `partial`, or `none`
     * @param array<int,string> $orphanedResources Resources that could not be cleaned
     * @param Throwable|null    $previous          Wrapped causal exception
     *
     * @return void
     */
    public function __construct(
        string $errorCode,
        string $failedAtStep,
        string $message='',
        string $rollbackStatus='none',
        array $orphanedResources=[],
        ?Throwable $previous=null,
    ) {
        parent::__construct(message: $message, code: 0, previous: $previous);
        $this->errorCode         = $errorCode;
        $this->failedAtStep      = $failedAtStep;
        $this->rollbackStatus    = $rollbackStatus;
        $this->orphanedResources = $orphanedResources;
    }//end __construct()

    /**
     * Get the machine-readable error code.
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }//end getErrorCode()

    /**
     * Get the step at which the creation failure occurred.
     *
     * @return string
     */
    public function getFailedAtStep(): string
    {
        return $this->failedAtStep;
    }//end getFailedAtStep()

    /**
     * Get the rollback completion status.
     *
     * @return string `complete`, `partial`, or `none`
     */
    public function getRollbackStatus(): string
    {
        return $this->rollbackStatus;
    }//end getRollbackStatus()

    /**
     * Get the list of resources that could not be cleaned up during rollback.
     *
     * @return array<int,string>
     */
    public function getOrphanedResources(): array
    {
        return $this->orphanedResources;
    }//end getOrphanedResources()
}//end class
