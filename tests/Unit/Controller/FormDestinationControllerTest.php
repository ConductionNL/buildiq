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
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the answer shape, the missing manifest and a failing check.
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
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(['pages' => []]);
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->method('findings')->willThrowException(new RuntimeException('boom'));

		$controller = new FormDestinationController('buildiq', $request, $destinations, $this->createMock(LoggerInterface::class));

		self::assertSame(500, $controller->check()->getStatus());
	}//end testAFailingCheckIs500()

	/**
	 * The controller over a request carrying this manifest and a check answering these findings.
	 *
	 * @param array<string, mixed>|null $manifest The body's manifest.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return FormDestinationController The controller.
	 */
	private function controller(?array $manifest, array $findings): FormDestinationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($manifest);
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->method('findings')->willReturn($findings);

		return new FormDestinationController('buildiq', $request, $destinations, $this->createMock(LoggerInterface::class));
	}//end controller()
}//end class
