<?php

/**
 * Unit test for the Integration component's contract bindings.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Integration
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

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\IntegrationRegistrar;
use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Kernel\Contracts\PageLayoutLeafProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Kernel contract a caller asks for resolves to the page-layout leaf.
 */
#[CoversClass(IntegrationRegistrar::class)]
final class IntegrationRegistrarTest extends TestCase {

	/**
	 * The page-layout contract is aliased to its one implementation, and nothing else is registered.
	 *
	 * @return void
	 */
	public function testThePageLayoutContractIsBoundToItsLeafProvider(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())->method('registerServiceAlias')
			->with(PageLayoutLeafProviderInterface::class, PageLayoutLeafProvider::class);
		$context->expects(self::never())->method('registerService');
		$context->expects(self::never())->method('registerEventListener');

		(new IntegrationRegistrar())->registerContractBindings($context);
	}//end testThePageLayoutContractIsBoundToItsLeafProvider()
}//end class
