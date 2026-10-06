<?php

/**
 * Buildiq OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader so this app can reference
 * `OCA\OpenRegister\AppHost\…` from its own `Application::register()`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\Buildiq\AppInfo
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

namespace OCA\Buildiq\AppInfo;

/**
 * Registers OpenRegister's autoload prefix before AppHost is referenced.
 *
 * ## Why this is needed (ADR-040)
 *
 * `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks THAT sorted list registering each
 * app's autoloader and then calling `$app->register()` for one app at a
 * time. So every app's `register()` runs BEFORE the PSR-4 prefix of every
 * alphabetically-LATER app exists.
 *
 * `buildiq` sorts before `openregister`, so `OCA\OpenRegister\` is NOT
 * autoloadable inside `Application::register()` on a perfectly healthy
 * instance with OpenRegister enabled. This was measured, not theorised:
 * `Buildiq: OpenRegister AppHost\Bootstrap is not autoloadable` was logged on
 * EVERY occ call in CI (3 per E2E run, run 31081906401), while
 * `lib/AppHost/Bootstrap.php` existed and was enabled the whole time.
 *
 * Buildiq's `class_exists()` guard meant this degraded SILENTLY rather than
 * fatally: under the CLI SAPI `Bootstrap::register()` did not run, so the
 * generic dashboard / settings / preferences controllers, the observability
 * (health + metrics) controllers, the install repair steps and the
 * manifest-driven deep-link listener were absent from every occ command,
 * background job and repair step — with nothing anywhere to say so.
 *
 * Scope, honestly: in that same run `/api/health` and `/api/metrics` answered
 * 200, and those routes exist ONLY as `Bootstrap::register()` DI aliases, so
 * under the web SAPI the guard was answering true. The CLI/web divergence is
 * not explained. This prelude removes the dependence on whatever causes it.
 *
 * Lives in its own class rather than inline in `Application::register()` for
 * one reason: `Application` cannot be constructed without a Nextcloud DI
 * container, so an inline prelude is unreachable from a unit test. Here the
 * degraded-path contract — "this NEVER throws, whatever the instance looks
 * like" — is directly assertable, and it is asserted.
 *
 * @spec openspec/specs/settings-and-observability/spec.md
 */
final class OpenRegisterAutoloader {

	/**
	 * The app whose autoload prefix this prelude registers.
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The PSR-4 namespace prefix OpenRegister's `lib/` serves.
	 */
	private const OPENREGISTER_NAMESPACE = 'OCA\\OpenRegister\\';

	/**
	 * The registered loader, or null when none is on the SPL chain.
	 *
	 * @var (\Closure(string): void)|null
	 */
	private static ?\Closure $loader = null;

	/**
	 * Register OpenRegister's PSR-4 prefix on the autoloader.
	 *
	 * MUST be called before any `OCA\OpenRegister\…` reference in
	 * `Application::register()`, including a `class_exists()` probe — the probe
	 * answers FALSE, not "not yet loaded", and a FALSE is indistinguishable
	 * from OpenRegister being absent.
	 *
	 * ## Public API only (Nextcloud 35)
	 *
	 * This used to call `\OC_App::registerAutoloading()`. That is private API
	 * and Nextcloud 35 REMOVED it (it moved to the equally private
	 * `OC\App\AppManager::registerAutoloading()`). The `\Error` landed in the
	 * catch below and the AppHost wiring was skipped in silence. For an app
	 * shipping `vendor/autoload.php` — which OpenRegister does — Nextcloud's own
	 * registration reduces to a PSR-4 prefix over `lib/`, so that is exactly
	 * what is registered here, with `spl_autoload_register()` and the public
	 * `IAppManager`. Same approach as keepiq#712.
	 *
	 * OpenRegister's `vendor/autoload.php` is deliberately NOT required: that
	 * would pull its whole dependency tree into this process, where versions
	 * differing from ours would win first-come.
	 *
	 * Deliberately NOT `IAppManager::loadApp('openregister')`: that marks
	 * OpenRegister loaded and calls `Coordinator::bootApp()`, booting it before
	 * its own `register()` has run.
	 *
	 * Idempotent: a second call while registered is a no-op and does not stack
	 * another loader.
	 *
	 * @param string|null                    $appId      App id whose `lib/` serves
	 *                                                   `OCA\OpenRegister\`.
	 *                                                   Production callers pass
	 *                                                   nothing and get
	 *                                                   'openregister'. It exists
	 *                                                   so the degraded path below
	 *                                                   — the branch that must
	 *                                                   NEVER rethrow — is
	 *                                                   reachable from a test with
	 *                                                   an id that cannot resolve.
	 * @param \OCP\App\IAppManager|null $appManager Injected for tests; resolved
	 *                                                   from the server when null.
	 *
	 * @return void This never reports success or failure. The caller's own
	 *              `class_exists()` guard is the authoritative signal; a
	 *              return value here would only duplicate it.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the public
	 * service locator, and this runs at the composition root where no
	 * container is available to inject from.
	 *
	 * @spec openspec/specs/settings-and-observability/spec.md
	 */
	public static function register(?string $appId=null, ?\OCP\App\IAppManager $appManager=null): void {
		try {
			$appId      ??= self::OPENREGISTER_APP_ID;
			$appManager ??= \OCP\Server::get(\OCP\App\IAppManager::class);

			// Checked before the short-circuit: under a long-lived worker this
			// static outlives the request, and an OpenRegister disabled since
			// must not stay wired.
			if ($appManager->isEnabledForAnyone($appId) === false) {
				self::unregister();
				return;
			}

			if (self::$loader !== null) {
				return;
			}

			$path = rtrim($appManager->getAppPath($appId), '/');
			if (is_dir($path.'/lib') === false) {
				return;
			}

			self::$loader = static function (string $class) use ($path): void {
				$file = self::classFile(appPath: $path, class: $class);
				if ($file !== null && is_file($file) === true) {
					require_once $file;
				}
			};
			spl_autoload_register(self::$loader);
		} catch (\Throwable) {
			// OpenRegister absent, disabled, or the server container is not up
			// (unit tests). The caller's class_exists() guard then skips the
			// AppHost plumbing exactly as it did before. Never rethrow: an
			// exception escaping here would abort the caller's entire
			// register(), which is the exact defect this prelude prevents.
		}//end try

	}//end register()

	/**
	 * Take the loader off the SPL chain (tests, and OpenRegister disabled).
	 *
	 * @return void
	 */
	public static function unregister(): void {
		if (self::$loader !== null) {
			spl_autoload_unregister(self::$loader);
			self::$loader = null;
		}

	}//end unregister()

	/**
	 * Map an `OCA\OpenRegister\…` class name to its file under `lib/`.
	 *
	 * Returns null for any class that is not OpenRegister's, so this loader
	 * never answers for (and shadows) names another loader owns.
	 *
	 * @param string $appPath Absolute path to the openregister app, no trailing slash.
	 * @param string $class   The fully qualified class name being resolved.
	 *
	 * @return string|null The candidate file, or null when not OpenRegister's.
	 */
	public static function classFile(string $appPath, string $class): ?string {
		if (str_starts_with($class, self::OPENREGISTER_NAMESPACE) === false) {
			return null;
		}

		$relative = substr($class, strlen(self::OPENREGISTER_NAMESPACE));
		if ($relative === '') {
			return null;
		}

		return $appPath.'/lib/'.str_replace('\\', '/', $relative).'.php';

	}//end classFile()
}//end class
