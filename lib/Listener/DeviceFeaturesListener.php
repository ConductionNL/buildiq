<?php

/**
 * Device features listener.
 *
 * Before an ApplicationVersion is stored, its `deviceFeatures[]` is derived
 * from its manifest, whichever screen or service saved it, so the responses
 * that serve the built app allow exactly the features its pages use
 * (pages-qr-and-barcodes REQ-BQQR-004).
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Service\DeviceFeatures;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stamps an ApplicationVersion's device features from its manifest.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
 */
class DeviceFeaturesListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolverInterface $slugs Resolves register and schema slugs.
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolverInterface $slugs,
	) {
	}//end __construct()

	/**
	 * Handle an object about to be stored.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		} else if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		} else {
			return;
		}

		if ($this->slugs->registerSlug(entity: $entity) !== ObjectSchemaSlugResolverInterface::REGISTER_SLUG
			|| $this->slugs->schemaSlug(entity: $entity) !== ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA
		) {
			return;
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return;
		}

		$manifest = $data['manifest'] ?? [];
		if (is_object($manifest) === true) {
			$manifest = json_decode((string)json_encode($manifest), true);
		}

		$features = DeviceFeatures::derive(manifest: (array)$manifest);
		if (($data['deviceFeatures'] ?? null) === $features) {
			return;
		}

		$data['deviceFeatures'] = $features;
		$entity->setObject($data);
	}//end handle()
}//end class
