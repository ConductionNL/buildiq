<?php

/**
 * OpenBuild FlowAgentChannelCollector
 *
 * Adapts {@see FlowAndAgentExportBundler} — the tested reader of "which flows
 * and agents does this application carry", already consumed by `ExportService`
 * for the openbuild-exporter's standalone app scaffold (PR #233) — into the
 * `path => contents` map convention every other `AppRepoSerializer` channel
 * collector returns.
 *
 * Split out of `AppRepoSerializer` purely for size: that class had grown past
 * its complexity/length threshold (the same tooling signal that already split
 * `DataRegisterProvisioner` and `SkillChannelDelegate` out of
 * `AppChannelApplier`). This class introduces NO new resolution logic — it
 * only translates the bundler's scratch-directory contract into an in-memory
 * map, the same adapter role `AppRepoSerializer::serialize()` would otherwise
 * have carried inline.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Collects an application's flows and agents into the repo file-map convention.
 *
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md
 */
class FlowAgentChannelCollector {

	/**
	 * Maximum bindings bundled per application, mirroring
	 * `AppRepoSerializer::MAX_CHANNEL_ENTRIES` — one application must not be
	 * able to declare the whole instance's flows into its repository.
	 *
	 * @var int
	 */
	private const MAX_CHANNEL_ENTRIES = 256;

	/**
	 * The UUID pattern flow/agent files are named by, mirroring
	 * `AppRepoSerializer::isSafeUuid()`.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

	/**
	 * Constructor.
	 *
	 * @param FlowAndAgentExportBundler|null $bundler Resolves an application's bound flows and its
	 *                                        agents. Nullable so a serializer built without it (the
	 *                                        v1 construction shape) degrades to an empty channel.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?FlowAndAgentExportBundler $bundler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Collect an application's bound flows and the agents that point at it.
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return array{flows:array<string,array<string,mixed>>,agents:array<string,array<string,mixed>>,declaredFlows:int,skipped:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-bound-flows-and-agents
	 */
	public function collect(array $application): array {
		$empty = ['flows' => [], 'agents' => [], 'declaredFlows' => 0, 'skipped' => []];
		if ($this->bundler === null) {
			return $empty;
		}

		$bindings = ($application['flows'] ?? []);
		if (is_array($bindings) === false) {
			$bindings = [];
		}

		// Bounded BEFORE the bundler runs, mirroring collectDataRegisters()'s
		// posture: one application must not be able to declare the whole
		// instance's flows into its repository.
		$bindings = array_slice($bindings, 0, self::MAX_CHANNEL_ENTRIES);
		$empty['declaredFlows'] = count($bindings);

		$slug = (string)($application['slug'] ?? '');
		$scratch = sys_get_temp_dir() . '/openbuild-repo-flows-' . bin2hex(random_bytes(8));

		if (is_dir($scratch) === false && mkdir($scratch, 0o700, true) === false) {
			$this->logger->warning('OpenBuild FlowAgentChannelCollector: could not create a scratch directory for the flows/agents channel.');
			return $empty;
		}

		try {
			$skipped = $this->bundler->bundle(rootDir: $scratch, flows: $bindings, applicationSlug: $slug);

			return [
				'flows' => $this->readChannelDir(dir: $scratch . '/lib/Settings/flows'),
				'agents' => $this->readChannelDir(dir: $scratch . '/lib/Settings/agents'),
				'declaredFlows' => $empty['declaredFlows'],
				'skipped' => $skipped,
			];
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild FlowAgentChannelCollector: flows/agents bundling failed: ' . $e->getMessage());
			return $empty;
		} finally {
			$this->removeScratchDir(path: $scratch);
		}
	}//end collect()

	/**
	 * Read every `<uuid>.json` file the bundler wrote into one scratch directory.
	 *
	 * The UUID is re-validated from the filename before use — the parser-side
	 * rule ("every channel path is validated before use") applies just as much
	 * reading the bundler's own scratch tree back, so a bundler defect can
	 * never smuggle a crafted filename into the repo file map.
	 *
	 * @param string $dir The scratch subdirectory (may not exist).
	 *
	 * @return array<string,array<string,mixed>> Decoded payloads keyed by UUID.
	 */
	private function readChannelDir(string $dir): array {
		$out = [];
		if (is_dir($dir) === false) {
			return $out;
		}

		$entries = scandir($dir);
		if ($entries === false) {
			return $out;
		}

		foreach ($entries as $entry) {
			if (str_ends_with($entry, '.json') === false) {
				continue;
			}

			$uuid = substr($entry, 0, -strlen('.json'));
			if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
				continue;
			}

			$contents = file_get_contents($dir . '/' . $entry);
			if ($contents === false) {
				continue;
			}

			$decoded = json_decode($contents, true);
			if (is_array($decoded) === false) {
				continue;
			}

			$out[$uuid] = $decoded;
		}//end foreach

		return $out;
	}//end readChannelDir()

	/**
	 * Recursively remove a scratch directory tree.
	 *
	 * @param string $path The directory to remove.
	 *
	 * @return void
	 */
	private function removeScratchDir(string $path): void {
		if (is_dir($path) === false) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			if ($item->isDir() === true) {
				rmdir($item->getPathname());
				continue;
			}

			unlink($item->getPathname());
		}

		rmdir($path);
	}//end removeScratchDir()
}//end class
