<?php

/**
 * Unit tests for FormDestinationController.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\FormDestinationController;
use OCA\Buildiq\Service\ManifestFormDestinations;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the answer shape, the missing manifest, the editor guard and a failing check.
 */
final class FormDestinationControllerTest extends TestCase {
	/**
	 * Findings come back with `accepted: false`.
	 *
	 * @return void
	 */
	public function testFindingsComeBackWithAcceptedFalse(): void {
		$finding = ['page' => 'new', 'pageIndex' => 0, 'field' => 'a', 'property' => 'a', 'code' => 'type-mismatch', 'message' => 'A'];
		$response = $this->controller(manifest: ['pages' => []], findings: [$finding])->check();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['accepted' => false, 'findings' => [$finding]], $response->getData());
	}//end testFindingsComeBackWithAcceptedFalse()

	/**
	 * The control: no findings is `accepted: true`.
	 *
	 * @return void
	 */
	public function testNoFindingsIsAccepted(): void {
		$response = $this->controller(manifest: ['pages' => []], findings: [])->check();

		self::assertSame(['accepted' => true, 'findings' => []], $response->getData());
	}//end testNoFindingsIsAccepted()

	/**
	 * A body without a manifest is a 400.
	 *
	 * @return void
	 */
	public function testABodyWithoutAManifestIs400(): void {
		self::assertSame(400, $this->controller(manifest: null, findings: [])->check()->getStatus());
	}//end testABodyWithoutAManifestIs400()

	/**
	 * A check that throws is a 500, never a silent accept.
	 *
	 * @return void
	 */
	public function testAFailingCheckIs500(): void {
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->method('findings')->willThrowException(new RuntimeException('boom'));

		self::assertSame(500, $this->controllerOver(manifest: ['pages' => []], destinations: $destinations, mayEdit: true)->check()->getStatus());
	}//end testAFailingCheckIs500()

	/**
	 * Someone who may not edit the app gets a 403, and the check never runs:
	 * its findings name the destination schema's properties.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotEditTheAppIsRefused(): void {
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->expects($this->never())->method('findings');

		self::assertSame(403, $this->controllerOver(manifest: ['pages' => []], destinations: $destinations, mayEdit: false)->check()->getStatus());
	}//end testACallerWhoMayNotEditTheAppIsRefused()

	/**
	 * The controller over a request carrying this manifest and a check answering these findings.
	 *
	 * @param array<string, mixed>|null $manifest The body's manifest.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return FormDestinationController The controller.
	 */
	private function controller(?array $manifest, array $findings): FormDestinationController {
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->method('findings')->willReturn($findings);

		return $this->controllerOver(manifest: $manifest, destinations: $destinations, mayEdit: true);
	}//end controller()

	/**
	 * The controller for the app `demo`, whose permissions do or do not admit the caller.
	 *
	 * @param array<string, mixed>|null $manifest The body's manifest.
	 * @param ManifestFormDestinations $destinations The check.
	 * @param bool $mayEdit Whether the caller is an owner or editor of `demo`.
	 *
	 * @return FormDestinationController The controller.
	 */
	private function controllerOver(?array $manifest, ManifestFormDestinations $destinations, bool $mayEdit): FormDestinationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'manifest' => $manifest,
				'appSlug' => 'demo',
				default => $default,
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$applications = $this->createMock(ManifestResolverService::class);
		$applications->method('findApplication')->with('demo')->willReturn(['slug' => 'demo', 'permissions' => ['owners' => ['user:alice']]]);

		$permissions = $this->createMock(PermissionResolver::class);
		$permissions->method('resolveUserGroups')->willReturn([]);
		$permissions->method('matchesCaller')->willReturn($mayEdit);

		return new FormDestinationController(
			appName: 'buildiq',
			request: $request,
			destinations: $destinations,
			applications: $applications,
			permissions: $permissions,
			userSession: $session,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controllerOver()
}//end class
