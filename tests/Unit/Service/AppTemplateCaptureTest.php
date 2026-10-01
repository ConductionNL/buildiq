<?php

/**
 * Unit tests for AppTemplateCapture, the PHP port of the de-namespace step
 * of src/services/templateCapture.js (change apps-copy-app-and-page, T01).
 *
 * The cases are the ones tests/vitest/templateCapture.spec.js runs against
 * the JS module, so both sides agree on the round trip: capture strips the
 * source app's slug prefix, clone adds the new one, and the composition is
 * a clean rename.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\AppTemplateCapture;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AppTemplateCapture.
 */
class AppTemplateCaptureTest extends TestCase {

	/**
	 * The source app's manifest: three references to one companion schema.
	 *
	 * @return array<string,mixed>
	 */
	private function manifest(): array {
		return [
			'pages' => [
				['id' => 'index', 'type' => 'index', 'config' => ['schema' => 'my-permits-permit-application']],
				['id' => 'form', 'type' => 'form', 'config' => ['schema' => 'my-permits-permit-application']],
			],
			'runtime' => [
				'theme' => 'nldesign',
				'documents' => [['template' => 'decision', 'schema' => 'my-permits-permit-application']],
			],
		];

	}//end manifest()

	/**
	 * The source app's companion schemas.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function schemas(): array {
		return [['slug' => 'my-permits-permit-application', 'title' => 'Permit', 'properties' => []]];

	}//end schemas()

	/**
	 * Companion schemas are de-namespaced and every manifest reference with them.
	 *
	 * @return void
	 */
	public function testDeNamespacesSchemasAndEveryManifestReference(): void {
		$template = (new AppTemplateCapture())->capture(
			application: ['slug' => 'my-permits', 'description' => 'Permits'],
			schemas: $this->schemas(),
			manifest: $this->manifest()
		);

		self::assertSame('permit-application', $template['companionSchemas'][0]['slug']);
		self::assertSame('permit-application', $template['manifest']['pages'][0]['config']['schema']);
		self::assertSame('permit-application', $template['manifest']['pages'][1]['config']['schema']);
		self::assertSame('permit-application', $template['manifest']['runtime']['documents'][0]['schema']);
		self::assertSame('nldesign', $template['manifest']['runtime']['theme']);
		self::assertSame('Permits', $template['description']);

	}//end testDeNamespacesSchemasAndEveryManifestReference()

	/**
	 * Capture then the clone's prefix is a clean rename, with no stacked prefix.
	 *
	 * @return void
	 */
	public function testCaptureThenCloneIsACleanRename(): void {
		$capture = new AppTemplateCapture();
		$template = $capture->capture(application: ['slug' => 'my-permits'], schemas: $this->schemas(), manifest: $this->manifest());

		// The clone controller's transform: prefix every companion with the new slug.
		$map = [];
		foreach ($template['companionSchemas'] as $schema) {
			$map[$schema['slug']] = 'vggm-permits-' . $schema['slug'];
		}

		$cloned = $capture->rewriteSchemaRefs(node: $template['manifest'], map: $map);

		self::assertSame('vggm-permits-permit-application', $cloned['pages'][0]['config']['schema']);
		self::assertSame('vggm-permits-permit-application', $cloned['runtime']['documents'][0]['schema']);

	}//end testCaptureThenCloneIsACleanRename()

	/**
	 * An unprefixed schema is captured unchanged and reported as shared.
	 *
	 * @return void
	 */
	public function testAnUnprefixedSchemaIsCapturedUnchanged(): void {
		$capture = new AppTemplateCapture();
		self::assertSame(['slug' => 'foo', 'shared' => false], $capture->deNamespaceSlug(schemaSlug: 'app-foo', appSlug: 'app'));
		self::assertSame(['slug' => 'foo', 'shared' => true], $capture->deNamespaceSlug(schemaSlug: 'foo', appSlug: 'app'));

		$schemas = array_merge($this->schemas(), [['slug' => 'shared-contacts', 'title' => 'Contacts', 'properties' => []]]);
		$template = $capture->capture(application: ['slug' => 'my-permits'], schemas: $schemas, manifest: $this->manifest());

		self::assertSame(['permit-application', 'shared-contacts'], array_column($template['companionSchemas'], 'slug'));

	}//end testAnUnprefixedSchemaIsCapturedUnchanged()

	/**
	 * Two schemas that de-namespace to one slug fail loudly, naming both.
	 *
	 * @return void
	 */
	public function testACollisionNamesBothSchemas(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('slug-collision: my-permits-tasks, tasks');

		(new AppTemplateCapture())->capture(
			application: ['slug' => 'my-permits'],
			schemas: [['slug' => 'my-permits-tasks'], ['slug' => 'tasks']],
			manifest: []
		);

	}//end testACollisionNamesBothSchemas()

	/**
	 * Capture copies definitions only and leaves its inputs untouched.
	 *
	 * @return void
	 */
	public function testNoRowsAndNoMutation(): void {
		$manifest = $this->manifest();
		$schemas = $this->schemas();
		$template = (new AppTemplateCapture())->capture(
			application: ['slug' => 'my-permits', 'objects' => [['id' => 1]]],
			schemas: $schemas,
			manifest: $manifest
		);

		self::assertArrayNotHasKey('objects', $template);
		self::assertSame($this->manifest(), $manifest);
		self::assertSame($this->schemas(), $schemas);

	}//end testNoRowsAndNoMutation()
}//end class
