<?php

/**
 * Buildiq RenameAgentSchemaSlug repair step.
 *
 * Moves this app's agent schema slug from `agent` to `buildAgent` IN PLACE, before
 * InitializeSettings imports the register.
 *
 * A schema slug is global per organisation and `SchemaMapper::find()` matches
 * `LOWER(slug)`, so a bare `agent` was answered for by hermiq's agent as readily as by
 * this app's. They are not two views of one record: hermiq's is the AI agent, 41 fields
 * of model, tools and Talk binding; this one is the 6-field workspace pointer this app
 * keeps for the agents bound to a generated application. They share two fields.
 *
 * The pair is therefore renamed apart rather than folded. {@see
 * \OCA\Buildiq\Service\FlowAndAgentExportBundler::HERMIQ_AGENT_SCHEMA} keeps naming
 * `agent`, because that is hermiq's slug and it does not move.
 *
 * Why a repair step and why before the import. OpenRegister matches an existing schema
 * by (application, slug): `ImportHandler` calls `findByApplicationAndSlug()` and creates
 * a NEW schema when that misses. A slug rename in the shipped fragment therefore does
 * not rename anything — it CREATES a second schema and silently orphans the first,
 * together with every object already written against it. The old schema keeps its shard
 * table and its rows; the app resolves the new id and reads an empty collection.
 *
 * Both application spellings are accepted. This app's schemas are mid-migration from
 * `openbuild` to `buildiq` ({@see MigrateSchemaApplicationId}), and depending on where
 * an install is in that sequence the row carries either. Matching only one would make
 * this step a silent no-op on exactly the installs that still need it.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://buildiq.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames this app's agent schema slug in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
class RenameAgentSchemaSlug implements IRepairStep {
	/**
	 * The colliding slug this step moves away from.
	 *
	 * @var string
	 */
	private const OLD_SLUG = 'agent';

	/**
	 * The namespaced slug the register fragment now declares.
	 *
	 * @var string
	 */
	private const NEW_SLUG = 'buildAgent';

	/**
	 * Application values this app's schema rows may carry, old spelling included.
	 *
	 * @var array<int, string>
	 */
	private const APPLICATIONS = ['buildiq', 'openbuild'];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function getName(): string {
		return 'Namespace the buildiq agent schema slug so it stops colliding with hermiq';
	}//end getName()

	/**
	 * Rename the slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function run(IOutput $output): void {
		$old = $this->schemaIds(slug: self::OLD_SLUG);
		$new = $this->schemaIds(slug: self::NEW_SLUG);

		if ($old === null || $new === null) {
			$output->info('RenameAgentSchemaSlug: schema table unreadable; leaving the slug alone.');
			return;
		}

		if ($old === []) {
			$output->info('RenameAgentSchemaSlug: no buildiq-owned `agent` schema on this install; nothing to do.');
			return;
		}

		if ($new !== []) {
			// Both slugs present: each may own objects, and renaming would collide
			// with the new row. Abandoning either set is not a call a repair step
			// gets to make without being asked.
			$this->logger->warning(
				'RenameAgentSchemaSlug: both slugs exist; refusing to merge them.',
				['old' => $old, 'new' => $new]
			);
			$output->warning(
				'RenameAgentSchemaSlug: both `' . self::OLD_SLUG . '` and `' . self::NEW_SLUG
				. '` exist; refusing to merge them. Resolve by hand.'
			);
			return;
		}

		if (count($old) > 1) {
			$this->logger->warning(
				'RenameAgentSchemaSlug: duplicate slugs; refusing to guess.',
				['ids' => $old]
			);
			$output->warning('RenameAgentSchemaSlug: duplicate `' . self::OLD_SLUG . '` schemas; refusing to guess.');
			return;
		}

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[self::NEW_SLUG, $old[0]]
			);
		} catch (Exception $e) {
			// Safe to fail: the import then creates a new schema rather than
			// updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameAgentSchemaSlug: slug rename failed; the import will create a second schema.',
				['id' => $old[0], 'exception' => $e->getMessage()]
			);
			$output->warning('RenameAgentSchemaSlug: slug rename failed; see the log.');
			return;
		}

		$output->info(
			'RenameAgentSchemaSlug: schema ' . $old[0] . ' renamed `' . self::OLD_SLUG . '` -> `'
			. self::NEW_SLUG . '`; its objects stay attached.'
		);
	}//end run()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * Scoped to THIS app's rows. Without the application filter the lookup would
	 * find hermiq's `agent` too, and renaming that is precisely the damage this
	 * step exists to avoid.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		$placeholders = implode(', ', array_fill(0, count(self::APPLICATIONS), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application IN (' . $placeholders . ')',
				array_merge([$slug], self::APPLICATIONS)
			)->fetchAll(\PDO::FETCH_COLUMN);

			return array_values((array)$rows);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameAgentSchemaSlug: could not read the schema table; skipping.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
