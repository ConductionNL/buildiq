<?php

/**
 * Buildiq Channel Provisioners
 *
 * The per-channel provisioners a published repository is applied through. AppChannelApplier
 * applies a published repository channel by channel; this object carries the provisioner for each
 * channel.
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
 * The per-channel provisioners a published repository is applied through.
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */
final class ChannelProvisioners {
	/**
	 * Constructor.
	 *
	 * @param DataRegisterProvisioner $registerProvisioner Provisions data registers.
	 * @param SkillChannelDelegate $skillDelegate Applies the skills channel.
	 * @param FlowChannelProvisioner $flowProvisioner Provisions flows.
	 * @param AgentChannelProvisioner $agentProvisioner Provisions agents.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DataRegisterProvisioner $registerProvisioner,
		public readonly SkillChannelDelegate $skillDelegate,
		public readonly FlowChannelProvisioner $flowProvisioner,
		public readonly AgentChannelProvisioner $agentProvisioner,
	) {
	}//end __construct()
}//end class
