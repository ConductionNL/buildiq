<?php

/**
 * Buildiq FlowWrapperPublisher
 *
 * Publishes the wrapper flows FlowBindingWrapperService keeps for an app's
 * flow bindings (logic-shared-flows-and-automations). OpenRegister subscribes
 * the triggers of a flow's PUBLISHED version only, so a wrapper left a draft
 * is saved and bound but never fires. A new wrapper is published right after
 * it is saved; a kept wrapper that is still a draft (saved before its last
 * node carried `exit`, which OpenRegister needs before it publishes a graph)
 * is re-saved with the current definition and published.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes buildiq's wrapper flows.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */
final class FlowWrapperPublisher {

	/**
	 * The lifecycle state of a flow that has never been published.
	 *
	 * @var string
	 */
	private const DRAFT = 'draft';

	/**
	 * Constructor.
	 *
	 * @param FlowService        $flowService OpenRegister's single entry point for flows.
	 * @param ContainerInterface $container   Resolves OpenRegister's flow versioning.
	 * @param LoggerInterface    $logger      Records a wrapper that could not be saved or published.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public function __construct(
		private readonly FlowService $flowService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Publish a wrapper, so its trigger subscribes.
	 *
	 * No container->has() guard: OpenRegister registers its versioning service
	 * in its own app container, and buildiq's container answers has() false for
	 * it while get() resolves it, so that guard skipped every publish and left
	 * every wrapper an unsubscribed draft. A get() that fails is caught here.
	 *
	 * @param Flow   $flow The saved wrapper.
	 * @param string $name The wrapper's name.
	 *
	 * @return bool True when the wrapper was published.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public function publish(Flow $flow, string $name): bool {
		if (interface_exists(FlowVersionServiceInterface::class) === false) {
			return false;
		}

		try {
			$this->container->get(FlowVersionServiceInterface::class)->publish(flow: $flow);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . $name . '" could not be published: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end publish()

	/**
	 * Re-save and publish a kept wrapper of this app that is still a draft; a
	 * published wrapper, or a flow that is not this app's wrapper, is left as it is.
	 *
	 * @param string              $uuid       The wrapper's flow uuid.
	 * @param string              $name       The wrapper's name.
	 * @param string              $appSlug    The application's slug.
	 * @param array<string,mixed> $definition The wrapper's current definition.
	 *
	 * @return array{name:string,reason:string}|null What failed, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowBindingWrapperService::ownWrapper() is a pure check of the flow.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public function republishDraft(string $uuid, string $name, string $appSlug, array $definition): ?array {
		try {
			$flow  = $this->flowService->find(uuid: $uuid);
			$state = (string)$flow->getLifecycleStatus();
		} catch (Throwable) {
			return null;
		}

		if ($state !== self::DRAFT || FlowBindingWrapperService::ownWrapper(flow: $flow, appSlug: $appSlug) === false) {
			return null;
		}

		try {
			$flow = $this->flowService->save(data: $definition, uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . $name . '" could not be saved: ' . $e->getMessage());
			return ['name' => $name, 'reason' => 'save_failed'];
		}

		if ($this->publish(flow: $flow, name: $name) === false) {
			return ['name' => $name, 'reason' => 'not_published'];
		}

		return null;
	}//end republishDraft()
}//end class
