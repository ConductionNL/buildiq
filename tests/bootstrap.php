<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

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
        $path     = $ocpStubs . '/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    });
}

// Bootstrap Nextcloud if available. Inside the docker container we'll get
// the full NC runtime; outside (CI / local dev) we fall back to the
// vendor/nextcloud/ocp stubs and run only the pure-unit subset.
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
            \OC_App::loadApp('openbuilt');
        }

        if (class_exists(\OC_Hook::class)) {
            \OC_Hook::clear();
        }
    }
}
