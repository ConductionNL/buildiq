<?php

/**
 * Buildiq AppOrganisationGuard.
 *
 * Decides whether a user may see an app at all, by its organisation scope,
 * before any role is looked at. An app scoped to "all" organisations (or with
 * no scope, every app from before the scope existed) is seen by everyone, as
 * before. An app scoped to its "holder" is seen only by members of the
 * organisation OpenRegister stamped on it, or of an organisation OpenRegister
 * counts as that organisation's child. Everyone else gets the answer an
 * unknown slug gets.
 *
 * Fails closed: a scope that is neither absent nor `all` is read as `holder`,
 * and a holder or membership that cannot be read means "not visible".
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

use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The organisation-scope check every door that resolves an app goes through.
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class AppOrganisationGuard {

	/**
	 * Scope: every organisation sees the app.
	 *
	 * @var string
	 */
	public const SCOPE_ALL = 'all';

	/**
	 * Scope: only the holder organisation (and its children) sees the app.
	 *
	 * @var string
	 */
	public const SCOPE_HOLDER = 'holder';

	/**
	 * Reads the holder, the membership and the multitenancy setting.
	 *
	 * @var AppOrganisationMembership
	 */
	private readonly AppOrganisationMembership $membership;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's organisation mapper lazily.
	 * @param LoggerInterface    $logger    Records a membership that could not be read.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
	 */
	public function __construct(
		ContainerInterface $container,
		LoggerInterface $logger,
	) {
		$this->membership = new AppOrganisationMembership(container: $container, logger: $logger);
	}//end __construct()

	/**
	 * Whether an app is shared with every organisation: scope `all`, or no scope at all.
	 *
	 * Pure, so a caller without a guard still lets a shared app through and still
	 * refuses a scoped one.
	 *
	 * @param array<string, mixed> $application The Application.
	 *
	 * @return bool True for a shared app.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-an-app-has-an-organisation-scope-req-bqos-001
	 */
	public static function isShared(array $application): bool {
		$scope = ($application['organisationScope'] ?? null);

		return $scope === null || $scope === '' || $scope === self::SCOPE_ALL;
	}//end isShared()

	/**
	 * Whether the user may see the app at all.
	 *
	 * @param array<string, mixed> $application The Application, as OpenRegister renders it.
	 * @param IUser|null           $user        The caller; null sees only shared apps.
	 *
	 * @return bool True when the app is shared, or the user belongs to its holder or a child of it.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
	 */
	public function canSee(array $application, ?IUser $user): bool {
		if (self::isShared(application: $application) === true) {
			return true;
		}

		return $this->membership->isMember(application: $application, uid: $this->uidOf(user: $user));
	}//end canSee()

	/**
	 * The user id of the caller.
	 *
	 * @param IUser|null $user The caller.
	 *
	 * @return string The uid, or '' without a caller.
	 */
	private function uidOf(?IUser $user): string {
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end uidOf()

	/**
	 * The scope a new app starts with: `holder` while OpenRegister's multitenancy
	 * is on, `all` while it is off.
	 *
	 * @return string `holder` or `all`.
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-an-app-has-an-organisation-scope-req-bqos-001
	 */
	public function defaultScope(): string {
		if ($this->multitenancyOn() === true) {
			return self::SCOPE_HOLDER;
		}

		return self::SCOPE_ALL;
	}//end defaultScope()

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
		return $this->membership->multitenancyOn();
	}//end multitenancyOn()
}//end class
