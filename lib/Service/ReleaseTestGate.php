<?php

/**
 * Release Test Gate
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Runs an app version's test suite before it is released or promoted onto
 * production, keeps every run as an `app-test-run` object, and stops the
 * release when a check fails (lifecycle-release-test-gate D2, D3).
 *
 * - A green suite lets the release through.
 * - A failing suite throws {@see ReleaseTestsFailedException} with the run,
 *   before the caller writes anything.
 * - An owner may release anyway with a reason; the run then records the
 *   failures, the owner and the reason. A caller who is not an owner, or an
 *   override without a reason, is refused.
 *
 * A run that cannot be stored is logged; the gate itself does not depend on
 * the store, except for an override, which must be recorded or the release
 * does not go ahead.
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
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\ReleaseOverrideRefusedException;
use OCA\Buildiq\Exception\ReleaseTestsFailedException;
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\Buildiq\Support\ReleaseOverride;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The test gate in front of a release and a promotion onto production.
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
 */
class ReleaseTestGate {

	/**
	 * The schema slug of a stored run.
	 */
	public const TEST_RUN_SCHEMA = 'app-test-run';

	public const TRIGGER_MANUAL = 'manual';
	public const TRIGGER_RELEASE = 'release';
	public const TRIGGER_PROMOTION = 'promotion';

	/**
	 * Constructor.
	 *
	 * @param AppTestSuiteService    $suite         Runs the suite of a version.
	 * @param ObjectServiceInterface $objectService Stores the runs.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly AppTestSuiteService $suite,
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the suite before a release or a promotion and stop on a failure.
	 *
	 * @param array<string, mixed>  $application The built app.
	 * @param array<string, mixed>  $version     The version about to reach production, with its manifest.
	 * @param string                $trigger     {@see self::TRIGGER_RELEASE} or {@see self::TRIGGER_PROMOTION}.
	 * @param ReleaseOverride|null  $override    An owner's "release anyway", when asked for.
	 *
	 * @return array<string, mixed> The stored run (its counts, report and any override).
	 *
	 * @throws ReleaseTestsFailedException     When a check fails and no override was given.
	 * @throws ReleaseOverrideRefusedException When the override is not the owner's, or has no reason.
	 * @throws RuntimeException                When an override cannot be recorded.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function guard(array $application, array $version, string $trigger, ?ReleaseOverride $override = null): array {
		$report = $this->suite->run(application: $application, version: $version);

		if ($report['failed'] === 0) {
			return $this->record(application: $application, version: $version, trigger: $trigger, report: $report, override: null);
		}

		if ($override === null) {
			throw new ReleaseTestsFailedException(
				run: $this->record(application: $application, version: $version, trigger: $trigger, report: $report, override: null)
			);
		}

		self::assertMayOverride(override: $override);

		$run = $this->record(application: $application, version: $version, trigger: $trigger, report: $report, override: $override);
		if (($run['id'] ?? '') === '') {
			throw new RuntimeException(message: 'The override could not be recorded, so the version was not released.');
		}

		$this->logger->warning(
			'Buildiq: {user} released {app} past {failed} failed test(s): {reason}',
			[
				'user' => $override->userId,
				'app' => (string)($application['slug'] ?? ''),
				'failed' => $report['failed'],
				'reason' => $override->reason,
			]
		);

		return $run;
	}//end guard()

	/**
	 * Run the suite on demand ("Run tests") and keep the run.
	 *
	 * @param array<string, mixed> $application The built app.
	 * @param array<string, mixed> $version     The version, with its manifest.
	 *
	 * @return array<string, mixed> The run, stored when the store accepted it.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	public function runManually(array $application, array $version): array {
		$report = $this->suite->run(application: $application, version: $version);

		return $this->record(application: $application, version: $version, trigger: self::TRIGGER_MANUAL, report: $report, override: null);
	}//end runManually()

	/**
	 * Refuse an override that is not an owner's, or that gives no reason.
	 *
	 * @param ReleaseOverride $override The override asked for.
	 *
	 * @return void
	 *
	 * @throws ReleaseOverrideRefusedException When it is not allowed.
	 */
	private static function assertMayOverride(ReleaseOverride $override): void {
		if ($override->byOwner === false) {
			throw new ReleaseOverrideRefusedException(
				errorCode: ReleaseOverrideRefusedException::NOT_OWNER,
				message: 'Only an owner of the app may release past failing tests.'
			);
		}

		if (trim($override->reason) === '') {
			throw new ReleaseOverrideRefusedException(
				errorCode: ReleaseOverrideRefusedException::REASON_REQUIRED,
				message: 'Releasing past failing tests needs a reason.'
			);
		}
	}//end assertMayOverride()

	/**
	 * Store one run. A store failure is logged and answers the run without an id.
	 *
	 * @param array<string, mixed>                                                  $application The built app.
	 * @param array<string, mixed>                                                  $version     The version.
	 * @param string                                                                $trigger     What started the run.
	 * @param array{passed: int, failed: int, skipped: int, checks: list<array<string, string>>} $report The suite's report.
	 * @param ReleaseOverride|null                                                  $override    The accepted override.
	 *
	 * @return array<string, mixed> The run; `id` set when stored.
	 */
	private function record(array $application, array $version, string $trigger, array $report, ?ReleaseOverride $override): array {
		$run = [
			'application' => self::idOf(object: $application),
			'version' => self::idOf(object: $version),
			'ranAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'trigger' => $trigger,
			'passed' => $report['passed'],
			'failed' => $report['failed'],
			'skipped' => $report['skipped'],
			'report' => $report['checks'],
		];
		if ($override !== null) {
			$run['override'] = ['user' => $override->userId, 'reason' => $override->reason];
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $run,
				register: ApplicationVersionServiceInterface::REGISTER_SLUG,
				schema: self::TEST_RUN_SCHEMA
			);
			$run['id'] = self::savedId(saved: $saved);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the test run of version {version} could not be stored: {message}',
				['version' => $run['version'], 'message' => $e->getMessage(), 'exception' => $e]
			);
			$run['id'] = '';
		}

		return $run;
	}//end record()

	/**
	 * The uuid of a stored object.
	 *
	 * @param mixed $saved What the store answered.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private static function savedId(mixed $saved): string {
		if ($saved instanceof ObjectEntity === true && (string)$saved->getUuid() !== '') {
			return (string)$saved->getUuid();
		}

		return self::idOf(object: ObjectRecord::castArray(object: $saved));
	}//end savedId()

	/**
	 * The uuid of an object array: `id`, else `uuid`, else `@self.id`.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string
	 */
	private static function idOf(array $object): string {
		$self = (array)($object['@self'] ?? []);

		return trim((string)($object['id'] ?? ($object['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()
}//end class
