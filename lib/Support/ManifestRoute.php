<?php

/**
 * ManifestRoute — one place that says what a manifest route looks like.
 *
 * `UpsertPageHandler` and `UpsertMenuItemHandler` each carried a private copy
 * of the same route guard: a route must start with `/` and hold only path-safe
 * characters (issue #167, the route-injection guard). The tool catalogue that
 * the copilot shows the model declared `route` as nothing more than
 * `{type: string, minLength: 1, maxLength: 200}`, and the `upsertMenuItem`
 * description asked for a route that "should match a page id". A model that
 * followed that description wrote `"route": "overview"`, the plan validator
 * had no pattern to measure it against, the review screen enabled
 * "Confirm & create", and the handler then refused the write — so the whole
 * plan rolled back on the one click the reader had been told was safe.
 *
 * A page and a menu item do not mean the same thing by "route", and that is
 * where the bug lived:
 *
 *  - a PAGE's route is a path, so {@see normalise()} roots a bare one and
 *    {@see isValid()} is the guard;
 *  - a MENU ITEM's route is the NAME of the route to go to, and the runtime
 *    names every route after its page id (`src/services/manifestRouting.js`;
 *    the wizard's own seed manifest targets `Dashboard` and `MessagesIndex`
 *    that way). A path works too, because that runtime repoints a menu entry
 *    naming a page's path onto that page's id. {@see isValidMenuTarget()}
 *    accepts both and rewrites neither.
 *
 * Rooting a menu target would have been worse than refusing it: measured on
 * the live instance, the plan's `borrow-tool` item named a page whose route is
 * `/loans/new`, so `/borrow-tool` matched nothing and the entry vanished from
 * the navigation, while the bare `borrow-tool` is that page's id and resolves.
 *
 * WHAT NORMALISING DELIBERATELY DOES NOT DO. It never rescues a route that
 * names somewhere else. A value with a colon before its first slash is a
 * scheme (`javascript:alert(1)`, `https://example.org`), and a value starting
 * with `//` is protocol-relative; prefixing either would turn an input the
 * guard exists to refuse into one it accepts. Those are handed back untouched
 * and both guards reject them, which keeps the ai-copilot spec's "Execution
 * reuses the handlers, not a copy" scenario true.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Normalises and validates a manifest route.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
 */
final class ManifestRoute {

	/**
	 * Longest route the manifest accepts, in bytes.
	 *
	 * @var int
	 */
	private const MAX_LENGTH = 256;

	/**
	 * Turn a bare page id into the path it meant.
	 *
	 * `overview` becomes `/overview`, `tools/:id` becomes `/tools/:id`. A route
	 * that already starts with `/` is returned unchanged, and so is anything
	 * that names a scheme or a host: see the class docblock for why.
	 *
	 * @param string $route The route as the caller wrote it.
	 *
	 * @return string The route, rooted at `/` when that was safe to do.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function normalise(string $route): string {
		$trimmed = trim($route);
		if ($trimmed === '' || str_starts_with($trimmed, '/') === true) {
			return $trimmed;
		}

		if (self::namesAScheme(route: $trimmed) === true) {
			return $trimmed;
		}

		return '/' . $trimmed;
	}//end normalise()

	/**
	 * Whether a menu item's target is one the manifest may store.
	 *
	 * A menu item does not carry a path: it carries the NAME of the route the
	 * shell should go to, and the runtime names every route after its page id
	 * (see `src/services/manifestRouting.js`, and the wizard's own seed
	 * manifest, whose two menu items target `Dashboard` and `MessagesIndex`).
	 * A path is accepted too, because that same runtime repoints a menu entry
	 * naming a page's path onto that page's id.
	 *
	 * So both shapes are valid here and neither is rewritten. The guard that
	 * demanded a leading `/` was the page rule copied onto the menu, and it
	 * refused exactly the shape this tool's own description asked for.
	 *
	 * @param string $route The candidate menu target.
	 *
	 * @return bool True when the target is safe to store.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function isValidMenuTarget(string $route): bool {
		if (self::isValid(route: $route) === true) {
			return true;
		}

		if (strlen($route) > self::MAX_LENGTH) {
			return false;
		}

		// A bare route name: a page id. No slash, no colon, so no scheme and
		// no host can be spelt this way.
		return (bool)preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_\-\.]*$#', $route);
	}//end isValidMenuTarget()

	/**
	 * Whether a page's route is one the manifest may store.
	 *
	 * Accepts paths that start with `/` and consist only of alphanumeric
	 * characters, hyphens, underscores, dots, forward slashes, and route
	 * parameter placeholders (`:param` or `{param}`). Rejects `javascript:`
	 * URIs, protocol-relative paths, and other injection vectors (issue #167).
	 *
	 * @param string $route The candidate route string.
	 *
	 * @return bool True when the route is safe to store.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function isValid(string $route): bool {
		if (strlen($route) > self::MAX_LENGTH) {
			return false;
		}

		// Require the character after the leading '/' to be non-slash so that
		// protocol-relative URLs (//host/path) are rejected.
		return (bool)preg_match('#^/([a-zA-Z0-9_\-\.:\{][a-zA-Z0-9/_\-\.:\{\}]*)?$#', $route);
	}//end isValid()

	/**
	 * Whether the value looks like it names a scheme rather than a path.
	 *
	 * A colon before the first slash is what separates `javascript:alert(1)`
	 * and `https://example.org` from `tools/:id`, where the colon introduces a
	 * route parameter inside a path segment.
	 *
	 * @param string $route A route with no leading slash.
	 *
	 * @return bool
	 */
	private static function namesAScheme(string $route): bool {
		$colon = strpos($route, ':');
		if ($colon === false) {
			return false;
		}

		$slash = strpos($route, '/');
		if ($slash === false) {
			return true;
		}

		return $colon < $slash;
	}//end namesAScheme()
}//end class
