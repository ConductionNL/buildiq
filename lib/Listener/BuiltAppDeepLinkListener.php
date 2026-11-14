<?php

/**
 * Buildiq BuiltAppDeepLinkListener
 *
 * Extends OpenRegister's AppHost manifest-driven deep-link listener so that,
 * next to the entries of buildiq's own `src/manifest.json` `deepLinks`, every
 * detail page of the production version of every published built app gets a
 * deep link. A record of a built app found in Nextcloud unified search then
 * opens on its page in that app (`/apps/buildiq/builder/{slug}/...`) instead
 * of in OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\ManifestDataBinding;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\AppHost\Listener\GenericDeepLinkRegistrationListener;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers buildiq's manifest deep links plus one per built-app detail page.
 *
 * @spec openspec/changes/apps-unified-search-deep-links/specs/deep-link-registration/spec.md
 */
class BuiltAppDeepLinkListener extends GenericDeepLinkRegistrationListener {
	/**
	 * Path under which a built app's runtime is served; the app slug follows.
	 */
	private const BUILDER_PATH = '/apps/buildiq/builder/';

	/**
	 * Matches one route parameter such as `:id` or `:objectId`.
	 */
	private const ROUTE_PARAM = '/:[A-Za-z_][A-Za-z0-9_]*/';

	/**
	 * Constructor.
	 *
	 * @param string $appId The leaf app id whose manifest is read (`buildiq`).
	 * @param IAppManager $appManager App path resolution.
	 * @param LoggerInterface $logger PSR logger.
	 * @param PublishedApplicationProvider $applications The published Applications, production version extended.
	 * @param ManifestResolverServiceInterface $manifestResolver Resolves a production manifest by app slug.
	 *
	 * @return void
	 */
	public function __construct(
		string $appId,
		IAppManager $appManager,
		LoggerInterface $logger,
		private readonly PublishedApplicationProvider $applications,
		private readonly ManifestResolverServiceInterface $manifestResolver,
	) {
		parent::__construct(appId: $appId, appManager: $appManager, logger: $logger);
	}//end __construct()

	/**
	 * The manifest entries plus one entry per detail page of every published app's production version.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-2.1
	 */
	protected function loadDeepLinks(): array {
		$links = parent::loadDeepLinks();

		try {
			$applications = $this->applications->getPublishedApplications();
		} catch (Throwable $e) {
			// The manifest entries still register; built-app hits keep
			// opening in OpenRegister, as they did before this listener.
			$this->logger->warning(
				'Buildiq: built-app deep links skipped, published applications unreadable: ' . $e->getMessage(),
				['exception' => $e]
			);
			return $links;
		}

		foreach ($applications as $application) {
			foreach ($this->applicationLinks(application: $application) as $link) {
				$links[] = $link;
			}
		}

		return $links;
	}//end loadDeepLinks()

	/**
	 * The deep links of one published Application's production version.
	 *
	 * An Application without a production version yields none: its hits keep
	 * opening in OpenRegister. The Application-level `manifest` fallback that
	 * other readers apply is deliberately not used here.
	 *
	 * @param array<string, mixed> $application One published Application record.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-2.1
	 */
	private function applicationLinks(array $application): array {
		$appSlug = ($application['slug'] ?? null);
		if (is_string($appSlug) === false || $appSlug === '') {
			return [];
		}

		$version = $this->productionVersion(application: $application, appSlug: $appSlug);
		if ($version === null) {
			return [];
		}

		$pages = ($version['manifest']['pages'] ?? null);
		if (is_array($pages) === false) {
			return [];
		}

		$links = [];
		foreach ($pages as $page) {
			if (is_array($page) === false) {
				continue;
			}

			$link = $this->pageLink(page: $page, appSlug: $appSlug, version: $version);
			if ($link !== null) {
				$links[] = $link;
			}
		}

		return $links;
	}//end applicationLinks()

	/**
	 * The production version of an Application: its manifest, slug and stored register.
	 *
	 * The shared provider extends `productionVersion`, so normally the version
	 * object is embedded. A bare UUID pointer is resolved through
	 * ManifestResolverService without caller filtering; the version slug and
	 * register are then unknown, so only pages naming a concrete register link.
	 *
	 * @param array<string, mixed> $application One published Application record.
	 * @param string $appSlug The Application slug.
	 *
	 * @return array{manifest: array<string, mixed>, slug: string, register: string}|null
	 */
	private function productionVersion(array $application, string $appSlug): ?array {
		$productionVersion = ($application['productionVersion'] ?? null);

		if (is_array($productionVersion) === true) {
			$manifest = ($productionVersion['manifest'] ?? null);
			if (is_array($manifest) === false) {
				return null;
			}

			return [
				'manifest' => $manifest,
				'slug' => self::stringOrEmpty(value: ($productionVersion['slug'] ?? null)),
				'register' => self::stringOrEmpty(value: ($productionVersion['register'] ?? null)),
			];
		}

		if (is_string($productionVersion) === false || $productionVersion === '') {
			return null;
		}

		try {
			$manifest = $this->manifestResolver->resolve(appSlug: $appSlug, versionSlug: null, caller: null);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: production manifest of ' . $appSlug . ' unreadable for deep links: ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}

		if ($manifest === null) {
			return null;
		}

		return ['manifest' => $manifest, 'slug' => '', 'register' => ''];
	}//end productionVersion()

	/**
	 * The deep link of one manifest page, or null when the page does not get one.
	 *
	 * Only a page of type `detail` that names `config.schema` and
	 * `config.register` and whose route carries exactly one parameter links.
	 *
	 * @param array<string, mixed> $page One manifest page.
	 * @param string $appSlug The Application slug.
	 * @param array{manifest: array<string, mixed>, slug: string, register: string} $version The production version.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/apps-unified-search-deep-links/specs/deep-link-registration/spec.md#requirement-records-of-a-built-app-open-on-their-page-in-the-app-req-obdl-003
	 */
	private function pageLink(array $page, string $appSlug, array $version): ?array {
		if (($page['type'] ?? null) !== 'detail') {
			return null;
		}

		$config = ($page['config'] ?? null);
		if (is_array($config) === false) {
			return null;
		}

		$schema = self::stringOrEmpty(value: ($config['schema'] ?? null));
		$register = $this->registerOf(
			pageRegister: self::stringOrEmpty(value: ($config['register'] ?? null)),
			appSlug: $appSlug,
			version: $version
		);
		$path = self::uuidPath(route: self::stringOrEmpty(value: ($page['route'] ?? null)));
		if ($schema === '' || $register === '' || $path === null) {
			return null;
		}

		$link = [
			'registerSlug' => $register,
			'schemaSlug' => $schema,
			'urlTemplate' => self::BUILDER_PATH . $appSlug . $path,
		];

		$title = ($page['title'] ?? null);
		if (is_string($title) === true && $title !== '') {
			$link['displayName'] = $title;
		}

		return $link;
	}//end pageLink()

	/**
	 * The register a page's records live in.
	 *
	 * A concrete `config.register` is used as is. A template token
	 * (`{registerSlug}`, `{{register}}`) stands for the version's own register:
	 * its stored register slug, else the per-version slug buildiq provisions,
	 * built by {@see ManifestDataBinding::registerSlug()}.
	 *
	 * @param string $pageRegister The page's `config.register`.
	 * @param string $appSlug The Application slug.
	 * @param array{manifest: array<string, mixed>, slug: string, register: string} $version The production version.
	 *
	 * @return string The register slug, or '' when it cannot be determined.
	 *
	 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-2.3
	 * @SuppressWarnings(PHPMD.StaticAccess) ManifestDataBinding::registerSlug is a pure slug builder.
	 */
	private function registerOf(string $pageRegister, string $appSlug, array $version): string {
		if ($pageRegister === '') {
			return '';
		}

		if (str_contains($pageRegister, '{') === false) {
			return $pageRegister;
		}

		if ($version['register'] !== '' && ctype_digit($version['register']) === false) {
			return $version['register'];
		}

		if ($version['slug'] === '') {
			return '';
		}

		return ManifestDataBinding::registerSlug(appSlug: $appSlug, versionSlug: $version['slug']);
	}//end registerOf()

	/**
	 * The page route with its single parameter replaced by `{uuid}`.
	 *
	 * @param string $route The page route, e.g. `/zaken/:id`.
	 *
	 * @return string|null The path, or null when the route has no parameter or more than one.
	 */
	private static function uuidPath(string $route): ?string {
		if (preg_match_all(self::ROUTE_PARAM, $route) !== 1) {
			return null;
		}

		$path = (string)preg_replace(self::ROUTE_PARAM, '{uuid}', $route);
		if (str_starts_with($path, '/') === false) {
			$path = '/' . $path;
		}

		return $path;
	}//end uuidPath()

	/**
	 * A non-empty string value, or ''.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return string
	 */
	private static function stringOrEmpty(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringOrEmpty()
}//end class
