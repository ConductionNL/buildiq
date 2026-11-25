<?php

/**
 * SlugSearch: calls OpenRegister's searchObjectsBySlug() in the form the
 * installed OpenRegister takes.
 *
 * OpenRegister's published ObjectServiceInterface changed the method on
 * 21 Nov 2026 from `(string $registerSlug, string $schemaSlug, array $filters,
 * bool $_rbac, bool $_multitenancy)` to `(RegisterSchemaSlugs $target,
 * array $filters, bool $_rbac, bool $_multitenancy)`. Every buildiq caller
 * still used the old form, so on OpenRegister main each one threw ("Unknown
 * named parameter $registerSlug", or a TypeError for the positional calls),
 * and the callers that catch \Throwable silently answered "nothing found": an
 * app override that was just saved read back as empty.
 *
 * This adapter reads the first parameter of the service's own method and
 * builds the call that method takes, so buildiq works against OpenRegister
 * before and after that change, and against the unit-test stub.
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
 * @spec exclude call adapter for OpenRegister's slug search across its two published signatures, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

use ReflectionMethod;

/**
 * Runs a slug search against whichever searchObjectsBySlug() signature the service has.
 *
 * @spec exclude call adapter for OpenRegister's slug search across its two published signatures, no behaviour of its own
 */
final class SlugSearch {

	/**
	 * OpenRegister's value class for the register and schema slugs of a slug search.
	 *
	 * @var string
	 */
	public const TARGET_CLASS = 'OCA\\OpenRegister\\Contract\\RegisterSchemaSlugs';

	/**
	 * Per service class: whether its searchObjectsBySlug() takes the target value first.
	 *
	 * @var array<string, bool>
	 */
	private static array $takesTarget = [];

	/**
	 * The register/schema pair to search, both named by slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @spec exclude call adapter for OpenRegister's slug search across its two published signatures, no behaviour of its own
	 */
	public function __construct(
		private readonly string $register,
		private readonly string $schema,
	) {
	}//end __construct()

	/**
	 * Search the pair's objects through the service.
	 *
	 * @param object               $objectService OpenRegister's ObjectService (or a test double of it).
	 * @param array<string, mixed> $filters       Equality filters, as searchObjectsBySlug() takes them.
	 * @param bool                 $rbac          Apply register RBAC.
	 * @param bool                 $multitenancy  Apply organisation scoping.
	 *
	 * @return mixed The matching objects (an array) or a count, as OpenRegister answers.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The two flags are OpenRegister's own
	 *   `$_rbac` / `$_multitenancy`, passed through unchanged.
	 *
	 * @spec exclude call adapter for OpenRegister's slug search across its two published signatures, no behaviour of its own
	 */
	public function run(object $objectService, array $filters=[], bool $rbac=true, bool $multitenancy=true): mixed {
		if (self::takesTarget(objectService: $objectService) === true) {
			$targetClass = self::TARGET_CLASS;
			return $objectService->searchObjectsBySlug(
				new $targetClass($this->register, $this->schema),
				$filters,
				$rbac,
				$multitenancy
			);
		}

		return $objectService->searchObjectsBySlug($this->register, $this->schema, $filters, $rbac, $multitenancy);
	}//end run()

	/**
	 * Whether the service's searchObjectsBySlug() takes OpenRegister's target value first.
	 *
	 * True only when that value class exists AND the method's first parameter is
	 * typed with it, so a test double of the old contract keeps getting the old
	 * call even where the new class is on the autoload path.
	 *
	 * @param object $objectService The service.
	 *
	 * @return bool
	 */
	private static function takesTarget(object $objectService): bool {
		$class = get_class($objectService);
		if (isset(self::$takesTarget[$class]) === true) {
			return self::$takesTarget[$class];
		}

		$takes = false;
		if (class_exists(self::TARGET_CLASS) === true && method_exists($objectService, 'searchObjectsBySlug') === true) {
			$first = ((new ReflectionMethod($objectService, 'searchObjectsBySlug'))->getParameters()[0] ?? null);
			$type  = (string) $first?->getType();
			$takes = (ltrim($type, '?\\') === self::TARGET_CLASS);
		}

		self::$takesTarget[$class] = $takes;
		return $takes;
	}//end takesTarget()
}//end class
