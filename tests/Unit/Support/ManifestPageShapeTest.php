<?php

/**
 * Unit tests for ManifestPageShape.
 *
 * Every case here is a shape a real model produced on the live instance on
 * 2026-09-18, from a brief asking for a tool library, with the tool catalogue
 * followed exactly. Each one made the whole manifest fail the canonical
 * validator, so the wizard refused to create the app.
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

use OCA\Buildiq\Support\ManifestPageShape;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestPageShape.
 */
class ManifestPageShapeTest extends TestCase {

	/**
	 * A form page whose fields are bare property names gets objects the
	 * validator accepts, keeping the names it was given.
	 *
	 * @return void
	 */
	public function testFormFieldNamesBecomeFieldObjects(): void {
		$page = ManifestPageShape::normalise(page: [
			'id' => 'loan-form',
			'type' => 'form',
			'config' => ['register' => 'loan', 'schema' => 'loan', 'fields' => ['tool', 'borrowedOn', 'asset_tag']],
		]);

		self::assertSame(
			[
				['key' => 'tool', 'label' => 'Tool', 'type' => 'string'],
				['key' => 'borrowedOn', 'label' => 'Borrowed on', 'type' => 'string'],
				['key' => 'asset_tag', 'label' => 'Asset tag', 'type' => 'string'],
			],
			$page['config']['fields']
		);
	}//end testFormFieldNamesBecomeFieldObjects()

	/**
	 * A form page that names no submit destination posts to the collection it
	 * already says it belongs to.
	 *
	 * @return void
	 */
	public function testAFormWithNoDestinationPostsToItsOwnCollection(): void {
		$page = ManifestPageShape::normalise(page: [
			'id' => 'loan-form',
			'type' => 'form',
			'config' => ['register' => 'tool-library', 'schema' => 'loan', 'fields' => ['tool']],
		]);

		self::assertSame('/apps/openregister/api/objects/tool-library/loan', $page['config']['submitEndpoint']);
	}//end testAFormWithNoDestinationPostsToItsOwnCollection()

	/**
	 * A destination the model did name is left exactly as it stands.
	 *
	 * @return void
	 */
	public function testAnExplicitSubmitDestinationIsNotTouched(): void {
		$withHandler = ManifestPageShape::normalise(page: [
			'type' => 'form',
			'config' => ['register' => 'r', 'schema' => 's', 'submitHandler' => 'saveLoan', 'fields' => ['tool']],
		]);
		$withEndpoint = ManifestPageShape::normalise(page: [
			'type' => 'form',
			'config' => ['register' => 'r', 'schema' => 's', 'submitEndpoint' => '/api/objects/a/b', 'fields' => ['tool']],
		]);

		self::assertArrayNotHasKey('submitEndpoint', $withHandler['config']);
		self::assertSame('saveLoan', $withHandler['config']['submitHandler']);
		self::assertSame('/api/objects/a/b', $withEndpoint['config']['submitEndpoint']);
	}//end testAnExplicitSubmitDestinationIsNotTouched()

	/**
	 * With no register and schema to go on, nothing is invented: the page stays
	 * as it was and the validator's message reaches the reader.
	 *
	 * @return void
	 */
	public function testNoDestinationIsInventedWithoutARegisterAndSchema(): void {
		$page = ManifestPageShape::normalise(page: ['type' => 'form', 'config' => ['fields' => ['tool']]]);

		self::assertArrayNotHasKey('submitEndpoint', $page['config']);
		self::assertArrayNotHasKey('submitHandler', $page['config']);
	}//end testNoDestinationIsInventedWithoutARegisterAndSchema()

	/**
	 * A dashboard whose `layout` names a style rather than placements loses the
	 * key, because it carries nothing the renderer can use and fails the whole
	 * manifest.
	 *
	 * @return void
	 */
	public function testADashboardLayoutThatIsNotAListIsDropped(): void {
		$page = ManifestPageShape::normalise(page: [
			'id' => 'dashboard',
			'type' => 'dashboard',
			'config' => ['layout' => 'grid', 'widgets' => []],
		]);

		self::assertArrayNotHasKey('layout', $page['config']);
		self::assertSame([], $page['config']['widgets']);
	}//end testADashboardLayoutThatIsNotAListIsDropped()

	/**
	 * Index and detail pages are handed back untouched: nothing on them is
	 * required by the validator that the tool does not already write.
	 *
	 * @return void
	 */
	public function testOtherPageTypesAreLeftAlone(): void {
		$config = ['register' => 'tool-library', 'schema' => 'tool', 'columns' => ['name', 'status']];
		$page = ['id' => 'tools', 'route' => '/tools', 'type' => 'index', 'title' => 'Tools', 'config' => $config];

		self::assertSame($page, ManifestPageShape::normalise(page: $page));
	}//end testOtherPageTypesAreLeftAlone()

	/**
	 * A field object that is already well formed keeps its own label and type.
	 *
	 * @return void
	 */
	public function testAWellFormedFieldKeepsItsLabelAndType(): void {
		$page = ManifestPageShape::normalise(page: [
			'type' => 'form',
			'config' => [
				'submitHandler' => 'save',
				'fields' => [['key' => 'active', 'label' => 'Currently lending', 'type' => 'boolean']],
			],
		]);

		self::assertSame(
			[['key' => 'active', 'label' => 'Currently lending', 'type' => 'boolean']],
			$page['config']['fields']
		);
	}//end testAWellFormedFieldKeepsItsLabelAndType()

	/**
	 * A field type the validator does not know falls back to a string rather
	 * than failing the manifest.
	 *
	 * @return void
	 */
	public function testAnUnknownFieldTypeFallsBackToString(): void {
		$page = ManifestPageShape::normalise(page: [
			'type' => 'form',
			'config' => ['submitHandler' => 'save', 'fields' => [['key' => 'dueOn', 'type' => 'date']]],
		]);

		self::assertSame('string', $page['config']['fields'][0]['type']);
		self::assertSame('Due on', $page['config']['fields'][0]['label']);
	}//end testAnUnknownFieldTypeFallsBackToString()
}//end class
