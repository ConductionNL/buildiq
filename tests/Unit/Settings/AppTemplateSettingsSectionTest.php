<?php

/**
 * Unit tests for the app template's SettingsSection.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
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

namespace OCA\Buildiq\Tests\Unit\Settings;

require_once __DIR__ . '/../../../lib/Resources/template/lib/Sections/SettingsSection.php';

use OCA\AppTemplate\Sections\SettingsSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the template's SettingsSection.
 */
#[CoversClass(SettingsSection::class)]
final class AppTemplateSettingsSectionTest extends TestCase {
	/**
	 * The section has a fixed id and priority, a translated name and the app icon.
	 *
	 * @return void
	 */
	public function testSectionDescribesItself(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('App Template')->willReturn('App-sjabloon');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('imagePath')
			->with('app-template', 'app-dark.svg')
			->willReturn('/apps/app-template/img/app-dark.svg');
		$section = new SettingsSection(l: $l10n, urlGenerator: $urlGenerator);

		$this->assertSame('app-template', $section->getID());
		$this->assertSame('App-sjabloon', $section->getName());
		$this->assertSame(75, $section->getPriority());
		$this->assertSame('/apps/app-template/img/app-dark.svg', $section->getIcon());
	}//end testSectionDescribesItself()
}//end class
