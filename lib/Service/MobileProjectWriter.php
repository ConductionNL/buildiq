<?php

/**
 * Writes a mobile app project into an export tree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\Icon\SvgRasteriser;
use OCP\IURLGenerator;
use Throwable;

/**
 * Writes `mobile/`: a Capacitor wrapper project whose only content is this instance's run page of the app.
 *
 * The project carries the app's name, a bundle id, the app's icon and a splash
 * image, and a README on building, signing and publishing. The wrapper allows
 * navigation to this instance only, so every other link opens in the system
 * browser. Buildiq builds, signs and publishes nothing (design D6).
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-the-export-writes-a-mobile-app-project-req-bqom-006
 */
class MobileProjectWriter {

	/**
	 * The folder the project goes into, at the root of the export tree.
	 */
	public const DIRECTORY = 'mobile';

	/**
	 * The Capacitor major version the project is written for.
	 */
	private const CAPACITOR_VERSION = '^6.1.2';

	/**
	 * Icon size in pixels, the size the store tooling asks for.
	 */
	private const ICON_SIZE = 1024;

	/**
	 * Splash image size in pixels, the size the store tooling asks for.
	 */
	private const SPLASH_SIZE = 2732;

	/**
	 * A bundle id: two or more dot-separated parts, each a letter followed by letters, digits or underscores.
	 */
	private const BUNDLE_ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)+$/';

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Makes the absolute run URL of the app on this instance.
	 * @param IconService   $iconService  Reads the app's icon.
	 * @param SvgRasteriser $rasteriser   Turns an SVG icon into the PNG sizes the stores want, when it can.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly IconService $iconService,
		private readonly SvgRasteriser $rasteriser,
	) {
	}//end __construct()

	/**
	 * Whether a bundle id has the shape both stores accept.
	 *
	 * @param string $bundleId The bundle id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T08
	 */
	public static function isValidBundleId(string $bundleId): bool {
		return preg_match(self::BUNDLE_ID_PATTERN, $bundleId) === 1;
	}//end isValidBundleId()

	/**
	 * The default bundle id, `nl.<instance-host>.<slug>`, reduced to characters a bundle id may hold.
	 *
	 * @param string $host The instance host.
	 * @param string $slug The app slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T08
	 */
	public static function defaultBundleId(string $host, string $slug): string {
		return 'nl.' . self::bundleIdPart(raw: $host, fallback: 'instance') . '.' . self::bundleIdPart(raw: $slug, fallback: 'app');
	}//end defaultBundleId()

	/**
	 * One bundle id part: lower case letters, digits and underscores, starting with a letter.
	 *
	 * @param string $raw      The text to reduce.
	 * @param string $fallback The part when nothing usable is left.
	 *
	 * @return string
	 */
	private static function bundleIdPart(string $raw, string $fallback): string {
		$part = (string)preg_replace('/[^a-z0-9]+/', '', strtolower($raw));
		if ($part === '') {
			return $fallback;
		}

		if (ctype_alpha($part[0]) === false) {
			$part = 'a' . $part;
		}

		return $part;
	}//end bundleIdPart()

	/**
	 * Write `mobile/` into the tree.
	 *
	 * @param string $rootDir  The export tree.
	 * @param string $slug     The app slug.
	 * @param string $appName  The app's name, as the stores show it.
	 * @param string $bundleId The bundle id; empty for the default.
	 *
	 * @return array{bundleId: string, runUrl: string, icons: bool} What was written: the bundle id, the run URL,
	 *     and whether PNG icons could be made.
	 *
	 * @throws InvalidArgumentException When the bundle id has a shape the stores refuse.
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T08
	 */
	public function write(string $rootDir, string $slug, string $appName, string $bundleId=''): array {
		$runUrl = $this->urlGenerator->linkToRouteAbsolute('buildiq.dashboard.run', ['slug' => $slug]);
		$host   = (string)parse_url($runUrl, PHP_URL_HOST);
		if ($bundleId === '') {
			$bundleId = self::defaultBundleId(host: $host, slug: $slug);
		}

		if (self::isValidBundleId(bundleId: $bundleId) === false) {
			throw new InvalidArgumentException('The bundle id "' . $bundleId . '" is not valid: use parts like nl.example.app.');
		}

		$name = trim($appName);
		if ($name === '') {
			$name = $slug;
		}

		$dir = rtrim($rootDir, '/') . '/' . self::DIRECTORY;
		foreach (['', '/www', '/resources'] as $sub) {
			if (is_dir($dir . $sub) === false) {
				mkdir($dir . $sub, 0o755, true);
			}
		}

		$json = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$config = $this->config(bundleId: $bundleId, name: $name, runUrl: $runUrl, host: $host);
		file_put_contents($dir . '/capacitor.config.json', json_encode($config, $json) . "\n");
		file_put_contents($dir . '/package.json', json_encode($this->package(slug: $slug), $json) . "\n");
		file_put_contents($dir . '/www/index.html', $this->fallbackPage(name: $name, runUrl: $runUrl));
		$icons = $this->writeImages(dir: $dir . '/resources', slug: $slug);
		file_put_contents($dir . '/README.md', $this->readme(name: $name, bundleId: $bundleId, runUrl: $runUrl, icons: $icons));

		return ['bundleId' => $bundleId, 'runUrl' => $runUrl, 'icons' => $icons];
	}//end write()

	/**
	 * The Capacitor configuration: the run URL as the only content, this instance as the only allowed origin.
	 *
	 * @param string $bundleId The bundle id.
	 * @param string $name     The app's name.
	 * @param string $runUrl   The run URL.
	 * @param string $host     The instance host.
	 *
	 * @return array<string, mixed>
	 */
	private function config(string $bundleId, string $name, string $runUrl, string $host): array {
		return [
			'appId' => $bundleId,
			'appName' => $name,
			'webDir' => 'www',
			'server' => [
				'url' => $runUrl,
				'cleartext' => str_starts_with($runUrl, 'http://'),
				'allowNavigation' => [$host],
			],
			'android' => ['allowMixedContent' => false],
		];
	}//end config()

	/**
	 * The npm manifest of the wrapper project.
	 *
	 * @param string $slug The app slug.
	 *
	 * @return array<string, mixed>
	 */
	private function package(string $slug): array {
		return [
			'name' => $slug . '-mobile',
			'version' => '1.0.0',
			'private' => true,
			'scripts' => [
				'assets' => 'capacitor-assets generate',
				'android' => 'cap add android && cap sync android',
				'ios' => 'cap add ios && cap sync ios',
			],
			'dependencies' => [
				'@capacitor/android' => self::CAPACITOR_VERSION,
				'@capacitor/core' => self::CAPACITOR_VERSION,
				'@capacitor/ios' => self::CAPACITOR_VERSION,
			],
			'devDependencies' => [
				'@capacitor/assets' => '^3.0.5',
				'@capacitor/cli' => self::CAPACITOR_VERSION,
			],
		];
	}//end package()

	/**
	 * The page in `www/`, shown only if the run URL cannot load: a link to it.
	 *
	 * @param string $name   The app's name.
	 * @param string $runUrl The run URL.
	 *
	 * @return string
	 */
	private function fallbackPage(string $name, string $runUrl): string {
		$title = htmlspecialchars($name, ENT_QUOTES);
		$url   = htmlspecialchars($runUrl, ENT_QUOTES);

		return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
			. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
			. '<title>' . $title . "</title>\n</head>\n<body>\n"
			. '<p><a href="' . $url . '">' . $title . "</a></p>\n</body>\n</html>\n";
	}//end fallbackPage()

	/**
	 * The icon and splash image in `resources/`: PNGs when the icon can be rasterised, else the icon as it is.
	 *
	 * @param string $dir  The resources folder.
	 * @param string $slug The app slug.
	 *
	 * @return bool Whether the PNG icon and splash image were written.
	 */
	private function writeImages(string $dir, string $slug): bool {
		try {
			['stream' => $stream, 'mimeType' => $mimeType] = $this->iconService->getIconStream(slug: $slug, dark: false);
			$bytes = null;
			if (is_resource($stream) === true) {
				$bytes = stream_get_contents($stream);
			}
		} catch (Throwable) {
			return false;
		}

		if (is_string($bytes) === false || $bytes === '') {
			return false;
		}

		if ((string)$mimeType === 'image/svg+xml') {
			$icon   = $this->rasteriser->toPng(svg: $bytes, size: self::ICON_SIZE);
			$splash = $this->rasteriser->toPng(svg: $bytes, size: self::SPLASH_SIZE);
			if ($icon !== null && $splash !== null) {
				file_put_contents($dir . '/icon.png', $icon);
				file_put_contents($dir . '/splash.png', $splash);
				return true;
			}

			file_put_contents($dir . '/icon.svg', $bytes);
			return false;
		}

		if ((string)$mimeType === 'image/png') {
			file_put_contents($dir . '/icon.png', $bytes);
			file_put_contents($dir . '/splash.png', $bytes);
			return true;
		}

		return false;
	}//end writeImages()

	/**
	 * The README: what the project is, and how the organisation builds, signs and publishes it.
	 *
	 * @param string $name     The app's name.
	 * @param string $bundleId The bundle id.
	 * @param string $runUrl   The run URL.
	 * @param bool   $icons    Whether PNG icons were written.
	 *
	 * @return string
	 */
	private function readme(string $name, string $bundleId, string $runUrl, bool $icons): string {
		$images = "`resources/icon.png` and `resources/splash.png` were made from the app's icon."
			. ' The store tooling wants the icon at 1024 x 1024 and the splash image at 2732 x 2732;'
			. ' replace them with your own artwork if you like, then run `npm run assets`.';
		if ($icons === false) {
			$images = "This server could not make PNG images from the app's icon."
				. ' Put `resources/icon.png` (1024 x 1024) and `resources/splash.png` (2732 x 2732) in place yourself'
				. ' (`resources/icon.svg` holds the icon when there is one), then run `npm run assets`.';
		}

		return '# ' . $name . " mobile app\n\n"
			. "A wrapper project for the iOS and Android app stores. The app shows one page:\n\n    " . $runUrl . "\n\n"
			. "People sign in with this Nextcloud's own login page. Only this instance opens inside the app;"
			. " every other link opens in the phone's browser. Buildiq does not build, sign or publish the store apps: your organisation does.\n\n"
			. "## Bundle id\n\n`" . $bundleId . "`. Change `appId` in `capacitor.config.json` before the first build;"
			. " the stores do not let you change it afterwards.\n\n"
			. "## Images\n\n" . $images . "\n\n"
			. "## Build\n\nYou need Node.js 18 or newer, Android Studio for Android, and Xcode on a Mac for iOS.\n\n"
			. "    npm install\n    npm run assets\n    npm run android   # then: npx cap open android\n    npm run ios       # then: npx cap open ios\n\n"
			. "## Sign and publish\n\n"
			. "- Android: in Android Studio, Build > Generate Signed Bundle, with your organisation's upload key."
			. " Upload the bundle in the Google Play Console.\n"
			. "- iOS: in Xcode, set your team under Signing & Capabilities, then Product > Archive and upload it to App Store Connect.\n\n"
			. "## Known limits\n\n"
			. "- An identity provider that refuses embedded browsers will stop the login in the app."
			. " The installable web app (Install from the browser) does not have this limit.\n"
			. "- The app needs this server to be reachable; offline use follows the app's own offline settings.\n";
	}//end readme()
}//end class
