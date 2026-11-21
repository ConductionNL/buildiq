<?php

/**
 * Unit tests for LayoutDeltaService.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001, REQ-OBSO-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\LayoutDeltaService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the keyed merge, the deletion marker, the reorder and the fingerprint.
 */
final class LayoutDeltaServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var LayoutDeltaService
	 */
	private LayoutDeltaService $deltas;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->deltas = new LayoutDeltaService();
	}//end setUp()

	/**
	 * A base of four tabs.
	 *
	 * @return array<string, mixed> The base.
	 */
	private function base(): array {
		return [
			'tabs' => [
				['id' => 'gegevens', 'kind' => 'fieldGroup', 'label' => 'Gegevens'],
				['id' => 'documenten', 'kind' => 'leaf', 'label' => 'Documenten', 'ref' => 'filinq-documents'],
				['id' => 'taken', 'kind' => 'relatedList', 'label' => 'Taken'],
				['id' => 'historie', 'kind' => 'widgets', 'label' => 'Historie'],
			],
		];
	}//end base()

	/**
	 * A patch names one tab, and the other three come through untouched. That is
	 * the whole reason a patch beats a copy (REQ-OBSO-001).
	 *
	 * @return void
	 */
	public function testAPatchOfOneTabLeavesTheOtherThreeAlone(): void {
		$result = $this->deltas->merge($this->base(), ['tabs' => ['documenten' => ['label' => 'Bijlagen']]]);

		self::assertCount(4, $result['tabs']);
		self::assertSame('Bijlagen', $result['tabs'][1]['label']);
		self::assertSame('filinq-documents', $result['tabs'][1]['ref']);
		self::assertSame('Gegevens', $result['tabs'][0]['label']);
	}//end testAPatchOfOneTabLeavesTheOtherThreeAlone()

	/**
	 * A tab the base gains AFTER the override was written still reaches the
	 * result. An override that had copied its base would never see it
	 * (REQ-OBSO-001).
	 *
	 * @return void
	 */
	public function testATabTheBaseGainsLaterStillReachesTheResult(): void {
		$base = $this->base();
		$base['tabs'][] = ['id' => 'besluit', 'kind' => 'widgets', 'label' => 'Besluit'];

		$result = $this->deltas->merge($base, ['tabs' => ['documenten' => ['label' => 'Bijlagen']]]);

		self::assertCount(5, $result['tabs']);
		self::assertSame('Bijlagen', $result['tabs'][1]['label']);
		self::assertSame('besluit', $result['tabs'][4]['id']);
	}//end testATabTheBaseGainsLaterStillReachesTheResult()

	/**
	 * The deletion marker removes an entry, and nothing else.
	 *
	 * @return void
	 */
	public function testTheDeletionMarkerRemovesOneEntry(): void {
		$result = $this->deltas->merge($this->base(), ['tabs' => ['taken' => ['$op' => 'remove']]]);

		self::assertSame(
			['gegevens', 'documenten', 'historie'],
			array_map(static fn (array $t): string => (string)$t['id'], $result['tabs'])
		);
	}//end testTheDeletionMarkerRemovesOneEntry()

	/**
	 * A reorder puts the named ids first and keeps the rest behind them, so a
	 * partial order is not a way to lose a tab.
	 *
	 * @return void
	 */
	public function testAReorderKeepsTheTabsItDoesNotName(): void {
		$result = $this->deltas->merge($this->base(), ['tabs' => ['__order' => ['documenten', 'gegevens']]]);

		self::assertSame(
			['documenten', 'gegevens', 'taken', 'historie'],
			array_map(static fn (array $t): string => (string)$t['id'], $result['tabs'])
		);
	}//end testAReorderKeepsTheTabsItDoesNotName()

	/**
	 * A patch may add a tab the base does not have.
	 *
	 * @return void
	 */
	public function testAPatchMayAddATab(): void {
		$result = $this->deltas->merge(
			$this->base(),
			['tabs' => ['betaling' => ['id' => 'betaling', 'kind' => 'leaf', 'label' => 'Betaling', 'ref' => 'shillinq-payment-requests']]]
		);

		self::assertCount(5, $result['tabs']);
		self::assertSame('betaling', $result['tabs'][4]['id']);
	}//end testAPatchMayAddATab()

	/**
	 * A scalar or a whole object simply replaces what was there.
	 *
	 * @return void
	 */
	public function testAPlainValueReplaces(): void {
		$result = $this->deltas->merge(['header' => ['titleField' => 'a', 'subtitleField' => 'b']], ['header' => ['titleField' => 'c']]);

		self::assertSame('c', $result['header']['titleField']);
		self::assertSame('b', $result['header']['subtitleField']);
	}//end testAPlainValueReplaces()

	/**
	 * A patch path that names a tab the base does not have, and does not look
	 * like a new tab, is reported as orphaned. A maintainer told only that
	 * something drifted has to diff two layouts by hand (REQ-OBSO-003).
	 *
	 * @return void
	 */
	public function testAnOrphanedPatchPathIsNamed(): void {
		$orphans = $this->deltas->orphanedPaths($this->base(), ['tabs' => ['besluit' => ['label' => 'Besluit']]]);

		self::assertSame(['tabs.besluit'], $orphans);
	}//end testAnOrphanedPatchPathIsNamed()

	/**
	 * A patch that ADDS a tab is not an orphan: it names an id the base does not
	 * have on purpose.
	 *
	 * @return void
	 */
	public function testAnAddedTabIsNotAnOrphan(): void {
		$orphans = $this->deltas->orphanedPaths(
			$this->base(),
			['tabs' => ['betaling' => ['id' => 'betaling', 'kind' => 'leaf', 'label' => 'Betaling']]]
		);

		self::assertSame([], $orphans);
	}//end testAnAddedTabIsNotAnOrphan()

	/**
	 * The same base fingerprints the same however its keys happen to be ordered.
	 * A fingerprint that changed when a store reordered its keys would report
	 * drift on every layout nobody touched.
	 *
	 * @return void
	 */
	public function testKeyOrderDoesNotChangeTheFingerprint(): void {
		$one = ['header' => ['titleField' => 'a', 'subtitleField' => 'b'], 'tabs' => []];
		$other = ['tabs' => [], 'header' => ['subtitleField' => 'b', 'titleField' => 'a']];

		self::assertSame($this->deltas->fingerprint($one), $this->deltas->fingerprint($other));
	}//end testKeyOrderDoesNotChangeTheFingerprint()

	/**
	 * A tab that kept its id and changed its KIND is drift, which is exactly what
	 * a key match cannot see (REQ-OBSO-002).
	 *
	 * @return void
	 */
	public function testATabThatKeptItsIdAndChangedItsKindIsDrift(): void {
		$moved = $this->base();
		$moved['tabs'][1]['kind'] = 'widgets';

		self::assertFalse($this->deltas->isCurrent($moved, $this->deltas->fingerprint($this->base())));
	}//end testATabThatKeptItsIdAndChangedItsKindIsDrift()

	/**
	 * An unchanged base is current.
	 *
	 * @return void
	 */
	public function testAnUnchangedBaseIsCurrent(): void {
		self::assertTrue($this->deltas->isCurrent($this->base(), $this->deltas->fingerprint($this->base())));
	}//end testAnUnchangedBaseIsCurrent()

	/**
	 * An override with NO fingerprint is not treated as current. It was written
	 * by something that did not pin its base, and the honest answer is that
	 * nobody knows what it was cut against.
	 *
	 * @return void
	 */
	public function testAnOverrideWithNoFingerprintIsNotCurrent(): void {
		self::assertFalse($this->deltas->isCurrent($this->base(), ''));
	}//end testAnOverrideWithNoFingerprintIsNotCurrent()

	/**
	 * A change outside the patchable parts, such as the layout's own name, is not
	 * drift: an override cannot patch it, so it cannot be broken by it.
	 *
	 * @return void
	 */
	public function testAChangeOutsideThePatchablePartsIsNotDrift(): void {
		$renamed = $this->base();
		$renamed['name'] = 'Renamed base';

		self::assertTrue($this->deltas->isCurrent($renamed, $this->deltas->fingerprint($this->base())));
	}//end testAChangeOutsideThePatchablePartsIsNotDrift()
}//end class
