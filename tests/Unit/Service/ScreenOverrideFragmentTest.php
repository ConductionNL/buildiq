<?php

/**
 * Unit tests for the screen-override half of the page-layouts fragment.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001, REQ-OBSO-002, REQ-OBSO-003, REQ-OBSO-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the override properties, the lifecycle state a drifted override
 * lands in, and that the notification is declared in the canonical dialect the
 * notification-dialect gate accepts.
 */
final class ScreenOverrideFragmentTest extends TestCase {
	/**
	 * Absolute path to the fragment.
	 *
	 * @var string
	 */
	private string $path = __DIR__ . '/../../../lib/Settings/register.d/51-page-layouts.json';

	/**
	 * Decode the pageLayout schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$data = json_decode((string)file_get_contents($this->path), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		return $data['components']['schemas']['pageLayout'];
	}//end schema()

	/**
	 * Every property the resolver reads is declared. OpenRegister DROPS an
	 * undeclared field in silence, so an override saved with a fingerprint the
	 * schema does not know would lose it, and the drift check would then read
	 * every override as never pinned.
	 *
	 * @return void
	 */
	public function testEveryOverridePropertyIsDeclared(): void {
		$properties = $this->schema()['properties'];

		foreach (['name', 'audience', 'baseRef', 'layoutDelta', 'baseFingerprint', 'baseCutAt', 'maintainer'] as $property) {
			self::assertArrayHasKey($property, $properties, $property . ' is missing from pageLayout');
		}
	}//end testEveryOverridePropertyIsDeclared()

	/**
	 * The audience names exactly the five kinds the resolver knows. A sixth in
	 * the schema would be an audience nothing ever resolves, so an override bound
	 * to it would be published, correct-looking, and never applied
	 * (REQ-OBSO-004).
	 *
	 * @return void
	 */
	public function testTheAudienceKindsMatchTheResolver(): void {
		self::assertSame(
			['everyone', 'group', 'team', 'portal', 'user'],
			$this->schema()['properties']['audience']['properties']['kind']['enum']
		);
	}//end testTheAudienceKindsMatchTheResolver()

	/**
	 * `needs-review` is a real lifecycle state, not a status string the code
	 * invents. It is what a drifted override lands in, and what the maintainer
	 * notification watches (REQ-OBSO-003).
	 *
	 * @return void
	 */
	public function testNeedsReviewIsInTheLifecycleAndTheStatusEnum(): void {
		$schema = $this->schema();

		self::assertContains('needs-review', $schema['properties']['status']['enum']);
		self::assertArrayHasKey('needs-review', $schema['x-openregister-lifecycle']['states']);
		self::assertSame('status', $schema['x-openregister-lifecycle']['field']);
	}//end testNeedsReviewIsInTheLifecycleAndTheStatusEnum()

	/**
	 * The drift notification is declared, watches the state a drifted override
	 * lands in, and is addressed to the maintainer. An override whose base moved
	 * and whose maintainer nobody tells is an override nobody re-cuts.
	 *
	 * @return void
	 */
	public function testTheDriftNotificationIsDeclaredAndAddressedToTheMaintainer(): void {
		$notifications = $this->schema()['x-openregister-notifications'];

		self::assertArrayHasKey('driftDetected', $notifications);

		$rule = $notifications['driftDetected'];
		self::assertSame('update', $rule['event']);
		self::assertSame('status', $rule['condition']['field']);
		self::assertSame('needs-review', $rule['condition']['value']);
		self::assertSame('maintainer', $rule['recipients']['field']);
	}//end testTheDriftNotificationIsDeclaredAndAddressedToTheMaintainer()

	/**
	 * A tab carries an id, which is the key an override patches by. Without it
	 * every patch would be an orphan and no override could ever apply
	 * (REQ-OBSO-001).
	 *
	 * @return void
	 */
	public function testATabCarriesTheIdAnOverridePatchesBy(): void {
		$tabs = $this->schema()['properties']['tabs'];

		self::assertArrayHasKey('id', $tabs['items']['properties']);
		self::assertContains('id', $tabs['items']['required']);
	}//end testATabCarriesTheIdAnOverridePatchesBy()

	/**
	 * The schema version moved, so the repair step re-imports it rather than
	 * leaving an instance on the shape from before overrides existed.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionMoved(): void {
		self::assertSame('0.3.0', $this->schema()['version']);
	}//end testTheSchemaVersionMoved()
}//end class
