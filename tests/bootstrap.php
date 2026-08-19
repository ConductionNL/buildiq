<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

// `OCP\Files\IRootFolder` extends BOTH `OCP\Files\Folder` and Nextcloud CORE's
// `OC\Hooks\Emitter`, which is not part of the `nextcloud/ocp` package. Loading
// IRootFolder without it fatals mid-autoload, so the interface never becomes
// defined and every `createMock(IRootFolder::class)` fails with a misleading
// "Class or interface OCP\Files\IRootFolder does not exist" — 8 of this suite's
// errors, from one missing symbol.
//
// The stub already existed and was wired into `bootstrap-unit.php`, but
// `phpunit.xml` boots THIS file, so it never loaded here. It must come before
// the OCP resolver below can be triggered, and it is `interface_exists`-guarded
// so a real in-container Nextcloud still wins.
require_once __DIR__ . '/stubs/nc-hooks-emitter.stub.php';

// vendor/nextcloud/ocp doesn't ship an autoload entry — it's intended as
// a PHPStan scan-only dependency. For unit tests outside the docker
// container we want OCP\* stubs loadable so MockBuilder can resolve them.
// Register a PSR-4 path resolver for the OCP namespace pointing at the
// stubs.
$ocpStubs = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (is_dir($ocpStubs)) {
	spl_autoload_register(static function (string $class) use ($ocpStubs): void {
		if (str_starts_with($class, 'OCP\\') === false) {
			return;
		}

		$relative = substr($class, strlen('OCP\\'));
		$path = $ocpStubs . '/' . str_replace('\\', '/', $relative) . '.php';
		if (file_exists($path)) {
			require_once $path;
		}
	});
}

// Bootstrap Nextcloud if available. Inside the docker container we'll get
// the full NC runtime — including the REAL OpenRegister classes, loaded via
// OC_App::loadApps() below; outside (CI / local dev) we fall back to the
// vendor/nextcloud/ocp stubs and run only the pure-unit subset.
//
// MUST run before the OpenRegister stub require below: `OC_App::loadApps()`
// is what makes `OCA\OpenRegister\Contract\ObjectEntityInterface` (and every
// other real OR type) resolvable via NC's own autoloader. The stub file's
// `class_exists(ObjectEntity::class, autoload: false)` guard only checks
// whether ITS OWN stub class already exists — it does not, and cannot, wait
// for classes it has not tried to load yet — so if the stub file runs FIRST,
// its `implements \OCA\OpenRegister\Contract\ObjectEntityInterface` clause
// fatals with "Interface not found" even in-container, because the real
// interface has not been autoloaded at that point. Loading NC first means
// the guard sees the REAL classes already defined and skips the stub
// entirely — exactly the in-container behaviour the guard is meant to select.
if (!defined('OC_CONSOLE')) {
	$ncBase = __DIR__ . '/../../../lib/base.php';
	if (file_exists($ncBase)) {
		require_once $ncBase;

		$ncAutoload = __DIR__ . '/../../../tests/autoload.php';
		if (file_exists($ncAutoload)) {
			require_once $ncAutoload;
		}

		if (class_exists(\OC_App::class)) {
			\OC_App::loadApps();
			\OC_App::loadApp('openbuild');
		}

		if (class_exists(\OC_Hook::class)) {
			\OC_Hook::clear();
		}
	}
}

// OpenRegister types are referenced by hard-typed constructor parameters in
// controllers, services, repair steps and listeners.  When the real
// OpenRegister sources are not on the autoload path (CI / out-of-container)
// load the minimal stub set so PHPUnit's MockBuilder can resolve the types.
// The stubs are guarded by class_exists() so they are a no-op when the real
// classes ARE available (in-container run, now bootstrapped above).
require_once __DIR__ . '/stubs/openregister-stubs.php';

// Same guard for the IMcpToolProvider interface which ships with OR but may
// not be present until OR#1466 merges.
require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
