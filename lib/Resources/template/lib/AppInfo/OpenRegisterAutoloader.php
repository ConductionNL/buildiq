<?php

/**
 * AppTemplate OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader so this app can reference
 * `OCA\OpenRegister\…` classes from its own `Application::register()`.
 *
 * @category AppInfo
 * @package  OCA\AppTemplate\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppTemplate\AppInfo;

/**
 * Registers OpenRegister's autoload prefix before any of its classes is used.
 *
 * ## Why this is needed (ADR-040)
 *
 * `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks THAT sorted list registering one app's
 * autoloader and then calling `$app->register()`, one app at a time. So every
 * app's `register()` runs BEFORE the PSR-4 prefix of every alphabetically-LATER
 * app exists.
 *
 * Most app ids sort before `openregister`, so `OCA\OpenRegister\` is NOT
 * autoloadable inside `Application::register()` on a perfectly healthy
 * instance with OpenRegister enabled. A `::class` constant does not autoload
 * and is safe; anything that does (`class_exists()`, `instanceof` against a
 * loaded object, a static call such as AppHost's `Bootstrap::register()`)
 * throws or answers FALSE. An uncaught `\Error` aborts the whole of
 * `register()` while `Coordinator` logs an `emergency` and carries on, so the
 * app stays enabled and looks fine.
 *
 * Apps generated from this template: call `OpenRegisterAutoloader::register()`
 * first thing in `Application::register()` (as the template does) and keep
 * every OpenRegister reference after it behind a `class_exists()` guard.
 *
 * Lives in its own class rather than inline in `Application::register()` for
 * one reason: `Application` cannot be constructed without a Nextcloud DI
 * container, so an inline prelude is unreachable from a unit test. Here the
 * degraded-path contract — "this NEVER throws, whatever the instance looks
 * like" — is directly assertable, and it is asserted.
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
	 * Earlier fleet copies of this prelude called `\OC_App::registerAutoloading()`.
	 * That is private API and Nextcloud 35 REMOVED it (it moved to the equally
	 * private `OC\App\AppManager::registerAutoloading()`), so the call threw,
	 * the catch below returned false and the caller's OpenRegister wiring was
	 * skipped in silence. Do not reintroduce it. For an app shipping `vendor/autoload.php` — which OpenRegister
	 * does — Nextcloud's own registration reduces to a PSR-4 prefix over `lib/`,
	 * so that is exactly what is registered here, with `spl_autoload_register()`
	 * and the public `IAppManager`. Same approach as keepiq#712.
	 *
	 * OpenRegister's `vendor/autoload.php` is deliberately NOT required: that
	 * would pull its whole dependency tree into this process, where versions
	 * differing from ours would win first-come.
	 *
	 * Deliberately NOT `IAppManager::loadApp('openregister')`: that marks
	 * OpenRegister loaded and calls `Coordinator::bootApp()`, booting it before
	 * its own `register()` has run.
	 *
	 * Idempotent: a second call while registered is a no-op.
	 *
	 * @param \OCP\App\IAppManager|null $appManager Injected for tests; resolved
	 *                                              from the server when null.
	 *
	 * @return bool True when the prefix is registered, false when OpenRegister
	 *              is absent, disabled, or otherwise unresolvable — in which
	 *              case the caller MUST fall through to its degraded path.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the public
	 * service locator, and this runs at the composition root where no
	 * container is available to inject from.
	 */
	public static function register(?\OCP\App\IAppManager $appManager=null): bool {
		try {
			$appManager ??= \OCP\Server::get(\OCP\App\IAppManager::class);

			// Checked before the short-circuit: under a long-lived worker this
			// static outlives the request, and an OpenRegister disabled since
			// must not stay wired.
			if ($appManager->isEnabledForAnyone(self::OPENREGISTER_APP_ID) === false) {
				self::unregister();
				return false;
			}

			if (self::$loader !== null) {
				return true;
			}

			$path = rtrim($appManager->getAppPath(self::OPENREGISTER_APP_ID), '/');
			if (is_dir($path.'/lib') === false) {
				return false;
			}

			self::$loader = static function (string $class) use ($path): void {
				$file = self::classFile(appPath: $path, class: $class);
				if ($file !== null && is_file($file) === true) {
					require_once $file;
				}
			};
			spl_autoload_register(self::$loader);

			return true;
		} catch (\Throwable) {
			// OpenRegister absent, or the server container is not up (unit
			// tests). The caller's class_exists() guard then skips the AppHost
			// plumbing. Never rethrow: an exception escaping here would abort
			// the caller's entire register(), which is the exact defect this
			// prelude exists to prevent.
			return false;
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
