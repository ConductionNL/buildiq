<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader and pin OCP/NCU PSR-4 prefixes onto it
// so unit tests can resolve interfaces like OCP\IRequest, OCP\AppFramework\Http,
// etc. from the nextcloud/ocp composer package — without booting Nextcloud.
// This mirrors phpstan-bootstrap.php; the unit suite intentionally stays
// out-of-container (integration tests live elsewhere).
/** @var \Composer\Autoload\ClassLoader $autoloader */
$autoloader = require __DIR__ . '/../vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');

// Re-pin the OpenBuilt PSR-4 prefix to the LOCAL lib/. The vendor/ dir
// may be symlinked from a sibling checkout (e.g. running in a git worktree)
// where the optimized classmap points at a stale baseDir. We rebuild the
// classmap entries for every OCA\OpenBuilt class so they resolve against
// the worktree-local lib/ directory.
$openBuiltLib = realpath(__DIR__ . '/../lib');
$autoloader->setPsr4('OCA\\OpenBuilt\\', [$openBuiltLib]);
$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($openBuiltLib));
$classMap = [];
foreach ($rii as $file) {
    if ($file->isFile() === false || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($openBuiltLib) + 1);
    $classMap['OCA\\OpenBuilt\\' . str_replace(['/', '.php'], ['\\', ''], $relative)] = $file->getPathname();
}
$autoloader->addClassMap($classMap);

// OpenRegister types are referenced by hard-typed constructor params on
// our controllers/services. The autoload psr-4 prefix is registered so
// the typehint reflection resolves; the actual implementations are
// replaced with PHPUnit mocks in each test.
$orCandidates = [
    __DIR__ . '/../../openregister/lib',                                                            // git-worktree sibling
    dirname(realpath(__DIR__ . '/../vendor'), levels: 1) . '/../openregister/lib',                  // symlinked vendor → apps-extra/openregister/lib
    '/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib',           // dev fallback
];
foreach ($orCandidates as $orPath) {
    if (is_dir($orPath) === true) {
        $autoloader->addPsr4('OCA\\OpenRegister\\', realpath($orPath));
        break;
    }
}

// Final fallback — if OpenRegister sources aren't present at all,
// register a stub set so type-hinted parameters resolve.
require_once __DIR__ . '/stubs/openregister-stubs.php';

// OpenRegister's IMcpToolProvider interface ships in PR #1466 (ai-chat
// companion orchestrator). Until that merges, the interface may not be
// loadable in unit-test isolation — load the stub so `implements
// IMcpToolProvider` doesn't blow up at class-load time.
require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';

// If the surrounding Nextcloud server is present (we're running inside
// the docker container), boot it so functional tests can talk to OC.
// In a stripped CI environment we skip — unit tests don't need it.
if (file_exists(__DIR__ . '/../../../lib/base.php') === true
    && getenv('OPENBUILT_SKIP_NC_BOOTSTRAP') === false
) {
    require_once __DIR__ . '/../../../lib/base.php';

    // Register Test\ namespace for NC test classes.
    $serverTestsLib = __DIR__ . '/../../../tests/lib/';
    if (is_dir($serverTestsLib) === true) {
        $autoloader->addPsr4('Test\\', $serverTestsLib);
    }
}
