<?php

/**
 * ManifestDataBinding — point a copilot-authored page at the data it created.
 *
 * `upsertSchema` namespaces everything it makes: a schema the model calls
 * `loan`, authored against app `tool-library` version `development`, is stored
 * as `tool-library-development-loan` inside the register
 * `openbuild-tool-library-development`. `upsertPage` and `addWidget` store
 * `config.register` / `config.schema` exactly as the model wrote them, and the
 * model writes the short name it asked for: `loan` / `loan`. Nothing rewrote
 * them, so a plan that executed cleanly still produced an app whose list pages
 * and KPI cards read from a register and a schema that do not exist. The app
 * opened, the menu worked, and every page was empty.
 *
 * The wizard path solved the same problem in
 * {@see \OCA\Buildiq\Service\ApplicationCreationService::substituteVersionContext()}
 * (buildiq#75, added after KPI cards aggregated against a schema that was
 * never there). This is that rule, applied to the copilot path, over the whole
 * config block rather than just a page's top level: a dashboard's data binding
 * lives on each widget's `content`, one level further in.
 *
 * WHAT IT DELIBERATELY LEAVES ALONE, mirroring
 * {@see \OCA\Buildiq\Service\VersionSchemaCarrier::rewriteManifestWiring()}:
 * a register slug that already carries the `openbuild-` prefix names a
 * per-version register somebody chose on purpose, and a schema slug already
 * carrying this version's prefix has been through here before. Both are
 * returned untouched, so running this twice changes nothing the first run did.
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

use OCA\Buildiq\Service\ApplicationVersionService;

/**
 * Rewrites `register` / `schema` bindings onto a version's own data.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
 */
final class ManifestDataBinding {

	/**
	 * The `{registerSlug}` token the manifest templates carry.
	 *
	 * @var string
	 */
	private const REGISTER_TOKEN = '{registerSlug}';

	/**
	 * The per-version register slug for an app version.
	 *
	 * Built from {@see ApplicationVersionService::VERSION_REGISTER_PREFIX} so
	 * the prefix is never typed out, the same way `UpsertSchemaHandler` and
	 * `ApplicationCreationService` build it.
	 *
	 * @param string $appSlug The application slug.
	 * @param string $versionSlug The version slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function registerSlug(string $appSlug, string $versionSlug): string {
		return ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug . '-' . $versionSlug;
	}//end registerSlug()

	/**
	 * The prefix `upsertSchema` namespaces a schema slug with.
	 *
	 * @param string $appSlug The application slug.
	 * @param string $versionSlug The version slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function schemaPrefix(string $appSlug, string $versionSlug): string {
		return $appSlug . '-' . $versionSlug . '-';
	}//end schemaPrefix()

	/**
	 * Bind one whole config block: a page's `config`, or the `widgetConfig`
	 * an `addWidget` step carries.
	 *
	 * On top of {@see bind()}, a block that names a `schema` but no `register`
	 * gets this version's register filled in. That is a completion rather than
	 * an invention: a copilot-authored schema exists nowhere but this version's
	 * own register, so there is exactly one register the block could mean, and
	 * a block naming a schema with no register renders nothing at all.
	 *
	 * @param array<string, mixed> $config The config block as the step carries it.
	 * @param string $appSlug The application slug the step targets.
	 * @param string $versionSlug The version slug the step targets.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function bindBlock(array $config, string $appSlug, string $versionSlug): array {
		if ($appSlug === '' || $versionSlug === '') {
			return $config;
		}

		$bound = self::bind(node: $config, appSlug: $appSlug, versionSlug: $versionSlug);
		if (is_array($bound) === false) {
			return $config;
		}

		$namesSchema = (isset($bound['schema']) === true && is_string($bound['schema']) === true && trim($bound['schema']) !== '');
		$hasRegister = (isset($bound['register']) === true && is_string($bound['register']) === true && trim($bound['register']) !== '');
		if ($namesSchema === true && $hasRegister === false) {
			$bound['register'] = self::registerSlug(appSlug: $appSlug, versionSlug: $versionSlug);
		}

		return $bound;
	}//end bindBlock()

	/**
	 * Rewrite every `register` / `schema` binding in a config block.
	 *
	 * Walks the whole block, so a page's own `{register, schema}` pair and each
	 * widget's `content.{register, schema}` are both reached.
	 *
	 * @param mixed $node The config block, or any part of it.
	 * @param string $appSlug The application slug the step targets.
	 * @param string $versionSlug The version slug the step targets.
	 *
	 * @return mixed The block with its bindings pointed at this version's data.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function bind(mixed $node, string $appSlug, string $versionSlug): mixed {
		if (is_array($node) === false || $appSlug === '' || $versionSlug === '') {
			return $node;
		}

		foreach ($node as $key => $value) {
			if ($key === 'register') {
				$node[$key] = self::bindRegister(value: $value, appSlug: $appSlug, versionSlug: $versionSlug);
				continue;
			}

			if ($key === 'schema') {
				$node[$key] = self::bindSchema(value: $value, appSlug: $appSlug, versionSlug: $versionSlug);
				continue;
			}

			$node[$key] = self::bind(node: $value, appSlug: $appSlug, versionSlug: $versionSlug);
		}

		return $node;
	}//end bind()

	/**
	 * Point one `register` value at this version's own register.
	 *
	 * @param mixed $value The value as written.
	 * @param string $appSlug The application slug.
	 * @param string $versionSlug The version slug.
	 *
	 * @return mixed
	 */
	private static function bindRegister(mixed $value, string $appSlug, string $versionSlug): mixed {
		if (is_string($value) === false || trim($value) === '') {
			return $value;
		}

		$slug = trim($value);
		$target = self::registerSlug(appSlug: $appSlug, versionSlug: $versionSlug);
		if ($slug === self::REGISTER_TOKEN) {
			return $target;
		}

		// Already a per-version register: somebody named it on purpose.
		if (str_starts_with($slug, ApplicationVersionService::VERSION_REGISTER_PREFIX) === true) {
			return $slug;
		}

		return $target;
	}//end bindRegister()

	/**
	 * Point one `schema` value at this version's namespaced schema.
	 *
	 * A numeric value is an OpenRegister schema id, which is already
	 * unambiguous, so it is left as it is.
	 *
	 * @param mixed $value The value as written.
	 * @param string $appSlug The application slug.
	 * @param string $versionSlug The version slug.
	 *
	 * @return mixed
	 */
	private static function bindSchema(mixed $value, string $appSlug, string $versionSlug): mixed {
		if (is_string($value) === false || trim($value) === '' || is_numeric($value) === true) {
			return $value;
		}

		$slug = trim($value);
		$prefix = self::schemaPrefix(appSlug: $appSlug, versionSlug: $versionSlug);
		if (str_starts_with($slug, $prefix) === true) {
			return $slug;
		}

		return $prefix . $slug;
	}//end bindSchema()
}//end class
