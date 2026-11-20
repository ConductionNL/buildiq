<?php

/**
 * Buildiq SvgRasteriser
 *
 * Turns an app's SVG icon into a square PNG for the installed app's touch
 * icon (pages-installable-offline-and-mobile T02, design D2). iOS wants a
 * PNG; this only works when the server's Imagick can read SVG, and says so
 * through available(), so the manifest and the page offer the PNG only then
 * and otherwise keep the SVG.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Icon
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Icon;

use Throwable;

/**
 * Rasterises SVG to PNG through Imagick, when the server has it.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
 */
class SvgRasteriser {

	/**
	 * The touch icon's edge in pixels, the size iOS asks for.
	 *
	 * @var int
	 */
	public const TOUCH_ICON_SIZE = 180;

	/**
	 * Whether this server can turn an SVG into a PNG.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
	 */
	public function available(): bool {
		if (class_exists('Imagick') === false) {
			return false;
		}

		try {
			return \Imagick::queryFormats('SVG') !== [];
		} catch (Throwable $e) {
			return false;
		}
	}//end available()

	/**
	 * A square PNG of the SVG, or null when it cannot be made.
	 *
	 * @param string $svg  The SVG document.
	 * @param int    $size The edge in pixels.
	 *
	 * @return string|null The PNG bytes.
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
	 */
	public function toPng(string $svg, int $size=self::TOUCH_ICON_SIZE): ?string {
		if (trim($svg) === '' || $size < 1 || $this->available() === false) {
			return null;
		}

		try {
			$image = new \Imagick();
			$image->setBackgroundColor(new \ImagickPixel('transparent'));
			$image->readImageBlob($svg);
			$image->setImageFormat('png32');
			$image->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1, true);
			$png = $image->getImageBlob();
			$image->clear();
		} catch (Throwable $e) {
			return null;
		}

		if ($png === '') {
			return null;
		}

		return $png;
	}//end toPng()
}//end class
