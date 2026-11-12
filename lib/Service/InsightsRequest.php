<?php

/**
 * Buildiq InsightsRequest
 *
 * Which Application version an insights payload is asked for, over which window.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * An insights request: Application, version and window.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
 */
final class InsightsRequest {

	/**
	 * Constructor.
	 *
	 * @param string $appUuid     Application UUID.
	 * @param string $versionUuid ApplicationVersion UUID.
	 * @param string $window      Window string — one of `7d`, `30d`, `90d`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $appUuid,
		public readonly string $versionUuid,
		public readonly string $window,
	) {
	}//end __construct()

	/**
	 * The distributed-cache key of this request's payload.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
	 */
	public function cacheKey(): string {
		return sprintf('payload_%s_%s_%s', $this->appUuid, $this->versionUuid, $this->window);
	}//end cacheKey()
}//end class
