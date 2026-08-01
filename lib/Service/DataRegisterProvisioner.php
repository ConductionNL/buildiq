<?php

/**
 * OpenBuild DataRegisterProvisioner
 *
 * Applies a published app repo's `data-registers/` channel: creates registers and
 * schemas that do not exist yet, and leaves existing ones exactly as they are.
 *
 * Split out of AppChannelApplier because provisioning OpenRegister structure is a
 * different responsibility from orchestrating the four channels — and because the
 * applier had grown past the complexity threshold, which is the tooling saying the
 * same thing.
 *
 * The never-mutate rule is the important part: installing an app must not reshape
 * data that is already on the instance. A register that exists is reported as
 * skipped, never merged into, never "upgraded".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-existing-register-or-schema-is-never-mutated
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the registers and schemas a published app declares.
 */
class DataRegisterProvisioner
{

    /**
     * The channel name used in the report.
     *
     * @var string
     */
    private const CHANNEL = 'dataRegisters';

    /**
     * Maximum data registers applied from one repo.
     *
     * @var int
     */
    private const MAX_REGISTERS = 64;


    /**
     * Constructor.
     *
     * @param RegisterMapper  $registerMapper Register lookup and creation.
     * @param SchemaMapper    $schemaMapper   Schema lookup and creation.
     * @param LoggerInterface $logger         PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()


    /**
     * Apply the data-registers channel.
     *
     * @param array<string,mixed> $registers The channel (slug → blob).
     * @param ChannelApplyReport  $report    The report to write into.
     *
     * @return void
     *
     * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-existing-register-or-schema-is-never-mutated
     */
    public function apply(array $registers, ChannelApplyReport $report): void
    {
        $report->declareChannel(channel: self::CHANNEL, declared: count($registers));

        $applied = 0;
        foreach ($registers as $slug => $blob) {
            $slug = (string) $slug;
            if ($applied >= self::MAX_REGISTERS) {
                $this->logger->warning(
                    'OpenBuild channel apply: channel "'.self::CHANNEL.'" declared '.count($registers)
                    .' items but the bound is '.self::MAX_REGISTERS.' — the excess was NOT applied.'
                );
                $report->recordTruncated(channel: self::CHANNEL, item: $slug);
                continue;
            }

            $applied++;

            try {
                $this->applyOne(slug: $slug, blob: (array) $blob, report: $report);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'OpenBuild channel apply: data register "'.$slug.'" failed: '.$e->getMessage()
                );
                $report->recordFailed(channel: self::CHANNEL, item: $slug, reason: $e->getMessage());
            }
        }//end foreach

    }//end apply()


    /**
     * Create one register and its missing schemas, or skip an existing one.
     *
     * @param string              $slug   The register slug.
     * @param array<string,mixed> $blob   The published register blob.
     * @param ChannelApplyReport  $report The report to write into.
     *
     * @return void
     */
    private function applyOne(string $slug, array $blob, ChannelApplyReport $report): void
    {
        try {
            $this->registerMapper->find($slug, _multitenancy: false);
            $report->recordSkipped(
                channel: self::CHANNEL,
                item: $slug,
                reason: ChannelApplyReport::REASON_EXISTS
            );
            return;
        } catch (Throwable) {
            // Absent — create it below.
        }

        $schemaIds = [];
        foreach ((array) ($blob['schemas'] ?? []) as $schemaSlug => $definition) {
            $schemaId = $this->findOrCreateSchema(slug: (string) $schemaSlug, definition: (array) $definition);
            if ($schemaId !== null) {
                $schemaIds[] = $schemaId;
            }
        }

        $this->registerMapper->createFromArray(
            [
                'slug'        => $slug,
                'title'       => (string) ($blob['title'] ?? $slug),
                'description' => 'Installed by OpenBuild from a published app repository.',
                'version'     => '0.1.0',
                'schemas'     => $schemaIds,
            ]
        );

        $report->recordCreated(channel: self::CHANNEL, item: $slug);

    }//end applyOne()


    /**
     * Find a schema by slug, or create it from the published definition.
     *
     * @param string              $slug       The schema slug.
     * @param array<string,mixed> $definition The published schema definition.
     *
     * @return int|null The schema id, or null when it could not be provisioned.
     */
    private function findOrCreateSchema(string $slug, array $definition): ?int
    {
        if ($slug === '') {
            return null;
        }

        try {
            $existing = $this->schemaMapper->find($slug, _multitenancy: false);
            return $existing->getId();
        } catch (Throwable) {
            // Absent — create it below.
        }

        try {
            $created = $this->schemaMapper->createFromArray(
                [
                    'slug'        => $slug,
                    'title'       => (string) ($definition['title'] ?? $slug),
                    'description' => (string) ($definition['description'] ?? ''),
                    'version'     => (string) ($definition['version'] ?? '0.1.0'),
                    'required'    => array_values((array) ($definition['required'] ?? [])),
                    'properties'  => (array) ($definition['properties'] ?? []),
                ]
            );

            return $created->getId();
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild channel apply: schema "'.$slug.'" could not be created: '.$e->getMessage()
            );
            return null;
        }

    }//end findOrCreateSchema()


}//end class
