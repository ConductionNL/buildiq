<?php

/**
 * Unit test for SlugSearch, the adapter over OpenRegister's two slug-search signatures.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract {
	if (class_exists(RegisterSchemaSlugs::class) === false) {
		/**
		 * Mirror of OpenRegister's value class, for a run without OpenRegister on the path.
		 */
		final class RegisterSchemaSlugs {
			/**
			 * Constructor.
			 *
			 * @param string $register The register slug.
			 * @param string $schema   The schema slug.
			 */
			public function __construct(
				public readonly string $register,
				public readonly string $schema,
			) {
			}
		}
	}
}

namespace OCA\Buildiq\Tests\Unit\Support {
	use OCA\Buildiq\Support\SlugSearch;
	use OCA\OpenRegister\Contract\RegisterSchemaSlugs;
	use PHPUnit\Framework\Attributes\CoversClass;
	use PHPUnit\Framework\TestCase;

	/**
	 * The adapter builds the call each signature takes, and passes the answer through.
	 */
	#[CoversClass(SlugSearch::class)]
	final class SlugSearchTest extends TestCase {
		/**
		 * OpenRegister main: the register and schema go in as one target value.
		 *
		 * @return void
		 */
		public function testTheTargetSignatureGetsTheValueObject(): void {
			$service = new class {
				/**
				 * The calls received.
				 *
				 * @var array<int, array<int, mixed>>
				 */
				public array $calls = [];

				/**
				 * OpenRegister main's signature.
				 *
				 * @param RegisterSchemaSlugs  $target        Register and schema.
				 * @param array<string, mixed> $filters       Filters.
				 * @param bool                 $_rbac         RBAC.
				 * @param bool                 $_multitenancy Multitenancy.
				 *
				 * @return array<int, string>
				 */
				public function searchObjectsBySlug(
					RegisterSchemaSlugs $target,
					array $filters=[],
					bool $_rbac=true,
					bool $_multitenancy=true
				): array {
					$this->calls[] = [$target->register, $target->schema, $filters, $_rbac, $_multitenancy];
					return ['hit'];
				}
			};

			$result = SlugSearch::run(
				objectService: $service,
				register: 'buildiq',
				schema: 'built-app',
				filters: ['slug' => 'crm'],
				rbac: false,
				multitenancy: false
			);

			self::assertSame(expected: ['hit'], actual: $result);
			self::assertSame(expected: [['buildiq', 'built-app', ['slug' => 'crm'], false, false]], actual: $service->calls);
		}

		/**
		 * The published signature before 21 Nov 2026: two slugs, positionally.
		 *
		 * @return void
		 */
		public function testTheLegacySignatureGetsTheTwoSlugs(): void {
			$service = new class {
				/**
				 * The calls received.
				 *
				 * @var array<int, array<int, mixed>>
				 */
				public array $calls = [];

				/**
				 * The old signature.
				 *
				 * @param string               $registerSlug  Register slug.
				 * @param string               $schemaSlug    Schema slug.
				 * @param array<string, mixed> $filters       Filters.
				 * @param bool                 $_rbac         RBAC.
				 * @param bool                 $_multitenancy Multitenancy.
				 *
				 * @return int
				 */
				public function searchObjectsBySlug(
					string $registerSlug,
					string $schemaSlug,
					array $filters=[],
					bool $_rbac=true,
					bool $_multitenancy=true
				): int {
					$this->calls[] = [$registerSlug, $schemaSlug, $filters, $_rbac, $_multitenancy];
					return 3;
				}
			};

			$result = SlugSearch::run(objectService: $service, register: 'buildiq', schema: 'applicationVersion');

			self::assertSame(expected: 3, actual: $result);
			self::assertSame(expected: [['buildiq', 'applicationVersion', [], true, true]], actual: $service->calls);
		}

		/**
		 * No buildiq call site uses the old form directly any more.
		 *
		 * @return void
		 */
		public function testNoCallSiteBypassesTheAdapter(): void {
			$root    = dirname(__DIR__, levels: 3) . '/lib';
			$callers = [];
			$files   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), '/Support/SlugSearch.php') === true) {
					continue;
				}

				foreach (file($file->getPathname()) as $number => $line) {
					if (str_contains($line, '->searchObjectsBySlug(') === true && preg_match('/^\s*(\*|\/\/)/', $line) !== 1) {
						$callers[] = substr($file->getPathname(), strlen($root)) . ':' . ($number + 1);
					}
				}
			}

			self::assertSame(expected: [], actual: $callers, message: 'Call OpenRegister\'s slug search through SlugSearch::run().');
		}
	}
}
