<?php

/**
 * The declared Nextcloud floor must match what this repo actually installs on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;

/**
 * `<nextcloud min-version>` is enforced at INSTALL time, so it is not prose —
 * it decides whether `occ app:enable openbuild` succeeds.
 *
 * This repository has already been wrong in BOTH directions:
 *
 *  - #146 lowered the floor to 28 because "this repo tests stable31", and left
 *    the surrounding comment asserting 32. Value and prose disagreed for two
 *    days, and the comment was the part a reader trusts.
 *  - Before that, the floor sat ABOVE the Nextcloud version the repo's own CI
 *    installed on, which is the openconnector#1172 shape: the seed then fails
 *    with "is not installed or enabled" and the run reads like a migration
 *    fault seventy seconds later.
 *
 * The invariant that catches both is local and cheap: every Nextcloud ref this
 * repo's own CI matrix installs on must be >= the floor the app declares. No
 * network, no fleet knowledge — just the two files that have to agree.
 */
class NextcloudFloorTest extends TestCase
{

    /**
     * Every `nextcloud-test-refs` entry in code-quality.yml must satisfy the
     * floor declared in appinfo/info.xml.
     *
     * @return void
     */
    public function testEveryCiNextcloudRefSatisfiesTheDeclaredFloor(): void
    {
        $floor = $this->declaredFloor();
        $refs  = $this->ciNextcloudMajors();

        // Positive control on the INPUT: an empty ref list would make the
        // assertion below vacuously true, which is the failure mode this whole
        // file exists to avoid. A scanner that read nothing must not look like
        // a scanner that found nothing wrong.
        $this->assertNotEmpty(
            $refs,
            'Parsed ZERO nextcloud-test-refs out of .github/workflows/code-quality.yml. '
            .'That is a broken parser, not a clean result — fix the parser before trusting this test.'
        );

        foreach ($refs as $ref => $major) {
            $this->assertGreaterThanOrEqual(
                $floor,
                $major,
                sprintf(
                    'CI installs OpenBuild on %s (Nextcloud %d) but appinfo/info.xml declares '
                    .'min-version="%d". min-version is enforced at install time, so `occ app:enable '
                    .'openbuild` refuses on that leg and the e2e seed fails with "is not installed '
                    .'or enabled" — which reads like a migration fault. Lower the floor or drop the leg.',
                    $ref,
                    $major,
                    $floor
                )
            );
        }

    }//end testEveryCiNextcloudRefSatisfiesTheDeclaredFloor()

    /**
     * The floor is 32: the fleet standardises on Nextcloud 32 so it can require
     * PHP 8.3, which `<php min-version>` declares.
     *
     * Asserted as a literal on purpose. The test above only proves the floor is
     * not ABOVE what CI tests; it would happily accept 28. This one pins the
     * product decision so a silent lowering has to argue with a test.
     *
     * @return void
     */
    public function testTheDeclaredFloorIsThirtyTwoAndPhpIsEightThree(): void
    {
        $this->assertSame(32, $this->declaredFloor());
        $this->assertSame('8.3', $this->declaredPhpFloor());

    }//end testTheDeclaredFloorIsThirtyTwoAndPhpIsEightThree()

    /**
     * Read `<nextcloud min-version>` out of appinfo/info.xml.
     *
     * @return int The declared Nextcloud major floor.
     */
    private function declaredFloor(): int
    {
        $node = $this->infoXml()->dependencies->nextcloud ?? null;
        if ($node === null) {
            throw new RuntimeException('appinfo/info.xml declares no <nextcloud> dependency.');
        }

        $min = (string) $node['min-version'];
        if ($min === '') {
            throw new RuntimeException('appinfo/info.xml <nextcloud> carries no min-version.');
        }

        return (int) $min;

    }//end declaredFloor()

    /**
     * Read `<php min-version>` out of appinfo/info.xml.
     *
     * @return string The declared PHP floor, e.g. "8.3".
     */
    private function declaredPhpFloor(): string
    {
        $node = $this->infoXml()->dependencies->php ?? null;
        if ($node === null) {
            throw new RuntimeException('appinfo/info.xml declares no <php> dependency.');
        }

        return (string) $node['min-version'];

    }//end declaredPhpFloor()

    /**
     * Parse appinfo/info.xml.
     *
     * @return SimpleXMLElement The parsed document.
     */
    private function infoXml(): SimpleXMLElement
    {
        $path = dirname(__DIR__, 3).'/appinfo/info.xml';
        $xml  = simplexml_load_file($path);
        if ($xml === false) {
            throw new RuntimeException('Could not parse '.$path);
        }

        return $xml;

    }//end infoXml()

    /**
     * The Nextcloud majors this repo's own CI installs the app on.
     *
     * Read out of `nextcloud-test-refs: '["stable32", "stable33"]'` in
     * .github/workflows/code-quality.yml. `masterN` / `master` are deliberately
     * NOT translated to a number — an unpinned ref cannot be compared, and
     * silently dropping it would hide a leg from the assertion.
     *
     * @return array<string,int> Map of ref name to Nextcloud major.
     */
    private function ciNextcloudMajors(): array
    {
        $path     = dirname(__DIR__, 3).'/.github/workflows/code-quality.yml';
        $workflow = file_get_contents($path);
        if ($workflow === false) {
            throw new RuntimeException('Could not read '.$path);
        }

        // Only the assignment line, never a comment: the comment above this
        // input in code-quality.yml names `stable31` while explaining that it
        // was REMOVED, so a comment-blind grep reads a leg that does not exist.
        $line = null;
        foreach (explode("\n", $workflow) as $candidate) {
            $trimmed = ltrim($candidate);
            if (str_starts_with($trimmed, 'nextcloud-test-refs:') === true) {
                $line = $trimmed;
                break;
            }
        }

        if ($line === null) {
            throw new RuntimeException('No nextcloud-test-refs input found in '.$path);
        }

        $matches = [];
        preg_match_all('/stable(\d+)/', $line, $matches, PREG_SET_ORDER);

        $refs = [];
        foreach ($matches as $match) {
            $refs['stable'.$match[1]] = (int) $match[1];
        }

        return $refs;

    }//end ciNextcloudMajors()


}//end class
