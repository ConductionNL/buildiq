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
 * Redacts GitHub tokens and other credentials from text bound for a log or an answer.
 *
 * Covers classic (`ghp_`, `gho_`, `ghu_`, `ghs_`, `ghr_`) and fine-grained (`github_pat_`)
 * tokens in any case, `v1.` installation tokens, JWTs, credentials after an
 * `Authorization`/`token`/`Bearer`/`Basic` keyword or an `access_token=` parameter,
 * and the password part of a URL.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 */
final class TokenScrubber {
	/**
	 * The shapes of a token that stand on their own.
	 *
	 * @var string
	 */
	private const TOKEN_PATTERN = '/gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|v1\\.[0-9a-f]{40}'
		. '|eyJ[A-Za-z0-9_-]{8,}\\.[A-Za-z0-9_-]{8,}\\.[A-Za-z0-9_-]+/i';

	/**
	 * A credential after a keyword that names it; the keyword is kept.
	 *
	 * @var string
	 */
	private const LABELLED_PATTERN = '/(\\b(?:authorization:\\s*)?(?:token|bearer|basic)\\s+|access_token=)[A-Za-z0-9._~+\\/=-]{16,}/i';

	/**
	 * The password part of a URL; scheme and user are kept.
	 *
	 * @var string
	 */
	private const URL_PASSWORD_PATTERN = '#(://[^/\\s:@]+:)[^@\\s/]+@#';

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
		$scrubbed = (string)preg_replace(self::TOKEN_PATTERN, '[redacted-token]', $message);
		$scrubbed = (string)preg_replace(self::LABELLED_PATTERN, '$1[redacted-token]', $scrubbed);
		return (string)preg_replace(self::URL_PASSWORD_PATTERN, '$1[redacted-token]@', $scrubbed);
	}//end scrub()
}//end class
