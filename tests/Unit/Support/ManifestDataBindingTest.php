<?php

/**
 * Unit tests for ManifestDataBinding.
 *
 * The short `loan` / `loan` pairs here are what a real model produced on the
 * live instance on 2026-09-18: it asked `upsertSchema` for a schema called
 * `loan`, then wrote `loan` again in every page config and widget config.
 * `upsertSchema` stored that schema as `tool-library-development-loan` inside
 * `openbuild-tool-library-development`, nothing rewrote the pages, and the
 * created app opened with every list page and every KPI card empty.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\ManifestDataBinding;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestDataBinding.
 */
class ManifestDataBindingTest extends TestCase {

	/**
	 * An index page's short register/schema pair is pointed at the version's
	 * own register and its namespaced schema.
	 *
	 * @return void
	 */
	public function testAShortPairIsPointedAtTheVersionsOwnData(): void {
		$bound = ManifestDataBinding::bindBlock(
			config: ['register' => 'loan', 'schema' => 'loan', 'columns' => ['member', 'tool']],
			appSlug: 'tool-library',
			versionSlug: 'development'
		);

		self::assertSame('openbuild-tool-library-development', $bound['register']);
		self::assertSame('tool-library-development-loan', $bound['schema']);
		self::assertSame(['member', 'tool'], $bound['columns']);
	}//end testAShortPairIsPointedAtTheVersionsOwnData()

	/**
	 * A dashboard's binding lives on each widget's content, one level in, and
	 * is reached too.
	 *
	 * @return void
	 */
	public function testAWidgetsOwnBindingIsReached(): void {
		$bound = ManifestDataBinding::bindBlock(
			config: [
				'widgets' => [
					['id' => 'loans-open', 'type' => 'stat', 'content' => ['register' => 'loan', 'schema' => 'loan', 'aggregate' => 'count']],
				],
			],
			appSlug: 'tool-library',
			versionSlug: 'development'
		);

		self::assertSame('openbuild-tool-library-development', $bound['widgets'][0]['content']['register']);
		self::assertSame('tool-library-development-loan', $bound['widgets'][0]['content']['schema']);
	}//end testAWidgetsOwnBindingIsReached()

	/**
	 * A block naming a schema but no register gets this version's register,
	 * because that is the only register the schema could live in.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterIsFilledIn(): void {
		$bound = ManifestDataBinding::bindBlock(
			config: ['schema' => 'tool'],
			appSlug: 'tool-library',
			versionSlug: 'development'
		);

		self::assertSame('openbuild-tool-library-development', $bound['register']);
		self::assertSame('tool-library-development-tool', $bound['schema']);
	}//end testAnAbsentRegisterIsFilledIn()

	/**
	 * Binding a block twice changes nothing the first pass did — the service
	 * runs it at plan time and again at execute time.
	 *
	 * @return void
	 */
	public function testBindingIsIdempotent(): void {
		$once = ManifestDataBinding::bindBlock(
			config: ['register' => 'loan', 'schema' => 'loan'],
			appSlug: 'tool-library',
			versionSlug: 'development'
		);
		$twice = ManifestDataBinding::bindBlock(
			config: $once,
			appSlug: 'tool-library',
			versionSlug: 'development'
		);

		self::assertSame($once, $twice);
	}//end testBindingIsIdempotent()

	/**
	 * The production version's pages read production's data, not
	 * development's — the bug buildiq#75 was opened for.
	 *
	 * @return void
	 */
	public function testEachVersionBindsToItsOwnData(): void {
		$production = ManifestDataBinding::bindBlock(
			config: ['register' => 'loan', 'schema' => 'loan'],
			appSlug: 'tool-library',
			versionSlug: 'production'
		);

		self::assertSame('openbuild-tool-library-production', $production['register']);
		self::assertSame('tool-library-production-loan', $production['schema']);
	}//end testEachVersionBindsToItsOwnData()

	/**
	 * A register somebody named on purpose is left alone, and so is a schema
	 * id, which is already unambiguous.
	 *
	 * @return void
	 */
	public function testADeliberateRegisterAndASchemaIdAreLeftAlone(): void {
		$bound = ManifestDataBinding::bindBlock(
			config: ['register' => 'openbuild-other-app-production', 'schema' => '4212'],
			appSlug: 'tool-library',
			versionSlug: 'development'
		);

		self::assertSame('openbuild-other-app-production', $bound['register']);
		self::assertSame('4212', $bound['schema']);
	}//end testADeliberateRegisterAndASchemaIdAreLeftAlone()

	/**
	 * A block that names no data at all is handed back untouched: nothing is
	 * invented for a page that never asked to read anything.
	 *
	 * @return void
	 */
	public function testABlockWithNoBindingIsUntouched(): void {
		$config = ['fields' => [['key' => 'member', 'label' => 'Member', 'type' => 'string']]];

		self::assertSame(
			$config,
			ManifestDataBinding::bindBlock(config: $config, appSlug: 'tool-library', versionSlug: 'development')
		);
	}//end testABlockWithNoBindingIsUntouched()
}//end class
