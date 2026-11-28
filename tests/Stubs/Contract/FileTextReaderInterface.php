<?php

/**
 * Stub of OpenRegister's published file text read (openregister change
 * a-file-text-read-for-consuming-apps), for tests run without OpenRegister.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contract;

if (interface_exists(FileTextReaderInterface::class) === false) {
	/**
	 * Reads the text of a file without storing anything.
	 */
	interface FileTextReaderInterface {

		/**
		 * The text of a file, or null when the type has no reader or the file holds no text.
		 *
		 * @param \OCP\Files\File $file The file.
		 *
		 * @return string|null
		 */
		public function readFileText(\OCP\Files\File $file): ?string;
	}
}
