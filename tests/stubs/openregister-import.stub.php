<?php

/**
 * Minimal stubs for OpenRegister's object import contract.
 *
 * Mirrors openregister/lib/Kernel/Contracts/ObjectImporterInterface.php and
 * lib/Kernel/Contracts/Value/Import{Request,Scope,Options,Mapping}.php, so the
 * records import of an app archive can be unit-tested out of container. Every
 * declaration is guarded, so this file is a no-op when the real OpenRegister is
 * on the autoload path.
 *
 * Keep the signatures in step with the real contract: a stub that drifts lets
 * a caller compile here and fatal in production.
 *
 * @category Stub
 * @package  OCA\Buildiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value {

	use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
	use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
	use OCP\IUser;

	if (class_exists(ImportScope::class) === false) {
		/** Where an import goes and as whom. */
		final class ImportScope {
			public function __construct(
				public readonly ?RegisterInterface $register = null,
				public readonly ?SchemaInterface $schema = null,
				public readonly ?IUser $currentUser = null,
				public readonly ?string $registerSlug = null,
			) {
			}
		}
	}

	if (class_exists(ImportOptions::class) === false) {
		/** How an import saves. */
		final class ImportOptions {
			public function __construct(
				public readonly bool $validation = false,
				public readonly bool $events = false,
				public readonly bool $enrich = true,
				public readonly bool $publish = false,
			) {
			}
		}
	}

	if (class_exists(ImportMapping::class) === false) {
		/** An optional migration pack, or a dry run. */
		final class ImportMapping {
			public function __construct(
				public readonly ?array $pack = null,
				public readonly bool $dryRun = false,
			) {
			}
		}
	}

	if (class_exists(ImportRequest::class) === false) {
		/** One import: the file and its scope, options and mapping. */
		final class ImportRequest {
			public function __construct(
				public readonly string $filePath,
				public readonly ImportScope $scope = new ImportScope(),
				public readonly ImportOptions $options = new ImportOptions(),
				public readonly ImportMapping $mapping = new ImportMapping(),
			) {
			}
		}
	}
}

namespace OCA\OpenRegister\Kernel\Contracts {

	use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;

	if (interface_exists(ObjectImporterInterface::class) === false) {
		/** OpenRegister's register import. */
		interface ObjectImporterInterface {
			public function importFromCsv(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array;

			public function importFromExcel(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array;

			public function importFromJson(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array;

			public function serializeErrorsToCsv(array $summary): string;

			public function softDeleteByImportJobId(string $importJobId): array;
		}
	}
}
