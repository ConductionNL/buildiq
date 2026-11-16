<?php

/**
 * ManifestPageShape — bring a tool-authored page up to what the validator wants.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `upsertPage` takes a free-form `config` object and stores it verbatim, which
 * is fine until the page type has fields the canonical manifest validator
 * insists on. Two shapes turned up on the first real plan of the day, from a
 * model that had followed the tool schema exactly:
 *
 *  - a `form` page whose `config.fields` was `["tool", "member", "dueOn"]`,
 *    a list of names, where the validator wants `{key, label, type}` objects,
 *    and which declared neither `submitHandler` nor `submitEndpoint`, so the
 *    form had nowhere to post;
 *  - a `dashboard` page whose `config.layout` was the string `"grid"`, where
 *    the validator wants an array of placements.
 *
 * Each of those makes the WHOLE manifest invalid, which in the copilot wizard
 * means Confirm and create stays disabled and the reader is told to rephrase
 * their brief. The tool descriptions now spell the requirements out, but a
 * description is a hope and this is a guarantee: whatever the model sends, the
 * page that comes out of here is one the validator accepts.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It never invents content. A field list
 * of names keeps those names; a title is derived from the name it was given;
 * a submit endpoint is only written when the page already says which register
 * and schema it belongs to. Where intent is genuinely missing, the page stays
 * as the model wrote it and the validator's message reaches the reader, which
 * is the honest outcome.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Normalises a page written by the builder tools.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */
final class ManifestPageShape {

	/**
	 * Normalise one page in place of the shape the tool was handed.
	 *
	 * @param array<string, mixed> $page The page as the tool arguments describe it.
	 *
	 * @return array<string, mixed> The same page, in a shape the validator accepts.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function normalise(array $page): array {
		$config = $page['config'] ?? [];
		if (is_array($config) === false) {
			return $page;
		}

		$type = (string)($page['type'] ?? '');
		if ($type === 'form') {
			$config = FormPageConfig::normalise(config: $config);
		}

		if ($type === 'dashboard') {
			$config = self::normaliseDashboard(config: $config);
		}

		$page['config'] = $config;

		return $page;
	}//end normalise()

	/**
	 * Drop a dashboard's `widgets` or `layout` when it is not a list.
	 *
	 * A model that writes `"layout": "grid"` is naming a style, not a set of
	 * placements. The key carries no information the renderer can use and it
	 * fails the whole manifest, so it goes; `addWidget` builds the real one.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 */
	private static function normaliseDashboard(array $config): array {
		foreach (['widgets', 'layout'] as $key) {
			if (array_key_exists($key, $config) === true && is_array($config[$key]) === false) {
				unset($config[$key]);
			}
		}

		return $config;
	}//end normaliseDashboard()
}//end class
