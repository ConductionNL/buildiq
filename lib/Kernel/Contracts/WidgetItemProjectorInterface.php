<?php

/**
 * Contract of WidgetItemProjector
 *
 * The public surface of WidgetItemProjector that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use OCA\Buildiq\Kernel\Values\WidgetDescriptor;
use OCP\Dashboard\Model\WidgetItems;

/**
 * Contract of WidgetItemProjector.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WidgetItemProjectorInterface {

	/**
	 * Project one promoted widget into dashboard items.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 * @param int $limit Maximum number of items to return.
	 *
	 * @return WidgetItems The items, or an honest empty state.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-clients-that-cannot-run-the-widget-receive-projected-items
	 */
	public function project(WidgetDescriptor $descriptor, int $limit = 7): WidgetItems;
}//end interface
