<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of Buildiq's Integrations page. Nothing in Buildiq reads it at runtime,
 * so a broken file fails nowhere in this repo: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-001-buildiq-declares-its-outside-connections-in-one-static-file
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\Connection\ConnectionReporter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards lib/Settings/connections.json against hydra connection-registry D2 and D12.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Integriq's schema, fetched with `gh api` from integriq `development` on
	 * 2026-09-14, where the file was last changed in
	 * 605a87792a062ebbd38e05e8f598597fcf8a94c4. It carries the hydra#673
	 * amendments (`reportedOnly`, `adapter.jsonPath`, `adapter.simulatedValues`).
	 *
	 * @var string
	 */
	private const SCHEMA = '/tests/Fixtures/Integriq/connections.schema.json';

	/**
	 * The keys the file declares, in declared order.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_KEYS = ['store', 'github', 'documents', 'rule-webhooks'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[(string) $connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The vendored schema.
	 *
	 * @return string
	 */
	private function schema(): string {
		$schema = file_get_contents($this->root() . self::SCHEMA);
		$this->assertIsString(actual: $schema);

		return $schema;
	}//end schema()

	/**
	 * The file validates against integriq's JSON Schema.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$result = (new Validator())->validate(json_decode($this->raw()), $this->schema());

		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue(condition: $result->isValid(), message: (string) json_encode($errors, JSON_PRETTY_PRINT));
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The schema check can fail: a misspelled field is refused.
	 *
	 * Without this control a validator that accepts everything would pass the
	 * test above as well.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAnUnknownField(): void {
		$declaration = json_decode($this->raw());
		$declaration->connections[0]->setingsUrl = '/settings/admin/buildiq#section-store';

		$this->assertFalse(condition: (new Validator())->validate($declaration, $this->schema())->isValid());
	}//end testTheSchemaRefusesAnUnknownField()

	/**
	 * The file names the app it ships in.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		// Deliberately file_get_contents() + simplexml_load_string() rather than
		// simplexml_load_file(). Under the Nextcloud bootstrap lib/base.php calls
		// libxml_set_external_entity_loader() with a loader returning null, and
		// that resolver also handles the primary document, so load_file() returns
		// false for a well-formed info.xml. Parsing a string never touches it.
		$infoXml = simplexml_load_string(
			(string)file_get_contents($this->root() . '/appinfo/info.xml')
		);

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string) $infoXml->id, actual: $this->declaration()['app']);
		$this->assertSame(expected: ConnectionReporter::APP_ID, actual: $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique, in rising order, and the ones the reporter accepts.
	 *
	 * A row is keyed by app and key, so a second entry with the same key would
	 * overwrite the first. A report for a key the file does not declare is
	 * refused by integriq.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueOrderedAndKnownToTheReporter(): void {
		$connections = $this->declaration()['connections'];
		$keys        = array_column($connections, 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys, message: 'a key is declared twice');
		$this->assertSame(expected: self::DECLARED_KEYS, actual: $keys);
		$this->assertSame(expected: ConnectionReporter::KEYS, actual: $keys);

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $orders);
		$this->assertCount(expectedCount: count($keys), haystack: array_unique($orders));
	}//end testTheKeysAreUniqueOrderedAndKnownToTheReporter()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: '--', haystack: $this->raw());
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on an element id that exists under src/ or templates/.
	 *
	 * A link into a section that does not exist scrolls nowhere and logs
	 * nothing. A copy of the link itself does not count as the element, and
	 * neither does an id that only starts with the anchor.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingElement(): void {
		$sources = $this->sourcesUnder(dirs: ['src', 'templates']);
		$linked  = [];

		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string) $connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/settings/admin/buildiq#section-', string: $url, message: $connection['key']);
			$anchor = substr($url, ((int) strpos($url, '#') + 1));
			$this->assertMatchesRegularExpression(
				pattern: '/\bid="' . preg_quote($anchor, '/') . '"/',
				string: $sources,
				message: $connection['key'] . ' links to a missing element #' . $anchor
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(expected: ['store'], actual: $linked);
	}//end testEverySettingsLinkPointsAtAnExistingElement()

	/**
	 * The store requires the one key a search needs, and that key is saved by the settings form.
	 *
	 * `GenericStoreService` builds the search URL from `registry_url`. The token
	 * is optional, so requiring it would keep a public store on Not configured.
	 *
	 * @return void
	 */
	public function testTheStoreRequiresTheRegistryUrl(): void {
		$store    = $this->connectionsByKey()['store'];
		$settings = (string) file_get_contents($this->root() . '/src/views/settings/Settings.vue');

		$this->assertSame(expected: ['registry_url'], actual: $store['requiredConfig']);
		$this->assertStringContainsString(needle: 'v-model="form.registry_url"', haystack: $settings);
		$this->assertArrayNotHasKey(key: 'reportedOnly', array: $store);
		$this->assertSame(
			expected: [],
			actual: array_diff($store['requiredConfig'], ConnectionReporter::REFRESH_KEYS['store']),
			message: 'a required store key that a save does not refresh leaves the row stale'
		);
	}//end testTheStoreRequiresTheRegistryUrl()

	/**
	 * The refresh map covers only declared connections that carry config keys.
	 *
	 * @return void
	 */
	public function testOnlyConfiguredConnectionsRefresh(): void {
		$withConfig = array_keys(
			array_filter(
				$this->connectionsByKey(),
				static fn (array $connection): bool => isset($connection['requiredConfig']) === true || isset($connection['adapter']) === true
			)
		);

		$this->assertSame(expected: $withConfig, actual: array_keys(ConnectionReporter::REFRESH_KEYS));
	}//end testOnlyConfiguredConnectionsRefresh()

	/**
	 * The rows only Buildiq can judge are reported only, and carry no config for integriq to guess from.
	 *
	 * @return void
	 */
	public function testTheCallOnlyConnectionsAreReportedOnly(): void {
		$byKey = $this->connectionsByKey();

		foreach (['github', 'documents', 'rule-webhooks'] as $key) {
			$this->assertTrue(condition: $byKey[$key]['reportedOnly'], message: $key);
			$this->assertArrayNotHasKey(key: 'requiredConfig', array: $byKey[$key], message: $key);
			$this->assertArrayNotHasKey(key: 'adapter', array: $byKey[$key], message: $key);
			$this->assertArrayNotHasKey(key: 'settingsUrl', array: $byKey[$key], message: $key);
		}
	}//end testTheCallOnlyConnectionsAreReportedOnly()

	/**
	 * No LLM row: the copilot rides Nextcloud's Task Processing API and Buildiq picks no provider.
	 *
	 * If Buildiq ever stores its own provider setting, this test is where the
	 * row gets declared.
	 *
	 * @return void
	 */
	public function testNoLlmConnectionIsDeclared(): void {
		foreach (array_keys($this->connectionsByKey()) as $key) {
			$this->assertDoesNotMatchRegularExpression(pattern: '/llm|copilot|openai|ai-/i', string: $key);
		}

		$copilot = (string) file_get_contents($this->root() . '/lib/Service/CopilotService.php');
		$this->assertStringContainsString(needle: 'OCP\\\\TaskProcessing\\\\IManager', haystack: $copilot);
		$this->assertDoesNotMatchRegularExpression(
			pattern: '/getValueString\(/',
			string: $copilot,
			message: 'CopilotService reads app config now: check whether it picks a provider'
		);
	}//end testNoLlmConnectionIsDeclared()

	/**
	 * The contents of every .vue, .js and .php file under the given directories.
	 *
	 * @param array<int, string> $dirs Directories relative to the repository root.
	 *
	 * @return string
	 */
	private function sourcesUnder(array $dirs): string {
		$contents = '';
		foreach ($dirs as $dir) {
			if (is_dir($this->root() . '/' . $dir) === false) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $dir));
			foreach ($iterator as $file) {
				if ($file->isFile() === false || preg_match('/\.(vue|js|php)$/', $file->getFilename()) !== 1) {
					continue;
				}

				$contents .= (string) file_get_contents($file->getPathname()) . "\n";
			}
		}

		return $contents;
	}//end sourcesUnder()
}//end class
