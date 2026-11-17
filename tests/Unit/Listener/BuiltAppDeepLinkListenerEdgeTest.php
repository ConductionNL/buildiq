<?php

/**
 * Edge-path unit tests for BuiltAppDeepLinkListener.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Kernel\Values\VersionRegisterSlug;
use OCA\Buildiq\Listener\BuiltAppDeepLinkListener;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Malformed production versions and pages register nothing beyond the manifest entry.
 *
 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-3.1
 */
#[CoversClass(BuiltAppDeepLinkListener::class)]
#[UsesClass(VersionRegisterSlug::class)]
final class BuiltAppDeepLinkListenerEdgeTest extends TestCase {
	/**
	 * A valid detail page.
	 *
	 * @var array<string, mixed>
	 */
	private const DETAIL = ['route' => '/zaken/:id', 'type' => 'detail', 'config' => ['register' => 'r', 'schema' => 's']];

	/**
	 * Published applications source.
	 *
	 * @var PublishedApplicationProvider&MockObject
	 */
	private PublishedApplicationProvider&MockObject $applications;

	/**
	 * Production manifest resolver.
	 *
	 * @var ManifestResolverServiceInterface&MockObject
	 */
	private ManifestResolverServiceInterface&MockObject $resolver;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->applications = $this->createMock(originalClassName: PublishedApplicationProvider::class);
		$this->resolver = $this->createMock(originalClassName: ManifestResolverServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * A production manifest whose `pages` is not a list links nothing.
	 *
	 * @return void
	 */
	public function testPagesThatAreNotAListLinkNothing(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => ['slug' => 'v1', 'manifest' => ['pages' => 'none']]],
		]);

		self::assertSame(expected: [], actual: $this->appLinks());
	}//end testPagesThatAreNotAListLinkNothing()

	/**
	 * A page entry that is not an object is skipped; the valid page next to it still links.
	 *
	 * @return void
	 */
	public function testANonArrayPageIsSkippedAndTheNextOneLinks(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => ['slug' => 'v1', 'manifest' => ['pages' => ['broken', self::DETAIL]]]],
		]);

		self::assertSame(
			expected: [[
				'appId' => 'buildiq',
				'registerSlug' => 'r',
				'schemaSlug' => 's',
				'urlTemplate' => '/apps/buildiq/builder/a/zaken/{uuid}',
				'icon' => '',
				'displayName' => null,
			],
			],
			actual: $this->appLinks()
		);
	}//end testANonArrayPageIsSkippedAndTheNextOneLinks()

	/**
	 * An embedded production version without a manifest links nothing and never resolves.
	 *
	 * @return void
	 */
	public function testEmbeddedVersionWithoutManifestLinksNothing(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => ['slug' => 'v1', 'manifest' => null]],
		]);
		$this->resolver->expects($this->never())->method('resolve');

		self::assertSame(expected: [], actual: $this->appLinks());
	}//end testEmbeddedVersionWithoutManifestLinksNothing()

	/**
	 * A resolver failure for a bare version pointer is logged and that app links nothing.
	 *
	 * @return void
	 */
	public function testResolverFailureIsLoggedAndLinksNothing(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => 'a0b1c2d3-0000-4000-8000-000000000000'],
		]);
		$this->resolver->method('resolve')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'production manifest of a unreadable for deep links: gone'));

		self::assertSame(expected: [], actual: $this->appLinks());
	}//end testResolverFailureIsLoggedAndLinksNothing()

	/**
	 * A bare version pointer the resolver cannot find links nothing.
	 *
	 * @return void
	 */
	public function testUnresolvedBareVersionLinksNothing(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => 'a0b1c2d3-0000-4000-8000-000000000000'],
		]);
		$this->resolver->method('resolve')->willReturn(null);

		self::assertSame(expected: [], actual: $this->appLinks());
	}//end testUnresolvedBareVersionLinksNothing()

	/**
	 * A detail page whose `config` is not an object links nothing.
	 *
	 * @return void
	 */
	public function testDetailPageWithoutConfigObjectLinksNothing(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => ['slug' => 'v1', 'manifest' => ['pages' => [
				['route' => '/zaken/:id', 'type' => 'detail', 'config' => 'zaak'],
			],
			],
			],
			],
		]);

		self::assertSame(expected: [], actual: $this->appLinks());
	}//end testDetailPageWithoutConfigObjectLinksNothing()

	/**
	 * A template register over a numeric stored register falls back to the per-version slug.
	 *
	 * @return void
	 */
	public function testNumericStoredRegisterFallsBackToTheVersionSlug(): void {
		$page = ['route' => 'zaken/:id', 'type' => 'detail', 'title' => 'Zaak', 'config' => ['register' => '{registerSlug}', 'schema' => 's']];
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => ['slug' => 'v2', 'register' => '42', 'manifest' => ['pages' => [$page]]]],
		]);

		$links = $this->appLinks();

		self::assertSame(expected: [VersionRegisterSlug::forVersion(appSlug: 'a', versionSlug: 'v2')], actual: array_column($links, 'registerSlug'));
		self::assertSame(expected: ['/apps/buildiq/builder/a/zaken/{uuid}'], actual: array_column($links, 'urlTemplate'));
		self::assertSame(expected: ['Zaak'], actual: array_column($links, 'displayName'));
	}//end testNumericStoredRegisterFallsBackToTheVersionSlug()

	/**
	 * A template register with neither a stored register nor a version slug links nothing.
	 *
	 * @return void
	 */
	public function testTemplateRegisterWithoutVersionSlugLinksNothing(): void {
		$page = ['route' => '/zaken/:id', 'type' => 'detail', 'config' => ['register' => '{registerSlug}', 'schema' => 's']];
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'a', 'productionVersion' => ['manifest' => ['pages' => [$page]]]],
		]);

		self::assertSame(expected: [], actual: $this->appLinks());
	}//end testTemplateRegisterWithoutVersionSlugLinksNothing()

	/**
	 * Handle a DeepLinkRegistrationEvent and return the register() calls after the manifest entry.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function appLinks(): array {
		$calls = [];
		$event = $this->createMock(originalClassName: DeepLinkRegistrationEvent::class);
		$event->method('register')->willReturnCallback(
			static function (
				string $appId,
				string $registerSlug,
				string $schemaSlug,
				string $urlTemplate,
				string $icon = '',
				?string $displayName = null,
			) use (&$calls): void {
				$calls[] = compact('appId', 'registerSlug', 'schemaSlug', 'urlTemplate', 'icon', 'displayName');
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn((string)realpath(__DIR__ . '/../../..'));
		$listener = new BuiltAppDeepLinkListener(
			appId: 'buildiq',
			appManager: $appManager,
			logger: $this->logger,
			applications: $this->applications,
			manifestResolver: $this->resolver
		);
		$listener->handle($event);

		// The first call is buildiq's own manifest entry for its Application schema.
		return array_slice($calls, 1);
	}//end appLinks()
}//end class
