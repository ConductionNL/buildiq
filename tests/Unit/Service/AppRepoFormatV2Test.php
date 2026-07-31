<?php

/**
 * Unit tests for app-repo-format-v2.
 *
 * Covers the format-version contract, which had NO test coverage before this
 * change: nothing in the suite asserted `formatVersion` at all, so the field that
 * governs whether a repository is parseable could be changed silently. These
 * tests pin it in both directions — v2 is stamped on emit, v1 still parses, and
 * an unknown major is refused rather than best-effort read.
 *
 * Also covers the channel counts (which are what stop a total collector producing
 * a silently empty artefact) and path safety on the new channels.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Exception\AppRepoParseException;
use OCA\OpenBuild\Service\AppRepoParser;
use OCA\OpenBuild\Service\AppRepoSerializer;
use OCA\OpenBuild\Service\TemplateRepoSerializer;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Format-version and channel contract for app-repo-format-v2.
 *
 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md
 */
class AppRepoFormatV2Test extends TestCase
{

    /**
     * A serializer with no ObjectService — the v1 construction shape. The new
     * channels must degrade to empty rather than throw.
     *
     * @return AppRepoSerializer
     */
    private function serializer(): AppRepoSerializer
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willThrowException(new \RuntimeException('no register'));
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $logger       = $this->createMock(LoggerInterface::class);

        return new AppRepoSerializer(
            $registerMapper,
            $schemaMapper,
            $logger,
            new TemplateRepoSerializer($schemaMapper, $logger)
        );

    }//end serializer()

    /**
     * A minimal application + version pair.
     *
     * @param array<string,mixed> $extra Extra Application fields.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function app(array $extra=[]): array
    {
        $application = array_merge(
            [
                'slug'        => 'demo-app',
                'name'        => 'Demo App',
                'description' => 'A demo',
                'appType'     => 'virtual',
            ],
            $extra
        );

        $version = [
            'semver'   => '1.0.0',
            'manifest' => ['version' => '1.0.0', 'pages' => []],
        ];

        return [$application, $version];
    }//end app()

    /**
     * The serializer stamps formatVersion 2.0.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration
     */
    public function testSerializerStampsFormatVersionTwo(): void
    {
        [$application, $version] = $this->app();

        $files      = $this->serializer()->serialize(application: $application, version: $version);
        $descriptor = json_decode($files['openbuild-app.json'], true);

        $this->assertSame('2.0', $descriptor['formatVersion']);
        $this->assertSame(AppRepoSerializer::FORMAT_VERSION, $descriptor['formatVersion']);

    }//end testSerializerStampsFormatVersionTwo()

    /**
     * Channel counts are recorded, so a collector that found nothing is visible
     * in the artefact instead of publishing as an apparent success.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration
     */
    public function testEmptyChannelsAreRecordedNotHidden(): void
    {
        [$application, $version] = $this->app();

        $files      = $this->serializer()->serialize(application: $application, version: $version);
        $descriptor = json_decode($files['openbuild-app.json'], true);

        $this->assertArrayHasKey('channels', $descriptor);
        $this->assertSame(0, $descriptor['channels']['schemas']);
        $this->assertSame(0, $descriptor['channels']['dataRegisters']);
        $this->assertSame(0, $descriptor['channels']['connectors']['declared']);
        $this->assertSame(0, $descriptor['channels']['automations']);

    }//end testEmptyChannelsAreRecordedNotHidden()

    /**
     * A v1 repository STILL parses — the back-compat guarantee this change rests
     * on, and the one that had no coverage at all before now.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration
     */
    public function testV1RepositoryStillParses(): void
    {
        $files = [
            'openbuild-app.json' => json_encode(
                [
                    'formatVersion' => '1.0',
                    'slug'          => 'legacy-app',
                    'name'          => 'Legacy App',
                    'description'   => 'Published before v2 existed',
                    'category'      => 'general',
                    'appType'       => 'virtual',
                    'version'       => '1.0.0',
                ]
            ),
            'manifest.json'      => json_encode(['version' => '1.0.0', 'pages' => []]),
        ];

        $parsed = (new AppRepoParser())->parse(files: $files);

        $this->assertSame('legacy-app', $parsed['slug']);

    }//end testV1RepositoryStillParses()

    /**
     * A v2 repository parses.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration
     */
    public function testV2RepositoryParses(): void
    {
        $files = [
            'openbuild-app.json' => json_encode(
                [
                    'formatVersion' => '2.0',
                    'slug'          => 'v2-app',
                    'name'          => 'V2 App',
                    'description'   => 'Published with channels',
                    'category'      => 'general',
                    'appType'       => 'virtual',
                    'version'       => '1.0.0',
                ]
            ),
            'manifest.json'      => json_encode(['version' => '1.0.0', 'pages' => []]),
        ];

        $parsed = (new AppRepoParser())->parse(files: $files);

        $this->assertSame('v2-app', $parsed['slug']);

    }//end testV2RepositoryParses()

    /**
     * An unknown major is REFUSED rather than best-effort parsed — a repository
     * that half-reads is worse than one that declines, because the caller would
     * install a partial app believing it complete.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration
     */
    public function testUnknownFormatMajorIsRefused(): void
    {
        $files = [
            'openbuild-app.json' => json_encode(
                [
                    'formatVersion' => '3.0',
                    'slug'          => 'future-app',
                    'name'          => 'Future App',
                    'description'   => 'From a newer OpenBuild',
                    'category'      => 'general',
                    'appType'       => 'virtual',
                    'version'       => '1.0.0',
                ]
            ),
            'manifest.json'      => json_encode(['version' => '1.0.0', 'pages' => []]),
        ];

        $this->expectException(AppRepoParseException::class);
        (new AppRepoParser())->parse(files: $files);

    }//end testUnknownFormatMajorIsRefused()

    /**
     * A crafted data-register binding slug never reaches a path.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
     */
    public function testCraftedBindingSlugIsRejected(): void
    {
        [$application, $version] = $this->app(
            [
                'dataRegisters' => [
                    ['register' => '../../etc'],
                    ['register' => '/absolute'],
                ],
                'connectors'    => [
                    ['kind' => 'source', 'slug' => '../../escape'],
                    ['kind' => 'not-a-kind', 'slug' => 'fine-slug'],
                ],
            ]
        );

        $files = $this->serializer()->serialize(application: $application, version: $version);

        foreach (array_keys($files) as $path) {
            $this->assertStringNotContainsString('..', $path, 'No emitted path may contain a traversal segment.');
            $this->assertStringStartsNotWith('/', $path);
        }

        $this->assertArrayNotHasKey('data-registers/../../etc.json', $files);

    }//end testCraftedBindingSlugIsRejected()

    /**
     * A v2 repository's channels survive serialize → parse.
     *
     * The channels are the whole point of the format; without this the two halves
     * could drift and nothing would notice until an install came back empty.
     *
     * @return void
     *
     * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-apps-whole-configuration
     */
    public function testChannelsRoundTripThroughTheParser(): void
    {
        $files = [
            'openbuild-app.json' => json_encode(
                [
                    'formatVersion' => '2.0',
                    'slug'          => 'round-trip',
                    'name'          => 'Round Trip',
                    'description'   => 'Channels survive',
                    'category'      => 'general',
                    'appType'       => 'virtual',
                    'version'       => '1.0.0',
                ]
            ),
            'manifest.json'                          => json_encode(['version' => '1.0.0', 'pages' => []]),
            'data-registers/spectr-live.json'        => json_encode(['slug' => 'spectr-live', 'schemas' => ['tender' => ['slug' => 'tender']]]),
            'connectors/source/ted-source.json'      => json_encode(['slug' => 'ted-source', 'type' => 'api']),
            'connectors/synchronization/ted-sync.json' => json_encode(['slug' => 'ted-sync', 'sourceId' => 'ted-source']),
            'automations/nightly-refresh.json'       => json_encode(['slug' => 'nightly-refresh', 'enabled' => true]),
            'skills/tender-summary/SKILL.md'         => "---\nname: tender-summary\n---\nSummarise a tender.\n",
            'skills/tender-summary/references/exemptions.md' => "Grounds.\n",
            // Hostile entries the parser must drop without failing the repo.
            'connectors/source/../../evil.json'      => json_encode(['slug' => 'evil']),
            'connectors/not-a-kind/thing.json'       => json_encode(['slug' => 'thing']),
        ];

        $parsed = (new AppRepoParser())->parse(files: $files);

        $this->assertArrayHasKey('channels', $parsed);
        $channels = $parsed['channels'];

        $this->assertArrayHasKey('spectr-live', $channels['dataRegisters']);
        $this->assertSame('ted-source', $channels['connectors']['source']['ted-source']['slug']);
        $this->assertSame('ted-sync', $channels['connectors']['synchronization']['ted-sync']['slug']);
        $this->assertArrayHasKey('nightly-refresh', $channels['automations']);
        $this->assertStringContainsString('Summarise a tender', $channels['skills']['tender-summary']['SKILL.md']);
        $this->assertArrayHasKey('references/exemptions.md', $channels['skills']['tender-summary']);

        // The hostile entries are dropped, and the rest still parsed.
        $this->assertArrayNotHasKey('not-a-kind', $channels['connectors']);
        foreach ($channels['connectors'] as $kind => $entries) {
            $this->assertNotSame('evil', array_key_first($entries));
        }

    }//end testChannelsRoundTripThroughTheParser()
}//end class
