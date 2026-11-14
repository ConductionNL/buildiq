<?php

/**
 * Unit tests for the app template's deep-link registration listener.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file.
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

require_once __DIR__ . '/../../../lib/Resources/template/lib/Listener/DeepLinkRegistrationListener.php';

use OCA\AppTemplate\Listener\DeepLinkRegistrationListener;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The listener registers the template's example deep link, and only on its event.
 */
#[CoversClass(DeepLinkRegistrationListener::class)]
final class AppTemplateDeepLinkRegistrationListenerTest extends TestCase {
	/**
	 * On the registration event the example schema's deep link is registered once.
	 *
	 * @return void
	 */
	public function testRegistersTheExampleDeepLink(): void {
		$event = $this->createMock(DeepLinkRegistrationEvent::class);
		$event->expects($this->once())->method('register')->with(
			'app-template',
			'app-template',
			'example',
			'/apps/app-template/#/examples/{uuid}'
		);

		(new DeepLinkRegistrationListener())->handle($event);
	}//end testRegistersTheExampleDeepLink()

	/**
	 * Any other event is ignored without touching it.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$event = $this->getMockBuilder(Event::class)->addMethods(['register'])->getMock();
		$event->expects($this->never())->method($this->anything());

		(new DeepLinkRegistrationListener())->handle($event);
	}//end testIgnoresOtherEvents()
}//end class
