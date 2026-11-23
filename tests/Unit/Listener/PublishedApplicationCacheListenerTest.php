<?php

/**
 * Unit tests for PublishedApplicationCacheListener.
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

use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Kernel\Contracts\PublishedApplicationProviderInterface;
use OCA\Buildiq\Listener\PublishedApplicationCacheListener;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A write to a Buildiq Application drops the published-Application cache; any other write does not.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
 */
#[CoversClass(PublishedApplicationCacheListener::class)]
final class PublishedApplicationCacheListenerTest extends TestCase {

	/**
	 * Build the listener over a provider that expects $invalidations calls.
	 *
	 * @param bool $isApplication Whether the written object is a Buildiq Application.
	 * @param int  $invalidations How often invalidate() must be called.
	 *
	 * @return PublishedApplicationCacheListener The listener.
	 */
	private function listener(bool $isApplication, int $invalidations): PublishedApplicationCacheListener {
		$applications = $this->createMock(PublishedApplicationProviderInterface::class);
		$applications->expects($this->exactly($invalidations))->method('invalidate');

		$slugs = $this->createMock(ObjectSchemaSlugResolverInterface::class);
		$slugs->method('isBuildiqSchema')
			->with($this->isInstanceOf(ObjectEntity::class), PublishedApplicationProvider::APPLICATION_SCHEMA)
			->willReturn($isApplication);

		return new PublishedApplicationCacheListener(applications: $applications, slugs: $slugs);
	}//end listener()

	/**
	 * Create, update and delete of an Application each drop the cache.
	 *
	 * @return void
	 */
	public function testAWriteToAnApplicationDropsTheCache(): void {
		$listener = $this->listener(isApplication: true, invalidations: 3);

		$listener->handle(new ObjectCreatedEvent(new ObjectEntity()));
		$listener->handle(new ObjectUpdatedEvent(new ObjectEntity()));
		$listener->handle(new ObjectDeletedEvent(new ObjectEntity()));
	}//end testAWriteToAnApplicationDropsTheCache()

	/**
	 * A write to another schema, or another event, keeps the cache.
	 *
	 * @return void
	 */
	public function testOtherWritesAndEventsKeepTheCache(): void {
		$this->listener(isApplication: false, invalidations: 0)
			->handle(new ObjectUpdatedEvent(new ObjectEntity()));

		$this->listener(isApplication: true, invalidations: 0)
			->handle(new Event());
	}//end testOtherWritesAndEventsKeepTheCache()
}//end class
