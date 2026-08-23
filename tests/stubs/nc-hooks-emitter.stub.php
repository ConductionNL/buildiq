<?php

/**
 * Nextcloud core `OC\Hooks\Emitter` stub.
 *
 * `OCP\Files\IRootFolder` (public API, `nextcloud/ocp`) extends BOTH
 * `OCP\Files\Folder` and `OC\Hooks\Emitter` — the latter is Nextcloud SERVER
 * CORE, not part of the `nextcloud/ocp` composer stub package, so it is
 * unresolvable when the unit suite runs out-of-container (or in-container
 * without the full NC server tree mounted, as `phpunit-unit.xml` does per
 * this repo's documented local-check recipe). Without this stub,
 * `$this->createMock(IRootFolder::class)` (needed by
 * `DocumentGenerationServiceTest` / `GeneratedDocumentControllerTest`,
 * automation-document-action) fails with "Interface OC\Hooks\Emitter not
 * found" — PHPUnit's mock generator must resolve the FULL interface
 * hierarchy, not just the directly-referenced type.
 *
 * Guarded with `interface_exists(..., autoload: false)` so this is a no-op
 * when the real Nextcloud server IS present (in-container run with
 * `lib/base.php` booted — see `tests/bootstrap-unit.php`).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-document-action/tasks.md#6.1
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OC\Hooks {
	if (interface_exists(\OC\Hooks\Emitter::class, false) === false) {
		/**
		 * Minimal shape mirror of Nextcloud core's `OC\Hooks\Emitter` — only
		 * what `IRootFolder`'s `extends` clause needs to resolve for
		 * PHPUnit's mock generator; no test in this suite calls these
		 * methods directly.
		 */
		interface Emitter {
			/**
			 * @param string $scope Hook scope.
			 * @param string $method Hook method name.
			 * @param callable $callback Listener callback.
			 *
			 * @return void
			 */
			public function listen($scope, $method, callable $callback);

			/**
			 * @param string $scope Hook scope.
			 * @param string $method Hook method name.
			 * @param callable|null $callback Listener callback, or null to remove all.
			 *
			 * @return void
			 */
			public function removeListener($scope = null, $method = null, ?callable $callback = null);
		}
	}
}//end namespace OC\Hooks
