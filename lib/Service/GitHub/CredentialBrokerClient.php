<?php

/**
 * Buildiq Credential Broker Client
 *
 * Sends one JSON call to GitHub through OpenRegister's credential broker. Only
 * {method, path, body} leave buildiq: the base URL is the broker's host-lock and
 * the Authorization header is injected there from the vault, so this process
 * never sees the token. Built by GitHubAppSyncService and GitHubPushService from
 * the container they are given; each keeps its own handling of the answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\GitHub
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

namespace OCA\Buildiq\Service\GitHub;

use Psr\Container\ContainerInterface;

/**
 * One JSON call through the credential broker.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 */
final class CredentialBrokerClient {
	/**
	 * OpenRegister's credential broker, resolved by class name at call time.
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The app the broker checks the credential's grant against.
	 */
	private const APP_ID = 'buildiq';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the broker when a call is made.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Send a JSON call under the given credential and return the broker's answer.
	 *
	 * Whatever the container or the broker throws is left to the caller, which
	 * decides how a transport failure is reported.
	 *
	 * @param array{method:string,path:string,body:array<string,mixed>|null} $request HTTP method, GitHub-relative path and optional JSON body.
	 * @param BrokerAccess $access The broker credential and its owner.
	 *
	 * @return mixed The broker's `{status, body}` answer.
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function sendJson(array $request, BrokerAccess $access): mixed {
		$encoded = null;
		if ($request['body'] !== null) {
			$encoded = (string)json_encode($request['body']);
		}

		return $this->container->get(self::BROKER_CLASS)->request(
			$access->credentialId,
			self::APP_ID,
			(string)$request['method'],
			(string)$request['path'],
			['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
			$encoded,
			$access->actingUserId
		);
	}//end sendJson()
}//end class
