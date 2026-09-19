<?php

/**
 * What buildiq announces to the shared leaf registry.
 *
 * 🔴 IT USED TO ANNOUNCE A WIDGET AND A TAB IT HAD NEVER BUILT.
 * `buildiq-registration-form-panel` declared the render-surface kind with the
 * surfaces `widget` and `tab`, and buildiq had no client half for either: no
 * `registerIntegration` call anywhere in `src/`, no component, and the id
 * appeared nowhere outside its own constant and its own registration. It also
 * ships no leaf bundle and loads no integration script, so nothing on any page
 * could have rendered it.
 *
 * Every consumer was told the widget and the tab existed, `getLeaves()` returned
 * them, and a host page that made room showed an empty space. Nothing failed.
 *
 * 🔑 NOTHING TESTED THIS LISTENER AT ALL, which is why the declaration could sit
 * there. The suite's test and assertion counts were identical before and after
 * removing a whole leaf registration.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.buildiq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\BuildiqLeafRegistrationListener;
use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\Buildiq\Service\LayoutDeltaService;
use OCA\Buildiq\Service\PageLayoutFrozenBase;
use OCA\Buildiq\Service\PageLayoutLayerStack;
use OCA\Buildiq\Service\PageLayoutPresenter;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `BuildiqLeafRegistrationListener`.
 *
 * The five `@uses` below are not decoration. `phpunit.xml` sets
 * `beStrictAboutCoverageMetadata="true"` next to `failOnRisky="true"`, so under
 * a coverage run a test that carries `@covers` and executes any class outside
 * that list is RISKY, and one risky test is exit 1 for the whole suite. This
 * test builds the providers and their collaborators for real, because they are
 * final, so it touches all five. Locally that is invisible: `composer test:unit`
 * passes `--no-coverage` and there is no coverage driver on a dev box, while CI
 * runs the same tests with `--coverage-clover` and every matrix cell goes red.
 * Reproduced with pcov: exit 0 without coverage, exit 1 with it, same 1277 tests.
 *
 * @covers \OCA\Buildiq\Listener\BuildiqLeafRegistrationListener
 *
 * @uses \OCA\Buildiq\Integration\PageLayoutLeafProvider
 * @uses \OCA\Buildiq\Integration\RegistrationFormLeafProvider
 * @uses \OCA\Buildiq\Service\LayoutDeltaService
 * @uses \OCA\Buildiq\Service\PageLayoutFrozenBase
 * @uses \OCA\Buildiq\Service\PageLayoutLayerStack
 */
class BuildiqLeafRegistrationListenerTest extends TestCase {

	/**
	 * Run the listener and return what it announced.
	 *
	 * @return array<int, LeafDescriptor> The descriptors.
	 */
	private function announced(): array {
		$event = new RegisterLeafProvidersEvent();

		// The providers and LayoutDeltaService are all FINAL, so they are
		// constructed rather than mocked.
		// The listener only passes them through to `registerLeaf()`, so real
		// instances over doubled collaborators are the honest cheap option.
		$layers = new PageLayoutLayerStack(
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class)
		);

		$listener = new BuildiqLeafRegistrationListener(
			new RegistrationFormLeafProvider(
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IUserSession::class)
			),
			new PageLayoutLeafProvider(
				$this->createMock(ObjectServiceInterface::class),
				$this->createMock(IAppConfig::class),
				new LayoutDeltaService(),
				$layers,
				new PageLayoutFrozenBase($layers),
				new PageLayoutPresenter(),
				new NullLogger()
			)
		);

		$listener->handle($event);

		return array_map(
			static fn (array $leaf): LeafDescriptor => $leaf['descriptor'],
			$event->getLeaves()
		);
	}//end announced()

	/**
	 * 🔴 BUILDIQ ANNOUNCES NO RENDER SURFACE, BECAUSE IT HAS NONE.
	 *
	 * The guard that stops the unbuilt panel coming back. Announcing a surface
	 * with no client half is worse than announcing nothing: a consumer makes
	 * room for it and shows an empty space, and every check reports success.
	 *
	 * @return void
	 */
	public function testBuildiqAnnouncesNoRenderSurface(): void {
		foreach ($this->announced() as $descriptor) {
			$this->assertNotContains(
				LeafDescriptor::KIND_RENDER_SURFACE,
				$descriptor->getKinds(),
				sprintf(
					'"%s" announces a render surface. buildiq ships no leaf bundle and loads no '
					. 'integration script, so nothing can render it. Build the client half first, '
					. 'or do not announce it.',
					$descriptor->getId()
				)
			);
		}
	}//end testBuildiqAnnouncesNoRenderSurface()

	/**
	 * The data leaves are still announced, each with its provider.
	 *
	 * The control. Without it, a listener that announced nothing at all would
	 * pass the test above while removing two working integrations.
	 *
	 * @return void
	 */
	public function testTheDataLeavesAreStillAnnounced(): void {
		$ids = array_map(
			static fn (LeafDescriptor $descriptor): string => $descriptor->getId(),
			$this->announced()
		);

		$this->assertCount(2, $ids, 'buildiq announces its two data leaves and nothing else.');

		foreach ($this->announced() as $descriptor) {
			$this->assertContains(LeafDescriptor::KIND_DATA_PROVIDER, $descriptor->getKinds());
		}
	}//end testTheDataLeavesAreStillAnnounced()

	/**
	 * The retired id is still recorded, so a consumer that stored it finds out.
	 *
	 * Deleting the constant outright would leave somebody searching a published
	 * id and finding nothing at all, which reads as "never existed" rather than
	 * "withdrawn, and here is why".
	 *
	 * @return void
	 */
	public function testTheRetiredIdIsStillNamed(): void {
		$this->assertSame(
			'buildiq-registration-form-panel',
			BuildiqLeafRegistrationListener::RETIRED_FORM_PANEL_ID
		);
	}//end testTheRetiredIdIsStillNamed()
}//end class
