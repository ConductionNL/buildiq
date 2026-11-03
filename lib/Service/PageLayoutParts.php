<?php

/**
 * Buildiq Page Layout Parts
 *
 * The layer stack, frozen base, presenter and delta store of a page layout. PageLayoutLeafProvider resolves a page layout from its layers, frozen base and stored deltas, and presents the result; this object carries those parts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

/**
 * The layer stack, frozen base, presenter and delta store of a page layout.
 */
final class PageLayoutParts {
	/**
	 * Constructor.
	 *
	 * @param LayoutDeltaService $deltas Stored layout deltas.
	 * @param PageLayoutLayerStack $layers Resolves the layer stack.
	 * @param PageLayoutFrozenBase $frozenBase The frozen base layout.
	 * @param PageLayoutPresenter $presenter Presents the resolved layout.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly LayoutDeltaService $deltas,
		public readonly PageLayoutLayerStack $layers,
		public readonly PageLayoutFrozenBase $frozenBase,
		public readonly PageLayoutPresenter $presenter,
	) {
	}//end __construct()
}//end class
