<?php

/**
 * Buildiq StoredDecisionTables
 *
 * A rule set's decision table lives in OpenRegister's `decision-tables`
 * register (decision 196), read through OpenRegister's DecisionTableStore and
 * written through its object service. This class is the one place that knows
 * how buildiq's authoring shape (input columns with an expression path, rules
 * as a condition map and a value map) maps onto the stored shape OpenRegister
 * evaluates (input and output columns, rules as positional entries).
 *
 * The store reads every table regardless of organisation, so the tenant
 * boundary moves into the key: `buildiq.<organisation>.<rule-set-slug>`. A
 * table is only ever reached through a rule set the caller could already
 * resolve, which is the organisation-scoped read in RuleObjectReader
 * (REQ-BRE-007), and two organisations that both have a rule set called
 * `loan-eligibility` get two keys.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Reads and writes a rule set's decision table in OpenRegister's register.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */
class StoredDecisionTables {

	/**
	 * The prefix of every key this app writes.
	 */
	public const KEY_PREFIX = 'buildiq.';

	/**
	 * Constructor.
	 *
	 * @param DecisionTableStore     $store     OpenRegister's reader of stored tables.
	 * @param ObjectServiceInterface $objects   Writes the register.
	 * @param DecisionTableShape     $shape     Maps the authoring shape onto the stored shape and back.
	 * @param LoggerInterface        $logger    Logger.
	 */
	public function __construct(
		private readonly DecisionTableStore $store,
		private readonly ObjectServiceInterface $objects,
		private readonly DecisionTableShape $shape,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The key of the table that belongs to a rule set.
	 *
	 * @param string      $ruleSetSlug  The rule set's slug.
	 * @param string|null $organisation The organisation that holds the rule set, if any.
	 *
	 * @return string The key, for example `buildiq.loan-eligibility` or `buildiq.<org-uuid>.loan-eligibility`.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function keyFor(string $ruleSetSlug, ?string $organisation): string {
		$parts = [rtrim(self::KEY_PREFIX, '.')];
		$organisation = strtolower(trim((string)$organisation));
		if ($organisation !== '') {
			$parts[] = $organisation;
		}

		$parts[] = strtolower(trim($ruleSetSlug));

		return implode('.', $parts);

	}//end keyFor()

	/**
	 * The key of the table that belongs to a resolved rule set row.
	 *
	 * @param array<string,mixed> $ruleSet The rule set as the reader returns it.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function keyOfRuleSet(array $ruleSet): string {
		$self = [];
		if (is_array($ruleSet['@self'] ?? null) === true) {
			$self = $ruleSet['@self'];
		}

		return $this->keyFor(
			ruleSetSlug: (string)($ruleSet['slug'] ?? ''),
			organisation: (string)($self['organisation'] ?? '')
		);

	}//end keyOfRuleSet()

	/**
	 * The decision tables of a rule set, in buildiq's authoring shape.
	 *
	 * A rule set has at most one table: the one under its own key, or else a
	 * shared one under `buildiq.<rule-set-slug>`, which is what a seed ships
	 * (a seed cannot know the organisation the import will give the rule set;
	 * a table written through buildiq always gets the organisation's own key).
	 * A missing table answers an empty list,
	 * as it did when the tables were rows in buildiq's own register; a stored
	 * table OpenRegister refuses to run is logged and also answers an empty
	 * list, so the evaluation reports no result instead of a wrong one.
	 *
	 * @param array<string,mixed> $ruleSet The resolved rule set.
	 *
	 * @return list<array<string,mixed>> Zero or one table.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function forRuleSet(array $ruleSet): array {
		$slug = (string)($ruleSet['slug'] ?? '');
		if ($slug === '') {
			return [];
		}

		$keys = [$this->keyOfRuleSet(ruleSet: $ruleSet)];
		$shared = $this->keyFor(ruleSetSlug: $slug, organisation: null);
		if (in_array($shared, $keys, true) === false) {
			$keys[] = $shared;
		}

		foreach ($keys as $key) {
			try {
				$row = $this->store->find(reference: $key);
			} catch (UnexpectedValueException $e) {
				$this->logger->debug(
					'Buildiq: no runnable decision table under "' . $key . '"',
					['ruleSet' => $slug, 'reason' => $e->getMessage()]
				);
				continue;
			}

			return [$this->shape->fromStored(row: $row, ruleSetSlug: $slug)];
		}

		$this->logger->info('Buildiq: rule set "' . $slug . '" has no runnable decision table in OpenRegister', ['keys' => $keys]);

		return [];

	}//end forRuleSet()

	/**
	 * Write a rule set's table into OpenRegister, replacing the one it had.
	 *
	 * The CALLER authorises: the write runs without OpenRegister's register
	 * RBAC, because the repair step runs without a user. The one HTTP path,
	 * RulesController::saveDecisionTable(), refuses anyone but an administrator
	 * before it gets here, which is who could write a table in buildiq's own
	 * register before the move.
	 *
	 * @param array<string,mixed> $ruleSet The resolved rule set (slug, name, @self.organisation).
	 * @param array<string,mixed> $table   The table in buildiq's authoring shape.
	 *
	 * @return array<string,mixed> The stored row as written.
	 *
	 * @throws RuntimeException When OpenRegister refuses the write, including a table its validator cannot run.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function save(array $ruleSet, array $table): array {
		$slug = (string)($ruleSet['slug'] ?? '');
		$name = trim((string)($ruleSet['name'] ?? ''));
		if ($name === '') {
			$name = $slug;
		}

		$key = $this->keyOfRuleSet(ruleSet: $ruleSet);
		$row = $this->shape->toStored(key: $key, name: $name, table: $table);

		try {
			$this->objects->saveObject(
				object: $row,
				register: DecisionTableStore::REGISTER,
				schema: DecisionTableStore::SCHEMA,
				uuid: $this->existingUuid(key: $key),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new RuntimeException(
				'OpenRegister refused the decision table "' . $key . '": ' . $e->getMessage(),
				0,
				$e
			);
		}

		return $row;

	}//end save()

	/**
	 * Whether OpenRegister already holds a table under a key.
	 *
	 * @param string $key The table's key.
	 *
	 * @return bool True when a row carries the key.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function exists(string $key): bool {
		return $this->existingUuid(key: $key) !== null;

	}//end exists()

	/**
	 * The uuid of the row that carries a key, if any.
	 *
	 * @param string $key The table's key.
	 *
	 * @return string|null The uuid, or null when no row carries the key.
	 */
	private function existingUuid(string $key): ?string {
		try {
			$rows = $this->objects->searchObjectsBySlug(
				registerSlug: DecisionTableStore::REGISTER,
				schemaSlug: DecisionTableStore::SCHEMA,
				filters: [],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The decision-table register could not be read: ' . $e->getMessage(), 0, $e);
		}

		foreach ((array)$rows as $entity) {
			if (is_object($entity) === false || method_exists($entity, 'getObject') === false) {
				continue;
			}

			$data = $entity->getObject();
			if (is_array($data) === true && trim((string)($data['key'] ?? '')) === $key) {
				return (string)$entity->getUuid();
			}
		}

		return null;

	}//end existingUuid()
}//end class
