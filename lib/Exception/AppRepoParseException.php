<?php

/**
 * OpenBuild AppRepoParseException
 *
 * Thrown by AppRepoParser when a GitHub app-repo file map violates a
 * conforming-repo invariant (github-app-repo-format REQ-GARF-008). Carries a
 * STABLE error code plus the offending file path so the caller can surface a
 * generic-but-actionable 4xx — the codes + paths carry no secret and no PII and
 * are ADR-005-safe to return (design.md Decision 8). The parse is strictly
 * all-or-nothing: any single violation aborts the whole parse and produces no
 * payload (the `manifest-validation-discards-backend-delta` failure mode is
 * explicitly forbidden).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenBuild\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Exception;

use RuntimeException;
use Throwable;

/**
 * Structured, per-file parse failure for the GitHub app-repo format.
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */
final class AppRepoParseException extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param string         $errorCode Stable failure code (design.md Decision 4 taxonomy).
     * @param string         $message   Human-readable, PII-free diagnostic.
     * @param string|null    $filePath  The offending repo-relative file path, when applicable.
     * @param Throwable|null $previous  Wrapped causal exception (never surfaced to the caller).
     *
     * @return void
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly ?string $filePath=null,
        ?Throwable $previous=null,
    ) {
        parent::__construct(message: $message, code: 0, previous: $previous);
    }//end __construct()

    /**
     * The stable failure code (e.g. `descriptor_missing`, `manifest_invalid`).
     *
     * @return string
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }//end getErrorCode()

    /**
     * The offending repo-relative file path, when the failure names one.
     *
     * @return string|null
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }//end getFilePath()

    /**
     * A caller-safe structured payload (code + path + message; no secret, no PII).
     *
     * @return array{error:string,code:string,file:string|null,detail:string}
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    public function toArray(): array
    {
        return [
            'error'  => 'repo_parse_failed',
            'code'   => $this->errorCode,
            'file'   => $this->filePath,
            'detail' => $this->getMessage(),
        ];
    }//end toArray()
}//end class
