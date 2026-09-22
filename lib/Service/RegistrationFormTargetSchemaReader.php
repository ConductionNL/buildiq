<?php

/**
 * Registration Form Target Schema Reader
 *
 * Reads the schema a registration form writes into, so the rules that judge a
 * form can be given the two things they need from it: the properties it
 * declares, and the values its channel property accepts.
 *
 * WHY THIS EXISTS
 * ---------------
 * `RegistrationFormValidator` has taken `$targetProperties` and
 * `$targetChannels` since it was written, and `RegistrationFormAuthoringService`
 * passed null for both. Null is the validator's documented "cannot be read",
 * so every preset naming a property that does not exist was saved without a
 * warning, and every channel the consumer never heard of was saved without a
 * refusal. The rules were written, tested and enforced on nothing.
 *
 * WHICH PROPERTY IS THE CHANNEL
 * -----------------------------
 * The form says so, in `channelProperty`, the same way it already says which
 * property carries the type in `typeProperty`. Guessing it from a name that
 * looks channel-ish would refuse a save on the strength of a pattern match,
 * which is a worse failure than not refusing at all. A form that names no
 * channel property gets a null channel list and keeps today's behaviour.
 *
 * A SCHEMA THAT CANNOT BE READ IS SAID OUT LOUD
 * ---------------------------------------------
 * Buildiq reads this schema across an app boundary and the read can fail:
 * openregister absent, the register renamed, the slug gone. Failing the save
 * would leave an administrator with a builder that refuses everything. So the
 * read returns null and a `note`, the note travels back with the save as a
 * warning, and the author sees that the check did not run. A check that
 * silently did not run looks exactly like one that passed.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the target schema's properties and accepted channels.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-007)
 */
final class RegistrationFormTargetSchemaReader {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Resolves the consuming register.
	 * @param SchemaMapper $schemaMapper Resolves the schemas it holds.
	 * @param LoggerInterface $logger Logger.
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
	 * What the target schema declares.
	 *
	 * @param string $registerSlug The consuming app's register.
	 * @param string $schemaSlug The schema the form writes into.
	 * @param string $channelProperty The property carrying the intake channel, empty when the form names none.
	 *
	 * @return array{properties: array<int, string>|null, channels: array<int, string>|null, note: string|null}
	 *   The declared property names, the channel values, and a sentence to show
	 *   the author when either could not be read.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
	 */
	public function read(string $registerSlug, string $schemaSlug, string $channelProperty = ''): array {
		$unread = ['properties' => null, 'channels' => null, 'note' => null];

		if ($registerSlug === '' || $schemaSlug === '') {
			return $unread;
		}

		try {
			$properties = $this->propertiesOf(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: could not read target schema "' . $schemaSlug . '" in register "'
				. $registerSlug . '": ' . $e->getMessage(),
				['exception' => $e]
			);

			$unread['note'] = sprintf(
				'The schema "%s" could not be read, so the field and channel checks did not run on this save.',
				$schemaSlug
			);

			return $unread;
		}

		if ($properties === null) {
			return [
				'properties' => null,
				'channels' => null,
				'note' => sprintf(
					'The register "%s" holds no schema called "%s", so the field and channel checks did not run on this save.',
					$registerSlug,
					$schemaSlug
				),
			];
		}

		return [
			'properties' => array_keys($properties),
			'channels' => $this->channelsOf(properties: $properties, channelProperty: $channelProperty),
			'note' => null,
		];
	}//end read()

	/**
	 * The property map of one schema in one register, matched by slug.
	 *
	 * Matched inside the register rather than by a bare slug lookup: two
	 * registers may each hold a schema called `case`, and the wrong one's
	 * properties would produce confident warnings about the wrong object.
	 *
	 * @param string $registerSlug The register.
	 * @param string $schemaSlug The schema.
	 *
	 * @return array<string, mixed>|null The properties, or null when the register holds no such schema.
	 */
	private function propertiesOf(string $registerSlug, string $schemaSlug): ?array {
		$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		$wanted = strtolower($schemaSlug);

		foreach ((array)($register->getSchemas() ?? []) as $schemaId) {
			$schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
			if (strtolower((string)$schema->getSlug()) !== $wanted) {
				continue;
			}

			$properties = $schema->getProperties();

			return is_array($properties) === true ? $properties : [];
		}

		return null;
	}//end propertiesOf()

	/**
	 * The values the named channel property accepts.
	 *
	 * @param array<string, mixed> $properties The target schema's properties.
	 * @param string $channelProperty The property the form nominates, empty when it nominates none.
	 *
	 * @return array<int, string>|null The accepted channels, or null when none are declared.
	 */
	private function channelsOf(array $properties, string $channelProperty): ?array {
		if ($channelProperty === '' || isset($properties[$channelProperty]) === false) {
			return null;
		}

		$definition = $properties[$channelProperty];
		if (is_array($definition) === false || is_array(($definition['enum'] ?? null)) === false) {
			// A channel property without an enum accepts anything the consumer
			// cares to write, so there is nothing to refuse against.
			return null;
		}

		$channels = [];
		foreach ($definition['enum'] as $value) {
			if (is_string($value) === true && $value !== '') {
				$channels[] = $value;
			}
		}

		return $channels === [] ? null : $channels;
	}//end channelsOf()
}//end class
