<?php

/**
 * Which register and schema a live form writes to, and what it computes there.
 *
 * A built app's form page (`type: form`) posts to an OpenRegister objects
 * endpoint. When its fields carry `calculate` or the page carries a blocking
 * `eligibility` check, the server must evaluate them again before the record
 * is stored (REQ-BQLV-005). The save listener cannot afford to read every
 * app's manifest on every object write, so this index keeps, per app
 * version, the forms that have something to recompute, keyed by the register
 * and schema they write to. It is rebuilt whenever an app version is saved.
 *
 * Stored in app config as one JSON value: `{versionId: [binding, ...]}`, a
 * binding being `{register, schema, calculate: [...], eligibility: ?array}`.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://buildiq.nl
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The live-form bindings of every app version, by register and schema.
 *
 * @psalm-type LiveCalc = array{field:string,ruleSet:string,output:string,inputs:list<string>}
 * @psalm-type LiveBinding = array{register:string,schema:string,calculate:list<LiveCalc>,eligibility:array<string,mixed>|null}
 * @phpstan-type LiveCalc array{field:string,ruleSet:string,output:string,inputs:list<string>}
 * @phpstan-type LiveBinding array{register:string,schema:string,calculate:list<LiveCalc>,eligibility:array<string,mixed>|null}
 */
class FormLiveBindingIndex {

	/**
	 * The app config key holding the index.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'form_live_bindings';

	/**
	 * The OpenRegister objects endpoint a form posts to: `{register}/{schema}`.
	 *
	 * @var string
	 */
	private const OBJECTS_ENDPOINT = '#^/(?:index\.php/)?(?:apps/openregister/)?api/objects/([^/]+)/([^/]+)/?$#';

	/**
	 * The token a manifest carries for the version's own register.
	 *
	 * @var string
	 */
	private const REGISTER_TOKEN = '{registerSlug}';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the index is kept
	 * @param LoggerInterface $logger PSR logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Rebuild the entry of one app version from its manifest.
	 *
	 * @param string $versionId The ApplicationVersion uuid (or slug when new)
	 * @param array<string,mixed> $version The ApplicationVersion object data
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function reindexVersion(string $versionId, array $version): void {
		$all = $this->read();
		$bindings = $this->extract(
			manifest: (array)($version['manifest'] ?? []),
			versionRegister: (string)($version['register'] ?? '')
		);

		if ($bindings === [] && array_key_exists($versionId, $all) === false) {
			return;
		}

		unset($all[$versionId]);
		if ($bindings !== []) {
			$all[$versionId] = $bindings;
		}

		$this->write(index: $all);
	}//end reindexVersion()

	/**
	 * The bindings for an object in a register and schema.
	 *
	 * Both lists hold every name the register or schema goes by (its id and
	 * its slug), since a manifest may name either.
	 *
	 * @param array<int,string> $registerKeys The register's id and slug
	 * @param array<int,string> $schemaKeys The schema's id and slug
	 *
	 * @return list<LiveBinding>
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function bindingsFor(array $registerKeys, array $schemaKeys): array {
		$registerKeys = array_values(array_filter($registerKeys, static fn ($key): bool => $key !== ''));
		$schemaKeys = array_values(array_filter($schemaKeys, static fn ($key): bool => $key !== ''));
		$found = [];
		foreach ($this->read() as $bindings) {
			foreach ($bindings as $binding) {
				if (in_array($binding['register'], $registerKeys, true) === true
					&& in_array($binding['schema'], $schemaKeys, true) === true
				) {
					$found[] = $binding;
				}
			}
		}

		return $found;
	}//end bindingsFor()

	/**
	 * Whether no app version has a live form, so a save has nothing to do.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function isEmpty(): bool {
		return $this->read() === [];
	}//end isEmpty()

	/**
	 * The live bindings of a manifest's form pages.
	 *
	 * @param array<string,mixed> $manifest The app manifest
	 * @param string $versionRegister The version's register slug
	 *
	 * @return list<LiveBinding>
	 */
	private function extract(array $manifest, string $versionRegister): array {
		$bindings = [];
		foreach ((array)($manifest['pages'] ?? []) as $page) {
			if (is_array($page) === false || ($page['type'] ?? '') !== 'form') {
				continue;
			}

			$config = (array)($page['config'] ?? []);
			$target = $this->target(endpoint: (string)($config['submitEndpoint'] ?? ''), versionRegister: $versionRegister);
			if ($target === null) {
				continue;
			}

			$calculate = $this->calculations(fields: (array)($config['fields'] ?? []));
			$eligibility = $this->blockingCheck(check: ($config['eligibility'] ?? null));
			if ($calculate === [] && $eligibility === null) {
				continue;
			}

			$bindings[] = [
				'register' => $target[0],
				'schema' => $target[1],
				'calculate' => $calculate,
				'eligibility' => $eligibility,
			];
		}//end foreach

		return $bindings;
	}//end extract()

	/**
	 * The register and schema a submit endpoint writes to.
	 *
	 * @param string $endpoint The page's submitEndpoint
	 * @param string $versionRegister The version's register slug
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function target(string $endpoint, string $versionRegister): ?array {
		if (preg_match(self::OBJECTS_ENDPOINT, $endpoint, $match) !== 1) {
			return null;
		}

		$register = $match[1];
		if ($register === self::REGISTER_TOKEN) {
			$register = $versionRegister;
		}

		if ($register === '') {
			return null;
		}

		return [$register, $match[2]];
	}//end target()

	/**
	 * The calculated fields of a form.
	 *
	 * @param array<int,mixed> $fields The form's fields
	 *
	 * @return list<LiveCalc>
	 */
	private function calculations(array $fields): array {
		$out = [];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$calc = ($field['calculate'] ?? null);
			if (is_array($calc) === false
				|| is_string($field['key'] ?? null) === false
				|| (string)($calc['ruleSet'] ?? '') === ''
				|| (string)($calc['output'] ?? '') === ''
			) {
				continue;
			}

			$inputs = array_values(array_filter((array)($calc['inputs'] ?? []), 'is_string'));
			$out[] = [
				'field' => $field['key'],
				'ruleSet' => (string)$calc['ruleSet'],
				'output' => (string)$calc['output'],
				'inputs' => $inputs,
			];
		}

		return $out;
	}//end calculations()

	/**
	 * The eligibility check, when it blocks submit; a check that only informs
	 * is not the server's to enforce.
	 *
	 * @param mixed $check The page's eligibility config
	 *
	 * @return array{ruleSet:string,passWhen:array{output:string,equals:string},explainWith:string}|null
	 */
	private function blockingCheck(mixed $check): ?array {
		if (is_array($check) === false
			|| ($check['blockSubmit'] ?? false) !== true
			|| (string)($check['ruleSet'] ?? '') === ''
		) {
			return null;
		}

		$passWhen = (array)($check['passWhen'] ?? []);
		return [
			'ruleSet' => (string)$check['ruleSet'],
			'passWhen' => [
				'output' => (string)($passWhen['output'] ?? ''),
				'equals' => (string)($passWhen['equals'] ?? ''),
			],
			'explainWith' => (string)($check['explainWith'] ?? ''),
		];
	}//end blockingCheck()

	/**
	 * Read the whole index.
	 *
	 * @return array<string,list<LiveBinding>>
	 */
	private function read(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$this->logger->warning('Buildiq: the live form index is not valid JSON and is ignored.');
			return [];
		}

		/*
		 * @var array<string,list<LiveBinding>> $decoded
		 */
		return $decoded;
	}//end read()

	/**
	 * Write the whole index.
	 *
	 * @param array<string,mixed> $index The index
	 *
	 * @return void
	 */
	private function write(array $index): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			(string)json_encode($index, JSON_UNESCAPED_SLASHES)
		);
	}//end write()
}//end class
