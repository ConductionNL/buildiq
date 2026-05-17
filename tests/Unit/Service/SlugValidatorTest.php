<?php

/**
 * Unit tests for SlugValidator.
 *
 * Covers spec `openbuilt-app-creation-wizard` REQ-OBWIZ-005 and
 * REQ-OBWIZ-006:
 *   - Valid app + version slugs
 *   - Leading-underscore rejection
 *   - Invalid-character rejection
 *   - Too-short rejection (single character)
 *   - Duplicate-slug detection in chain (no dup, single dup, multiple dups)
 *   - Edge cases
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Service
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

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Service\SlugValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SlugValidator.
 */
class SlugValidatorTest extends TestCase
{
    private SlugValidator $validator;

    /**
     * Set up the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->validator = new SlugValidator();
    }//end setUp()

    // -------------------------------------------------------------------------
    // validateAppSlug
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function validAppSlugReturnsEmptyArray(): void
    {
        self::assertSame([], $this->validator->validateAppSlug('my-cool-app'));
        self::assertSame([], $this->validator->validateAppSlug('helloworld'));
        self::assertSame([], $this->validator->validateAppSlug('ab'));
        self::assertSame([], $this->validator->validateAppSlug('a1-b2-c3'));
    }//end validAppSlugReturnsEmptyArray()

    /**
     * @test
     *
     * @return void
     */
    public function emptyAppSlugReturnsSlugEmptyError(): void
    {
        $result = $this->validator->validateAppSlug('');
        self::assertSame('slug_empty', $result['code']);
    }//end emptyAppSlugReturnsSlugEmptyError()

    /**
     * @test
     *
     * @return void
     */
    public function singleCharAppSlugIsRejected(): void
    {
        // Pattern requires minimum 2 chars: [a-z0-9][a-z0-9-]*[a-z0-9]
        $result = $this->validator->validateAppSlug('a');
        self::assertNotEmpty($result);
        self::assertArrayHasKey('code', $result);
    }//end singleCharAppSlugIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function appSlugWithInvalidCharsIsRejected(): void
    {
        $result = $this->validator->validateAppSlug('my app!');
        self::assertSame('slug_invalid', $result['code']);
    }//end appSlugWithInvalidCharsIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function appSlugWithUppercaseIsRejected(): void
    {
        $result = $this->validator->validateAppSlug('MyApp');
        self::assertNotEmpty($result);
    }//end appSlugWithUppercaseIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function appSlugStartingWithHyphenIsRejected(): void
    {
        $result = $this->validator->validateAppSlug('-my-app');
        self::assertNotEmpty($result);
    }//end appSlugStartingWithHyphenIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function appSlugEndingWithHyphenIsRejected(): void
    {
        $result = $this->validator->validateAppSlug('my-app-');
        self::assertNotEmpty($result);
    }//end appSlugEndingWithHyphenIsRejected()

    // -------------------------------------------------------------------------
    // validateVersionSlug
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function validVersionSlugReturnsEmptyArray(): void
    {
        self::assertSame([], $this->validator->validateVersionSlug('production'));
        self::assertSame([], $this->validator->validateVersionSlug('development'));
        self::assertSame([], $this->validator->validateVersionSlug('dev-staging'));
    }//end validVersionSlugReturnsEmptyArray()

    /**
     * @test
     *
     * @return void
     */
    public function leadingUnderscoreVersionSlugIsRejected(): void
    {
        $result = $this->validator->validateVersionSlug('_internal');
        self::assertSame('slug_leading_underscore', $result['code']);
        self::assertStringContainsString('reserved for openbuilt system use', $result['message']);
    }//end leadingUnderscoreVersionSlugIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function singleUnderscoreVersionSlugIsRejected(): void
    {
        $result = $this->validator->validateVersionSlug('_');
        self::assertSame('slug_leading_underscore', $result['code']);
    }//end singleUnderscoreVersionSlugIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function invalidCharsVersionSlugIsRejected(): void
    {
        $result = $this->validator->validateVersionSlug('my version!');
        self::assertSame('slug_invalid', $result['code']);
    }//end invalidCharsVersionSlugIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function singleCharVersionSlugIsRejected(): void
    {
        $result = $this->validator->validateVersionSlug('a');
        self::assertNotEmpty($result);
    }//end singleCharVersionSlugIsRejected()

    /**
     * @test
     *
     * @return void
     */
    public function emptyVersionSlugReturnsSlugEmptyError(): void
    {
        $result = $this->validator->validateVersionSlug('');
        self::assertSame('slug_empty', $result['code']);
    }//end emptyVersionSlugReturnsSlugEmptyError()

    // -------------------------------------------------------------------------
    // validateChainSlugs
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function uniqueChainSlugsReturnEmptyArray(): void
    {
        self::assertSame([], $this->validator->validateChainSlugs(['development', 'staging', 'production']));
    }//end uniqueChainSlugsReturnEmptyArray()

    /**
     * @test
     *
     * @return void
     */
    public function singleItemChainReturnsEmptyArray(): void
    {
        self::assertSame([], $this->validator->validateChainSlugs(['production']));
    }//end singleItemChainReturnsEmptyArray()

    /**
     * @test
     *
     * @return void
     */
    public function duplicateChainSlugReturnsErrorWithRows(): void
    {
        $result = $this->validator->validateChainSlugs(['staging', 'production', 'staging']);
        self::assertSame('duplicate_version_slug', $result['code']);
        self::assertSame('staging', $result['slug']);
        self::assertContains(0, $result['rows']);
        self::assertContains(2, $result['rows']);
    }//end duplicateChainSlugReturnsErrorWithRows()

    /**
     * @test
     *
     * @return void
     */
    public function firstDuplicateIsReportedWhenMultipleDuplicatesExist(): void
    {
        $result = $this->validator->validateChainSlugs(['staging', 'production', 'staging', 'production']);
        self::assertSame('duplicate_version_slug', $result['code']);
        // The first duplicate slug found is reported.
        self::assertContains($result['slug'], ['staging', 'production']);
    }//end firstDuplicateIsReportedWhenMultipleDuplicatesExist()

    /**
     * @test
     *
     * @return void
     */
    public function duplicateCheckIsCaseInsensitive(): void
    {
        // Slugs are normalised to lowercase — 'Staging' and 'staging' collide.
        $result = $this->validator->validateChainSlugs(['Staging', 'staging']);
        self::assertSame('duplicate_version_slug', $result['code']);
        self::assertSame('staging', $result['slug']);
    }//end duplicateCheckIsCaseInsensitive()

    /**
     * @test
     *
     * @return void
     */
    public function emptyChainReturnsEmptyArray(): void
    {
        self::assertSame([], $this->validator->validateChainSlugs([]));
    }//end emptyChainReturnsEmptyArray()
}//end class
