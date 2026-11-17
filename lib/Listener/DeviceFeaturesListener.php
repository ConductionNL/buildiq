<?php

/**
 * Buildiq DeviceFeaturesListener
 *
 * Before an ApplicationVersion is stored, writes the device features its
 * manifest uses to `deviceFeatures`, so the page that serves the app can
 * allow exactly those browser features without reading the whole manifest.
 * Every other object passes through untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\DeviceFeaturesInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stores an app version's device features on save.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
 */
class DeviceFeaturesListener implements IEventListener {

	/**
	 * Constructor
	 *
	 * @param ObjectSchemaSlugResolverInterface $slugs    Tells an ApplicationVersion apart.
	 * @param DeviceFeaturesInterface           $features Derives the features.
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolverInterface $slugs,
		private readonly DeviceFeaturesInterface $features,
	) {
	}//end __construct()

	/**
	 * Write `deviceFeatures` on an ApplicationVersion that is about to be stored.
	 *
	 * @param Event $event The save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($this->isApplicationVersion(entity: $entity) === false) {
			return;
		}

		$data = $entity->getObject();
		if (is_array($data) === false || array_key_exists('manifest', $data) === false) {
			return;
		}

		$event->setModifiedData(
			array_merge($event->getModifiedData(), ['deviceFeatures' => $this->features->fromManifest(manifest: $data['manifest'])])
		);
	}//end handle()

	/**
	 * The object a save event stores: the new object of an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The save event.
	 *
	 * @return object
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): object {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end entityOf()

	/**
	 * Whether an object is an ApplicationVersion in this app's register.
	 *
	 * @param object $entity The stored object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	private function isApplicationVersion(object $entity): bool {
		return $this->slugs->registerSlug(entity: $entity) === ObjectSchemaSlugResolverInterface::REGISTER_SLUG
			&& $this->slugs->schemaSlug(entity: $entity) === ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA;
	}//end isApplicationVersion()
}//end class
