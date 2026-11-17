<?php

/**
 * BulkSaveOptions
 *
 * The per-call switches of one bulk save: its default register and schema, and whether
 * it validates, dispatches events and writes audit rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 */

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How one SaveObjects::saveObjects() call saves its rows, apart from its RBAC and multitenancy flags.
 *
 * No strict_types: SaveObjects declares none, and these values arrive from it.
 *
 */
final class BulkSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param BulkBatchDefaults $defaults   The register and schema for every row that names none.
	 * @param bool              $validation Whether to validate rows against their schema.
	 * @param bool              $events     Whether to dispatch object lifecycle events.
	 * @param bool              $audit      Whether to write audit trail rows for the persisted objects.
	 *
	 */
	public function __construct(
		public readonly BulkBatchDefaults $defaults=new BulkBatchDefaults(),
		public readonly bool $validation=false,
		public readonly bool $events=false,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
