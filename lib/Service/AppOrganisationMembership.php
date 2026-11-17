<?php

/**
 * Buildiq AppOrganisationMembership.
 *
 * Reads what the organisation-scope check needs from OpenRegister: the
 * holder organisation of an app, whether a user belongs to it or to one of
 * its children, and whether multitenancy is on. Only AppOrganisationGuard
 * uses it.
 *
 * Fails closed: a holder or membership that cannot be read means "not a
 * member", and a multitenancy setting that cannot be read means "on".
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Organisation membership and the multitenancy setting, as the organisation-scope check reads them.
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class AppOrganisationMembership {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's organisation mapper and the app config lazily.
	 * @param LoggerInterface    $logger    Records a membership or setting that could not be read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the user belongs to the app's holder organisation or a child of it.
	 *
	 * @param array<string, mixed> $application The Application, as OpenRegister renders it.
	 * @param string               $uid         The caller's user id; '' is never a member.
	 *
	 * @return bool False as well when the holder or the membership cannot be read.
	 *
	* @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
	 */
	public function isMember(array $application, string $uid): bool {
		$holder = $this->holderOf(application: $application);
		if ($holder === '' || $uid === '') {
			return false;
		}

		try {
			return $this->belongsTo(uid: $uid, holder: $holder);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppOrganisationGuard: organisation membership could not be read; the app is treated as not visible',
				['exception' => $e->getMessage()]
			);
			return false;
		}
	}//end isMember()

	/**
	 * Whether OpenRegister's multitenancy is on.
	 *
	 * OpenRegister filters by organisation unless its `multitenancy` setting says
	 * `enabled: false`; an absent or unreadable setting counts as on, as it does
	 * for OpenRegister, so a new app is kept to its organisation when in doubt.
	 *
	 * @return bool True when multitenancy is on.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-an-app-has-an-organisation-scope-req-bqos-001
	 */
	public function multitenancyOn(): bool {
		try {
			$config = $this->container->get(IAppConfig::class);
			$raw = '';
			if ($config instanceof IAppConfig) {
				$raw = $config->getValueString('openregister', 'multitenancy', '');
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppOrganisationGuard: the multitenancy setting could not be read; it is treated as on',
				['exception' => $e->getMessage()]
			);
			return true;
		}

		$settings = json_decode($raw, true);
		if (is_array($settings) === false) {
			return true;
		}

		return ($settings['enabled'] ?? true) !== false;
	}//end multitenancyOn()

	/**
	 * The holder organisation OpenRegister stamped on the Application.
	 *
	 * @param array<string, mixed> $application The Application.
	 *
	 * @return string The organisation uuid, or '' when none can be read.
	 */
	private function holderOf(array $application): string {
		$self = ($application['@self'] ?? []);
		$holder = null;
		if (is_array($self) === true) {
			$holder = ($self['organisation'] ?? null);
		}

		if ($holder === null) {
			$holder = ($application['organisation'] ?? null);
		}

		if (is_string($holder) === false) {
			return '';
		}

		return trim($holder);
	}//end holderOf()

	/**
	 * Whether the user is a member of the holder, or of an organisation whose parent chain holds it.
	 *
	 * @param string $uid    The user id.
	 * @param string $holder The holder organisation uuid.
	 *
	 * @return bool True when the user belongs to the holder or one of its children.
	 */
	private function belongsTo(string $uid, string $holder): bool {
		$mapper = $this->container->get(OrganisationMapper::class);
		if (($mapper instanceof OrganisationMapper) === false) {
			return false;
		}

		foreach ($mapper->findByUserId($uid) as $organisation) {
			$uuid = (string)$organisation->getUuid();
			if ($uuid === $holder) {
				return true;
			}

			if ($uuid !== '' && in_array($holder, $mapper->findParentChain($uuid), true) === true) {
				return true;
			}
		}

		return false;
	}//end belongsTo()
}//end class
