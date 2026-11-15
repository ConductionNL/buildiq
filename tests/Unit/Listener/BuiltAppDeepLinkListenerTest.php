<?php

/**
 * Unit tests for BuiltAppDeepLinkListener.
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
use OCA\Buildiq\Listener\BuiltAppDeepLinkListener;
use OCA\Buildiq\Service\ManifestDataBinding;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The manifest entry plus one entry per detail page of each production version.
 *
 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-3.1
 */
#[CoversClass(BuiltAppDeepLinkListener::class)]
#[UsesClass(ManifestDataBinding::class)]
final class BuiltAppDeepLinkListenerTest extends TestCase {
	/**
	 * The entry src/manifest.json declares for buildiq's own Application schema.
	 *
	 * @var array<string, mixed>
	 */
	private const MANIFEST_ENTRY = [
		'appId' => 'buildiq',
		'registerSlug' => 'buildiq',
		'schemaSlug' => 'built-app',
		'urlTemplate' => '/apps/buildiq/applications/{uuid}',
		'icon' => '',
		'displayName' => 'App',
	];

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
	 * A detail page `/zaken/:id` on schema `zaak` opens in the built app; the index page gets nothing.
	 *
	 * @return void
	 */
	public function testDetailPageOfTheProductionVersionLinksIntoTheBuiltApp(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			$this->application(slug: 'vergunningen', pages: [
				['id' => 'Zaken', 'route' => '/zaken', 'type' => 'index', 'config' => ['register' => '{registerSlug}', 'schema' => 'zaak']],
				[
					'id' => 'Zaak',
					'title' => 'Zaak',
					'route' => '/zaken/:id',
					'type' => 'detail',
					'config' => ['register' => '{registerSlug}', 'schema' => 'zaak'],
				],
			]),
		]);

		self::assertSame(
			expected: [
				self::MANIFEST_ENTRY,
				[
					'appId' => 'buildiq',
					'registerSlug' => ManifestDataBinding::registerSlug(appSlug: 'vergunningen', versionSlug: 'v1'),
					'schemaSlug' => 'zaak',
					'urlTemplate' => '/apps/buildiq/builder/vergunningen/zaken/{uuid}',
					'icon' => '',
					'displayName' => 'Zaak',
				],
			],
			actual: $this->registered()
		);
	}//end testDetailPageOfTheProductionVersionLinksIntoTheBuiltApp()

	/**
	 * A concrete register on the page wins; a stored version register replaces a template token.
	 *
	 * @return void
	 */
	public function testPageRegisterAndStoredVersionRegisterAreUsed(): void {
		$concrete = $this->application(slug: 'a', pages: [
			['route' => '/x/:objectId', 'type' => 'detail', 'config' => ['register' => 'own-register', 'schema' => 's']],
		]);
		$stored = $this->application(slug: 'b', pages: [
			['route' => 'y/:id', 'type' => 'detail', 'config' => ['register' => '{{register}}', 'schema' => 't']],
		]);
		$stored['productionVersion']['register'] = 'stored-register';
		$this->applications->method('getPublishedApplications')->willReturn([$concrete, $stored]);

		$links = array_slice($this->registered(), 1);

		self::assertSame(expected: ['own-register', 'stored-register'], actual: array_column($links, 'registerSlug'));
		self::assertSame(
			expected: ['/apps/buildiq/builder/a/x/{uuid}', '/apps/buildiq/builder/b/y/{uuid}'],
			actual: array_column($links, 'urlTemplate')
		);
		self::assertSame(expected: [null, null], actual: array_column($links, 'displayName'));
	}//end testPageRegisterAndStoredVersionRegisterAreUsed()

	/**
	 * A detail page without a route parameter, or with two, yields no entry.
	 *
	 * @return void
	 */
	public function testDetailPageWithoutExactlyOneParameterIsSkipped(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			$this->application(slug: 'vergunningen', pages: [
				['route' => '/instellingen', 'type' => 'detail', 'config' => ['register' => 'r', 'schema' => 's']],
				['route' => '/zaken/:zaak/taken/:id', 'type' => 'detail', 'config' => ['register' => 'r', 'schema' => 's']],
				['route' => '/zaken/:id', 'type' => 'detail', 'config' => ['register' => 'r']],
				['route' => '/zaken/:id', 'type' => 'detail', 'config' => ['schema' => 's']],
			]),
		]);

		self::assertSame(expected: [self::MANIFEST_ENTRY], actual: $this->registered());
	}//end testDetailPageWithoutExactlyOneParameterIsSkipped()

	/**
	 * No published apps yields only the manifest entry.
	 *
	 * @return void
	 */
	public function testNoPublishedAppsYieldsOnlyTheManifestEntry(): void {
		$this->applications->method('getPublishedApplications')->willReturn([]);

		self::assertSame(expected: [self::MANIFEST_ENTRY], actual: $this->registered());
	}//end testNoPublishedAppsYieldsOnlyTheManifestEntry()

	/**
	 * An app without a production version yields nothing, even with an app-level manifest.
	 *
	 * @return void
	 */
	public function testAppWithoutProductionVersionYieldsNothing(): void {
		$detail = ['route' => '/zaken/:id', 'type' => 'detail', 'config' => ['register' => 'r', 'schema' => 's']];
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'draft-only', 'productionVersion' => null, 'manifest' => ['pages' => [$detail]]],
			['slug' => '', 'productionVersion' => ['slug' => 'v1', 'manifest' => ['pages' => [$detail]]]],
		]);
		$this->resolver->expects($this->never())->method('resolve');

		self::assertSame(expected: [self::MANIFEST_ENTRY], actual: $this->registered());
	}//end testAppWithoutProductionVersionYieldsNothing()

	/**
	 * A bare production-version UUID is resolved through the resolver without caller filtering.
	 *
	 * @return void
	 */
	public function testBareProductionVersionIsResolvedWithoutCaller(): void {
		$this->applications->method('getPublishedApplications')->willReturn([
			['slug' => 'vergunningen', 'productionVersion' => 'a0b1c2d3-0000-4000-8000-000000000000'],
		]);
		$this->resolver->expects($this->once())->method('resolve')
			->with('vergunningen', null, null)
			->willReturn([
				'pages' => [
					['route' => '/zaken/:id', 'type' => 'detail', 'config' => ['register' => 'openbuild-vergunningen-v1', 'schema' => 'zaak']],
					['route' => '/taken/:id', 'type' => 'detail', 'config' => ['register' => '{registerSlug}', 'schema' => 'taak']],
				],
			]);

		$links = array_slice($this->registered(), 1);

		self::assertSame(expected: ['zaak'], actual: array_column($links, 'schemaSlug'));
		self::assertSame(expected: ['openbuild-vergunningen-v1'], actual: array_column($links, 'registerSlug'));
	}//end testBareProductionVersionIsResolvedWithoutCaller()

	/**
	 * An unreadable published-app list keeps the manifest entry and logs a warning.
	 *
	 * @return void
	 */
	public function testUnreadableApplicationsKeepTheManifestEntry(): void {
		$this->applications->method('getPublishedApplications')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'published applications unreadable: down'));

		self::assertSame(expected: [self::MANIFEST_ENTRY], actual: $this->registered());
	}//end testUnreadableApplicationsKeepTheManifestEntry()

	/**
	 * A non-matching event is ignored without reading anything.
	 *
	 * @return void
	 */
	public function testNonMatchingEventIsIgnored(): void {
		$this->applications->expects($this->never())->method('getPublishedApplications');
		$event = $this->getMockBuilder(className: Event::class)->addMethods(['register'])->getMock();
		$event->expects($this->never())->method($this->anything());

		$this->listener()->handle($event);
	}//end testNonMatchingEventIsIgnored()

	/**
	 * A published Application whose embedded production version `v1` holds the given pages.
	 *
	 * @param string $slug The Application slug.
	 * @param array<int, array<string, mixed>> $pages The production manifest's pages.
	 *
	 * @return array<string, mixed>
	 */
	private function application(string $slug, array $pages): array {
		return [
			'slug' => $slug,
			'status' => 'published',
			'productionVersion' => ['slug' => 'v1', 'manifest' => ['pages' => $pages]],
		];
	}//end application()

	/**
	 * Handle a DeepLinkRegistrationEvent and return every register() call, by argument name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function registered(): array {
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

		$this->listener()->handle($event);

		return $calls;
	}//end registered()

	/**
	 * The listener reading buildiq's real src/manifest.json.
	 *
	 * @return BuiltAppDeepLinkListener
	 */
	private function listener(): BuiltAppDeepLinkListener {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn((string)realpath(__DIR__ . '/../../..'));

		return new BuiltAppDeepLinkListener(
			appId: 'buildiq',
			appManager: $appManager,
			logger: $this->logger,
			applications: $this->applications,
			manifestResolver: $this->resolver
		);
	}//end listener()
}//end class
