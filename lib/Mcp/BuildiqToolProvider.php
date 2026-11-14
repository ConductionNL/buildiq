<?php

/**
 * Buildiq MCP Tool Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider. Exposes the
 * full Buildiq authoring surface to an LLM via MCP: list/read apps, create
 * new apps, promote versions, and mutate a draft version's manifest (pages,
 * widgets, menu items) and per-version schemas.
 *
 * This class is a thin dispatcher: all tool logic lives in dedicated handler
 * classes under OCA\Buildiq\Mcp\Handler\.
 *
 * @category Service
 * @package  OCA\Buildiq\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-8
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-42
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-50
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-59
 * @spec openspec/changes/archive/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-1
 * @spec openspec/changes/archive/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-2
 * @spec openspec/changes/archive/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-3
 * @spec openspec/changes/archive/retrofit-2026-05-24-openbuild-runtime-mcp/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Buildiq\Mcp;

use OCA\Buildiq\Kernel\Contracts\BuildiqToolProviderInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Mcp\Handler\AddWidgetHandler;
use OCA\Buildiq\Service\AgentPlanService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Buildiq MCP Tool Provider — thin dispatcher to per-tool handler classes.
 *
 * Read tools:
 *   - buildiq.listApps        → ListAppsHandler
 *   - buildiq.getAppManifest  → GetAppManifestHandler
 *
 * Write tools (lifecycle):
 *   - buildiq.createApp       → CreateAppHandler
 *   - buildiq.promoteVersion  → PromoteVersionHandler
 *
 * Write tools (authoring against the draft version's manifest):
 *   - buildiq.upsertSchema    → UpsertSchemaHandler
 *   - buildiq.upsertPage      → UpsertPageHandler
 *   - buildiq.addWidget       → AddWidgetHandler
 *   - buildiq.upsertMenuItem  → UpsertMenuItemHandler
 *
 * Authoring tools default to the `development` version so a misfired tool
 * call cannot mutate production. To promote the change use promoteVersion.
 */
class BuildiqToolProvider implements IMcpToolProvider, BuildiqToolProviderInterface {

	/**
	 * Tool catalogue.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const TOOL_DESCRIPTORS = [
		[
			'id' => 'buildiq.listApps',
			'subject' => 'app',
			'action' => 'list',
			'name' => 'List virtual apps',
			'description' => 'List the virtual apps built with Buildiq in your organisation.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
					'statusFilter' => ['type' => 'string', 'enum' => ['any', 'draft', 'published', 'archived'], 'default' => 'any'],
				],
				'required' => [],
			],
		],
		[
			'id' => 'buildiq.getAppManifest',
			'subject' => 'appManifest',
			'action' => 'get',
			'name' => 'Get virtual app manifest',
			'description' => 'Fetch the runtime manifest blob for one published virtual app by slug.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
				],
				'required' => ['slug'],
			],
		],
		[
			'id' => 'buildiq.createApp',
			'subject' => 'app',
			'action' => 'create',
			'name' => 'Create a new virtual app',
			'description' => 'Create a new Buildiq virtual app with an initial draft ApplicationVersion.'
				. ' Preset chooses the version chain: "single", "dev-prod" or "dev-staging-prod".',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
					'description' => ['type' => 'string', 'maxLength' => 500],
					'preset' => ['type' => 'string', 'enum' => ['single', 'dev-prod', 'dev-staging-prod'], 'default' => 'dev-prod'],
				],
				'required' => ['slug', 'name'],
			],
		],
		[
			'id' => 'buildiq.promoteVersion',
			// `promote`, not `update`: it advances a version through a release
			// pipeline. A grant reading "may update apps" should not silently
			// carry the right to push a version to production.
			'subject' => 'version',
			'action' => 'promote',
			'name' => 'Promote a virtual app version',
			'description' => 'Promote a virtual app from one version (e.g. development) to the next (e.g. production).'
				. ' Strategy "empty-start" (default, safest) leaves the target empty.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'appSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'sourceVersionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'strategy' => [
						'type' => 'string',
						'enum' => ['empty-start', 'start-with-source-data', 'migrate-existing-data'],
						'default' => 'empty-start',
					],
				],
				'required' => ['appSlug', 'sourceVersionSlug'],
			],
		],
		[
			'id' => 'buildiq.upsertSchema',
			'subject' => 'schema',
			'action' => 'upsert',
			'name' => 'Create or update a schema in a virtual app',
			'description' => 'Create or update a JSON Schema in the given app version\'s per-version OR register.'
				. ' Slug is automatically namespaced with appSlug+versionSlug.'
				. ' Properties is a JSON Schema property map; required is an array of property names.'
				. ' Defaults versionSlug to "development".',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'appSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
					'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'title' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
					'description' => ['type' => 'string', 'maxLength' => 500],
					'properties' => ['type' => 'object'],
					'required' => ['type' => 'array', 'items' => ['type' => 'string']],
				],
				'required' => ['appSlug', 'slug', 'title', 'properties'],
			],
		],
		[
			'id' => 'buildiq.upsertPage',
			'subject' => 'page',
			'action' => 'upsert',
			'name' => 'Create or update a page in a virtual app',
			'description' => 'Create or update a page in the draft manifest.'
				. ' pageId is the unique key; if it exists it is replaced.'
				. ' Type is one of dashboard, index, detail, form.'
				. ' config is page-type-specific, and each type has fields the manifest is invalid without:'
				. ' index and detail take {register, schema} plus optional columns, where schema is the'
				. ' short slug you gave upsertSchema (e.g. "loan") — Buildiq namespaces it onto this'
				. " version's own register for you;"
				. ' dashboard takes {widgets, layout}, or leave both out and use addWidget;'
				. ' form MUST take a non-empty fields array, each entry {key, label, type} with type one of'
				. ' boolean, number, string, enum, password, json, file, and exactly one of'
				. ' submitHandler or submitEndpoint saying where the form posts.'
				. ' Defaults versionSlug to "development".',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'appSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
					'pageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
					'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
					'type' => ['type' => 'string', 'enum' => ['dashboard', 'index', 'detail', 'form']],
					'route' => [
						'type' => 'string',
						'minLength' => 1,
						'maxLength' => 200,
						'description' => 'A path starting with "/", e.g. "/tools" or "/tools/:id".',
					],
					'config' => ['type' => 'object'],
				],
				'required' => ['appSlug', 'pageId', 'title', 'type', 'route'],
			],
		],
		[
			'id' => 'buildiq.addWidget',
			'subject' => 'widget',
			'action' => 'create',
			'name' => 'Add a widget to a page',
			'description' => 'Append a widget to a page\'s config.widgets array in the draft manifest,'
				. ' and place it on the page\'s grid. The target page must be type "dashboard".'
				. ' widgetType MUST be one of the values in its enum below. widgetConfig is'
				. ' widget-type-specific: a stat, gauge or delta tile takes {register, schema}'
				. ' plus an aggregate; an object-list takes {register, schema} plus columns.'
				. ' Give every widget a widgetId (kebab-case, unique within the page) and a title:'
				. ' both are stored on the widget and the manifest is invalid without them.'
				. ' Omit them only if you have nothing better, and one will be derived.'
				. ' Defaults versionSlug to "development".',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'appSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
					'pageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
					// The enum is the handler's own allow-list, spelt out so the
					// plan validator can refuse an unknown type at plan time
					// instead of the executor refusing it after the reader has
					// already clicked Confirm. Keep it in step with
					// AddWidgetHandler::ALLOWED_WIDGET_TYPES; WidgetTypeParityTest
					// fails when the two drift.
					'widgetType' => [
						'type' => 'string',
						'enum' => AddWidgetHandler::ALLOWED_WIDGET_TYPES,
						'minLength' => 1,
						'maxLength' => 48,
					],
					'widgetId' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
					'widgetConfig' => ['type' => 'object'],
				],
				// Note that widgetId and title stay OUT of `required` on purpose.
				// The description asks for both, and the handler derives them
				// when they are missing. Making them required would turn a model
				// that forgot one into a rejected plan, which is the failure
				// this change exists to remove.
				'required' => ['appSlug', 'pageId', 'widgetType'],
			],
		],
		[
			'id' => 'buildiq.upsertMenuItem',
			'subject' => 'menuItem',
			'action' => 'upsert',
			'name' => 'Create or update a menu item',
			'description' => 'Create or update a top-level menu item in the draft manifest.'
				. ' id is the unique key; if it exists it is replaced.'
				. ' route names the page this item opens: give that page\'s pageId (the page\'s own'
				. ' route path works too). Never invent a route no page has.'
				. ' order controls sort. icon is an MDI/standard icon name. Defaults versionSlug to "development".',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'appSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
					'versionSlug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'default' => 'development'],
					'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
					'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
					'icon' => ['type' => 'string', 'maxLength' => 80],
					'route' => [
						'type' => 'string',
						'minLength' => 1,
						'maxLength' => 200,
						'description' => 'The pageId of the page this item opens, e.g. "tools". A path works too.',
					],
					'order' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 999],
				],
				'required' => ['appSlug', 'id', 'label', 'route'],
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession User session used to resolve the current authenticated user.
	 * @param IGroupManager $groupManager Group manager used for admin checks.
	 * @param ContainerInterface $container DI container used to resolve OpenRegister and Buildiq services lazily.
	 * @param LoggerInterface $logger PSR logger used for non-fatal warnings and error logging.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object contract (ADR-084), threaded to every handler.
	 * @param PermissionResolverInterface|null $permissionResolver Shared permission-grammar resolver (H1 fix).
	 * @param AuditTrailMapper|null $auditTrailMapper Optional OR audit-trail writer threaded to handlers (L2).
	 * @param AgentPlanService|null $planService Approved-plan store for outside agents' writes (null: every MCP write is refused).
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?PermissionResolverInterface $permissionResolver = null,
		private readonly ?AuditTrailMapper $auditTrailMapper = null,
		private readonly ?AgentPlanService $planService = null,
	) {
	}//end __construct()

	/**
	 * Return the Nextcloud app id this provider belongs to.
	 *
	 * @return string
	 */
	public function getAppId(): string {
		return 'buildiq';
	}//end getAppId()

	/**
	 * Return the catalogue of MCP tools exposed by this provider.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTools(): array {
		$tools = [];
		foreach (self::TOOL_DESCRIPTORS as $tool) {
			if (AgentPlanService::isWriteTool(toolId: $tool['id']) === true) {
				$tool['description'] .= ' Writes only under a plan a person approved: pass planId.'
					. ' A call without planId writes nothing and submits this call as a plan for an owner or editor of the app to approve in Buildiq.';
				$tool['inputSchema']['properties']['planId'] = [
					'type' => 'string',
					'maxLength' => 64,
					'description' => 'The id of the approved plan this call is a step of.',
				];
			}

			$tools[] = $tool;
		}

		return $tools;
	}//end getTools()

	/**
	 * Return the tool catalogue for consumers that need the raw descriptor
	 * array (id + inputSchema) rather than the MCP-shaped {@see getTools()}
	 * response. `TOOL_DESCRIPTORS` stays private/single-source; this is the
	 * one public accessor other services read it through — currently the
	 * AI copilot's plan validator/prompt builder
	 * ({@see \OCA\Buildiq\Service\Copilot\CopilotPlanValidator},
	 * {@see \OCA\Buildiq\Service\Copilot\CopilotPromptBuilder}), which
	 * restrict LLM-proposed plan steps to exactly these tool ids and
	 * validate each step's arguments against the matching `inputSchema`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function getToolDescriptors(): array {
		return self::TOOL_DESCRIPTORS;
	}//end getToolDescriptors()

	/**
	 * Dispatch an MCP tool invocation from an outside agent.
	 *
	 * Reads run at once. A write runs only when `arguments.planId` names a plan
	 * a person with a write role on the app approved, the caller is the user
	 * who submitted it, and the call matches one of its unused steps. A write
	 * without a planId writes nothing and is submitted as a one-step plan.
	 *
	 * @param string $toolId Fully qualified tool id (e.g. "buildiq.listApps").
	 * @param array<string, mixed> $arguments Raw tool arguments as supplied by the MCP client.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		if (AgentPlanService::isWriteTool(toolId: $toolId) === false) {
			return $this->dispatch(toolId: $toolId, arguments: $arguments);
		}

		$refusal = $this->planRefusal(toolId: $toolId, arguments: $arguments);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->dispatch(toolId: $toolId, arguments: AgentPlanService::withoutPlanId(arguments: $arguments));
	}//end invokeTool()

	/**
	 * Dispatch a step of a plan the calling user approved in Buildiq itself.
	 *
	 * The AI Copilot's execute is that approval: the person reviewed the
	 * predicted manifest and confirmed it. This entry is not reachable over
	 * MCP; OpenRegister's MCP server calls {@see invokeTool()}.
	 *
	 * @param string $toolId Fully qualified tool id.
	 * @param array<string, mixed> $arguments The step's arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
	 */
	public function invokeApprovedTool(string $toolId, array $arguments): array {
		return $this->dispatch(toolId: $toolId, arguments: AgentPlanService::withoutPlanId(arguments: $arguments));
	}//end invokeApprovedTool()

	/**
	 * Why an outside agent's write call may not run, or null when it may.
	 *
	 * @param string $toolId The write tool.
	 * @param array<string, mixed> $arguments The call's arguments.
	 *
	 * @return array<string, mixed>|null
	 */
	private function planRefusal(string $toolId, array $arguments): ?array {
		if ($this->planService === null) {
			return [
				'isError' => true,
				'error' => 'plan_required',
				'message' => 'Builder writes over MCP need an approved plan, and plan approval is not available on this instance.',
			];
		}

		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		$planId = $arguments['planId'] ?? null;
		if (is_string($planId) === false || $planId === '') {
			$submitted = $this->planService->submit(
				appSlug: AgentPlanService::targetApp(toolId: $toolId, arguments: $arguments),
				steps: [['tool' => $toolId, 'arguments' => AgentPlanService::withoutPlanId(arguments: $arguments)]],
				userId: $userId
			);
			$planNote = '';
			if (isset($submitted['planId']) === true) {
				$planNote = ' This call was submitted as plan ' . $submitted['planId']
					. '; once an owner or editor of the app approves it in Buildiq (the copilot panel, Plans from outside agents),'
					. ' call again with the same arguments and planId.';
			}

			return [
				'isError' => true,
				'error' => 'plan_required',
				'message' => 'This write needs a plan a person approved. Nothing was written.' . $planNote,
				'planId' => ($submitted['planId'] ?? null),
			];
		}

		return $this->planService->authorizeStep(
			planId: $planId,
			toolId: $toolId,
			arguments: AgentPlanService::withoutPlanId(arguments: $arguments),
			userId: $userId
		);
	}//end planRefusal()

	/**
	 * Run the handler for a tool.
	 *
	 * @param string $toolId Fully qualified tool id.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function dispatch(string $toolId, array $arguments): array {
		// Handler class names are referenced as strings to keep the coupling
		// count of this dispatcher class within the PHPMD threshold.
		$handlerMap = [
			'buildiq.listApps' => 'OCA\Buildiq\Mcp\Handler\ListAppsHandler',
			'buildiq.getAppManifest' => 'OCA\Buildiq\Mcp\Handler\GetAppManifestHandler',
			'buildiq.createApp' => 'OCA\Buildiq\Mcp\Handler\CreateAppHandler',
			'buildiq.promoteVersion' => 'OCA\Buildiq\Mcp\Handler\PromoteVersionHandler',
			'buildiq.upsertSchema' => 'OCA\Buildiq\Mcp\Handler\UpsertSchemaHandler',
			'buildiq.upsertPage' => 'OCA\Buildiq\Mcp\Handler\UpsertPageHandler',
			'buildiq.addWidget' => 'OCA\Buildiq\Mcp\Handler\AddWidgetHandler',
			'buildiq.upsertMenuItem' => 'OCA\Buildiq\Mcp\Handler\UpsertMenuItemHandler',
		];

		if (isset($handlerMap[$toolId]) === true) {
			return $this->makeHandler(class: $handlerMap[$toolId])->handle($arguments);
		}

		return [
			'isError' => true,
			'error' => 'unknown_tool',
			'message' => "Unknown tool id '{$toolId}'. Available tools: "
				. implode(separator: ', ', array: array_column(array: self::TOOL_DESCRIPTORS, column_key: 'id')) . '.',
		];

	}//end dispatch()

	/**
	 * Instantiate a handler class, injecting the shared collaborators.
	 *
	 * @param class-string $class Fully qualified handler class name.
	 *
	 * @return \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
	 */
	private function makeHandler(string $class): \OCA\Buildiq\Mcp\Handler\AbstractToolHandler {
		return new $class(
			$this->userSession,
			$this->container,
			$this->logger,
			$this->groupManager,
			// AbstractToolHandler takes the object service as parameter #5.
			// Omitting it here shifted $permissionResolver onto it, and that
			// argument is nullable -- so every handler was constructed with null
			// where the service belongs.
			$this->objectService,
			$this->permissionResolver,
			$this->auditTrailMapper,
		);

	}//end makeHandler()
}//end class
