<?php

/**
 * DocumentGenerationService generate-route resolution tests.
 *
 * The document app was renamed from docudesk to filinq, and an instance
 * registers the generate route under the id it runs. These tests pin that the
 * route is found under either id, newest first, and that an instance with
 * neither reports the route as missing (issue #779).
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-document-action/tasks.md#2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DocumentGenerationService;
use OCA\Buildiq\Service\JobOwnerImpersonator;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Unit tests for how DocumentGenerationService finds the generate route.
 *
 * @covers \OCA\Buildiq\Service\DocumentGenerationService
 * @uses \OCA\Buildiq\Support\FleetAppId
 */
class DocumentGenerationRouteTest extends TestCase {

	/**
	 * The bare instance URL, what the router turns an unknown route into.
	 */
	private const ROOT_URL = 'https://cloud.test/';

	/**
	 * Resolve the route on an instance whose router knows only $known.
	 *
	 * @param list<string> $known Route names the router answers for.
	 *
	 * @return array{0: string, 1: string|null} What generateRoute() answered.
	 */
	private function resolve(array $known): array {
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn(self::ROOT_URL);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static function (string $route) use ($known): string {
				if (in_array($route, $known, true) === true) {
					return self::ROOT_URL . 'route/' . $route;
				}

				return self::ROOT_URL;
			}
		);

		$service = new DocumentGenerationService(
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			ownerImpersonator: $this->createMock(originalClassName: JobOwnerImpersonator::class),
			ruleActionDispatcher: $this->createMock(originalClassName: RuleActionDispatcher::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			urlGenerator: $urls,
			httpClientService: $this->createMock(originalClassName: IClientService::class),
			rootFolder: $this->createMock(originalClassName: IRootFolder::class),
			appDataFactory: $this->createMock(originalClassName: IAppDataFactory::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: new NullLogger()
		);

		$method = new ReflectionMethod(DocumentGenerationService::class, 'generateRoute');

		return $method->invoke($service);
	}//end resolve()

	/**
	 * An instance running filinq uses filinq's route.
	 *
	 * @return void
	 */
	public function testFilinqRouteIsUsed(): void {
		$this->assertSame(
			expected: ['filinq.correspondence.generate', self::ROOT_URL . 'route/filinq.correspondence.generate'],
			actual: $this->resolve(known: ['filinq.correspondence.generate'])
		);
	}//end testFilinqRouteIsUsed()

	/**
	 * An instance still running docudesk keeps working.
	 *
	 * @return void
	 */
	public function testDocudeskRouteIsTheFallback(): void {
		$this->assertSame(
			expected: ['docudesk.correspondence.generate', self::ROOT_URL . 'route/docudesk.correspondence.generate'],
			actual: $this->resolve(known: ['docudesk.correspondence.generate'])
		);
	}//end testDocudeskRouteIsTheFallback()

	/**
	 * With both apps enabled, filinq wins.
	 *
	 * The other two cases each know one route, so they pass under any
	 * candidate order. Only this one pins that the order is newest first.
	 *
	 * @return void
	 */
	public function testFilinqWinsWhenBothAnswer(): void {
		$this->assertSame(
			expected: ['filinq.correspondence.generate', self::ROOT_URL . 'route/filinq.correspondence.generate'],
			actual: $this->resolve(
				known: ['filinq.correspondence.generate', 'docudesk.correspondence.generate']
			)
		);
	}//end testFilinqWinsWhenBothAnswer()

	/**
	 * With neither app, no URL comes back and the newest route is named.
	 *
	 * @return void
	 */
	public function testNeitherIdAnswers(): void {
		$this->assertSame(
			expected: ['filinq.correspondence.generate', null],
			actual: $this->resolve(known: [])
		);
	}//end testNeitherIdAnswers()
}//end class
