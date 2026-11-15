<?php

/**
 * Buildiq Widget Spec
 *
 * What a caller asks for when it appends a dashboard widget to a page: the widget type,
 * its settings bag, and an optional id and title. ManifestWidgetShape derives the final
 * id, title and layout row from it.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * The widget a caller asks to append.
 *
 * @spec openspec/specs/ai-copilot/spec.md
 */
final class WidgetSpec {
	/**
	 * Constructor.
	 *
	 * @param string $type Widget type identifier, already allow-listed by the caller.
	 * @param array<string, mixed> $config Widget-type-specific settings bag.
	 * @param string $id Caller-supplied id, or '' to derive one.
	 * @param string $title Caller-supplied title, or '' to derive one.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $config,
		public readonly string $id = '',
		public readonly string $title = '',
	) {
	}//end __construct()
}//end class
