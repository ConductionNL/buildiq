<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\PageRegisterBinding;
use PHPUnit\Framework\TestCase;

/**
 * A cloned template's pages read the new app's own register.
 *
 * @spec openspec/specs/openbuild-template-catalogue/spec.md
 */
class PageRegisterBindingTest extends TestCase {

	/**
	 * Both tokens, and a page with a schema and no register, read the app's register.
	 *
	 * @return void
	 */
	public function testATemplatePageReadsTheAppsRegister(): void {
		$manifest = [
			'menu' => [['id' => 'Incidents']],
			'pages' => [
				['id' => 'Form', 'config' => ['schema' => 'pw-incident']],
				['id' => 'Detail', 'config' => ['register' => '{{register}}', 'schema' => 'pw-incident']],
				['id' => 'Old', 'config' => ['register' => '{registerSlug}', 'schema' => 'pw-incident']],
				['id' => 'Empty', 'config' => ['register' => '', 'schema' => 'pw-incident']],
			],
		];

		$bound = PageRegisterBinding::forManifest(manifest: $manifest, registerSlug: 'openbuild-pw');

		foreach ($bound['pages'] as $page) {
			$this->assertSame(expected: 'openbuild-pw', actual: $page['config']['register'], message: $page['id']);
			$this->assertSame(expected: 'pw-incident', actual: $page['config']['schema'], message: $page['id']);
		}

		$this->assertSame(expected: $manifest['menu'], actual: $bound['menu'], message: 'the rest of the manifest is kept');
	}//end testATemplatePageReadsTheAppsRegister()

	/**
	 * A named register, a page without a schema and a page without config are left alone.
	 *
	 * @return void
	 */
	public function testOtherPagesAreLeftAlone(): void {
		$manifest = [
			'pages' => [
				['id' => 'Shared', 'config' => ['register' => 'buildiq', 'schema' => 'hello-message']],
				['id' => 'Dashboard', 'config' => ['widgets' => []]],
				['id' => 'Bare'],
				'not-a-page',
			],
		];

		$this->assertSame(expected: $manifest, actual: PageRegisterBinding::forManifest(manifest: $manifest, registerSlug: 'openbuild-pw'));
		$this->assertSame(expected: ['register' => '{{register}}', 'schema' => 's'], actual: PageRegisterBinding::forConfig(config: ['register' => '{{register}}', 'schema' => 's'], registerSlug: ''), message: 'no register to point at, nothing changes');
		$this->assertSame(expected: ['title' => 'x'], actual: PageRegisterBinding::forManifest(manifest: ['title' => 'x'], registerSlug: 'openbuild-pw'));
	}//end testOtherPagesAreLeftAlone()
}//end class
