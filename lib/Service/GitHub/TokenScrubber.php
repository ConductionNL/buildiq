<?php

/**
 * Buildiq Token Scrubber
 *
 * Redacts GitHub token-shaped strings from text that may reach a log line or an error
 * message. Buildiq never holds a token, so the only way one can appear is an upstream
 * response or a transport error that echoes it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\GitHub
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

namespace OCA\Buildiq\Service\GitHub;

/**
 * Redacts classic (`ghp_`, `gho_`, `ghu_`, `ghs_`, `ghr_`) and fine-grained (`github_pat_`) tokens.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 */
final class TokenScrubber {
	/**
	 * The shapes of a GitHub token.
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '/gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}/';

	/**
	 * The text with every token-shaped string replaced.
	 *
	 * @param string $message Raw text.
	 *
	 * @return string Scrubbed text.
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public static function scrub(string $message): string {
		return (string)preg_replace(self::TOKEN_PATTERN, '[redacted-token]', $message);
	}//end scrub()
}//end class
