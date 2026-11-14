<?php

/**
 * ObjectRecord answers what each class's own coercion answered before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use JsonSerializable;
use OCA\Buildiq\Support\ObjectRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

/**
 * The fixture holds the output of every former private coercion method
 * (normalise(), normaliseObject(), toArray(), extractObjectData() and the
 * like) over the inputs below, recorded before those copies were folded into
 * ObjectRecord. Each copy must get the same answers from the method that
 * replaces it. The doubles keep public properties because an array cast
 * reads them.
 *
 * @covers \OCA\Buildiq\Support\ObjectRecord
 */
class ObjectRecordTest extends TestCase {

	/**
	 * The ObjectRecord method each former copy (`Class::method`) now calls.
	 *
	 * @var array<string,string>
	 */
	private const METHOD_OF = [
		'OCA\Buildiq\Listener\DocumentGenerationListener::normalise' => 'toArray',
		'OCA\Buildiq\Controller\AgentsController::normalise' => 'toArray',
		'OCA\Buildiq\Service\RuleImpactAnalysisService::normalise' => 'toArray',
		'OCA\Buildiq\Listener\AutomationApprovalTriggerListener::normalise' => 'toArray',
		'OCA\Buildiq\Service\DocumentGenerationService::normalise' => 'toArray',
		'OCA\Buildiq\Service\AutomationCompilerService::normalise' => 'toArray',
		'OCA\Buildiq\Service\AutomationWriteService::normalise' => 'toArray',
		'OCA\Buildiq\Listener\ApprovalOutcomeListener::normalise' => 'toArray',
		'OCA\Buildiq\Service\RuleObjectReader::normalise' => 'toArray',
		'OCA\Buildiq\BackgroundJob\RuleExecutionLogCleanup::normalise' => 'serialised',
		'OCA\Buildiq\Command\PublishTemplates::normalise' => 'serialised',
		'OCA\Buildiq\Service\ExportAppSchemaReader::normalise' => 'serialised',
		'OCA\Buildiq\Service\ExportAppSourceResolver::normalise' => 'serialised',
		'OCA\Buildiq\Service\GitHubAppSyncService::normalise' => 'serialised',
		'OCA\Buildiq\Repair\MoveDecisionTablesToOpenRegister::normalise' => 'serialised',
		'OCA\Buildiq\Service\AppHealthService::normalise' => 'serialised',
		'OCA\Buildiq\Service\RuleActionDispatcher::normalise' => 'arrayOrNull',
		'OCA\Buildiq\Controller\ApplicationVersionsController::normaliseObject' => 'toArray',
		'OCA\Buildiq\Controller\VersionPromotionController::normaliseObject' => 'toArray',
		'OCA\Buildiq\Controller\ApplicationsController::normaliseObject' => 'toArray',
		'OCA\Buildiq\Controller\ApplicationPublishController::normaliseObject' => 'toArray',
		'OCA\Buildiq\Repair\PopulateApplicationPermissions::normaliseObject' => 'toArray',
		'OCA\Buildiq\Repair\MigrateAppOverridesToHybrid::normaliseObjectArray' => 'toArray',
		'OCA\Buildiq\Repair\MigrateToVersionedModel::normaliseObjectArray' => 'toArray',
		'OCA\Buildiq\Service\ApplicationVersionService::normaliseObjectArray' => 'toArray',
		'OCA\Buildiq\Service\ManifestResolverService::normaliseObject' => 'toArray',
		'OCA\Buildiq\Service\IconService::normaliseObject' => 'toArray',
		'OCA\Buildiq\Service\ApplicationInsightsService::normaliseObject' => 'toArray',
		'OCA\Buildiq\Service\AppOverrideService::normaliseObject' => 'toArray',
		'OCA\Buildiq\Service\PublishedApplicationProvider::normaliseObject' => 'toArray',
		'OCA\Buildiq\Service\Dashboard\WidgetItemProjector::normaliseRow' => 'toArray',
		'OCA\Buildiq\Service\DataRegisterExportBundler::normaliseObjectArray' => 'toArray',
		'OCA\Buildiq\Service\ApplicationCreationService::normaliseObject' => 'toArray',
		'OCA\Buildiq\Controller\ExportsController::toArray' => 'serialised',
		'OCA\Buildiq\Mcp\Handler\AbstractToolHandler::toArray' => 'castArray',
		'OCA\Buildiq\Service\AgentRunLogger::toArray' => 'castArray',
		'OCA\Buildiq\Service\CopilotService::toArray' => 'castArray',
		'OCA\Buildiq\Listener\ProductionVersionGuardListener::extractObjectData' => 'entityData',
		'OCA\Buildiq\Listener\AutomationCleanupListener::extractObjectData' => 'entityData',
		'OCA\Buildiq\Listener\HybridMetadataLockListener::extractObjectData' => 'entityData',
	];

	/**
	 * One case per former copy.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::METHOD_OF) as $copy) {
			$cases[substr(strrchr($copy, '\\'), 1)] = [$copy];
		}

		return $cases;
	}//end classes()

	/**
	 * Every input answers as the recorded copy answered.
	 *
	 * @param string $copy The former copy compared, as `Class::method`.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testAnswersAsTheFormerCopy(string $copy): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/object-record-golden.json'), true);
		$method = self::METHOD_OF[$copy];
		$inputs = self::inputs();
		$this->assertSame(expected: array_keys($inputs), actual: array_keys($golden[$copy]));
		foreach ($inputs as $name => $input) {
			$this->assertSame(expected: $golden[$copy][$name], actual: self::answer(method: $method, input: $input), message: $copy . ' / ' . $name);
		}
	}//end testAnswersAsTheFormerCopy()

	/**
	 * What the ObjectRecord method answers, or 'TypeError' when the input is refused.
	 *
	 * @param string $method The ObjectRecord method.
	 * @param mixed  $input  The input.
	 *
	 * @return mixed
	 */
	private static function answer(string $method, mixed $input): mixed {
		try {
			if ($method === 'entityData') {
				return ObjectRecord::entityData(entity: $input);
			}

			return ObjectRecord::$method(object: $input);
		} catch (TypeError) {
			return 'TypeError';
		}
	}//end answer()

	/**
	 * The recorded inputs, in recording order.
	 *
	 * @return array<string,mixed>
	 */
	private static function inputs(): array {
		return [
			'array' => ['a' => 1, 'b' => ['c' => 2]],
			'emptyArray' => [],
			'list' => [1, 2],
			'null' => null,
			'string' => 'x',
			'int' => 7,
			'plainObject' => new stdClass(),
			'serialisedArray' => self::serialising(value: ['id' => 'u1']),
			'serialisedEmpty' => self::serialising(value: []),
			'serialisedString' => self::serialising(value: 's'),
			'serialisedNull' => self::serialising(value: null),
			'innerArray' => self::wrapping(value: ['id' => 'i1']),
			'innerString' => self::wrapping(value: 'i'),
			'innerNull' => self::wrapping(value: null),
			'bothArrays' => self::serialisingAndWrapping(serialised: ['from' => 'ser'], inner: ['from' => 'inner']),
			'bothSerEmpty' => self::serialisingAndWrapping(serialised: [], inner: ['from' => 'inner']),
			'bothSerScalar' => self::serialisingAndWrapping(serialised: 'x', inner: ['from' => 'inner']),
			'bothScalars' => self::serialisingAndWrapping(serialised: 'x', inner: 'y'),
			'serialisedSelf' => self::serialising(value: ['@self' => ['id' => 1], 'id' => 's']),
			'innerSelf' => self::wrapping(value: ['@self' => 1, 'id' => 'i']),
			'bothSelf' => self::serialisingAndWrapping(serialised: ['@self' => 2, 'k' => 'ser'], inner: 'notArray'),
		];
	}//end inputs()

	/**
	 * An entity whose jsonSerialize() returns the value.
	 *
	 * @param mixed $value The serialised value.
	 *
	 * @return JsonSerializable
	 */
	private static function serialising(mixed $value): JsonSerializable {
		return new class($value) implements JsonSerializable {
			/**
			 * @param mixed $v The serialised value.
			 */
			public function __construct(public mixed $v) {
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->v;
			}
		};
	}//end serialising()

	/**
	 * An object whose getObject() returns the value.
	 *
	 * @param mixed $value The wrapped value.
	 *
	 * @return object
	 */
	private static function wrapping(mixed $value): object {
		return new class($value) {
			/**
			 * @param mixed $v The wrapped value.
			 */
			public function __construct(public mixed $v) {
			}

			/**
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->v;
			}
		};
	}//end wrapping()

	/**
	 * An entity with both jsonSerialize() and getObject().
	 *
	 * @param mixed $serialised What jsonSerialize() returns.
	 * @param mixed $inner      What getObject() returns.
	 *
	 * @return JsonSerializable
	 */
	private static function serialisingAndWrapping(mixed $serialised, mixed $inner): JsonSerializable {
		return new class($serialised, $inner) implements JsonSerializable {
			/**
			 * @param mixed $s What jsonSerialize() returns.
			 * @param mixed $i What getObject() returns.
			 */
			public function __construct(public mixed $s, public mixed $i) {
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->s;
			}

			/**
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->i;
			}
		};
	}//end serialisingAndWrapping()
}//end class
