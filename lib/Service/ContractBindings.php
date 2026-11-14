<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\AppChannelApplierInterface;
use OCA\Buildiq\Kernel\Contracts\AppHealthServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationCreationServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationDeletionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationInsightsServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AppNavigationServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AppOverrideServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AppRepoParserInterface;
use OCA\Buildiq\Kernel\Contracts\AppVisibilityResolverInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationWriteServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ConditionActionExecutorInterface;
use OCA\Buildiq\Kernel\Contracts\ConnectionReporterInterface;
use OCA\Buildiq\Kernel\Contracts\CopilotServiceInterface;
use OCA\Buildiq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Buildiq\Kernel\Contracts\DocumentGenerationServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ExportAppContentBundlerInterface;
use OCA\Buildiq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ExportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\FormLiveBindingIndexInterface;
use OCA\Buildiq\Kernel\Contracts\FormLiveValuesRecomputerInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubAppSyncServiceInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubCatalogServiceInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubFormCatalogServiceInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubPushServiceInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubShopSearchInterface;
use OCA\Buildiq\Kernel\Contracts\IconServiceInterface;
use OCA\Buildiq\Kernel\Contracts\LayoutDeltaServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ListenerSlugContractInterface;
use OCA\Buildiq\Kernel\Contracts\ManifestFormDestinationsInterface;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Kernel\Contracts\PageLayoutAuthoringServiceInterface;
use OCA\Buildiq\Kernel\Contracts\PageLayoutFrozenBaseInterface;
use OCA\Buildiq\Kernel\Contracts\PageLayoutLayerStackInterface;
use OCA\Buildiq\Kernel\Contracts\PageLayoutPresenterInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Kernel\Contracts\RegistrationFormAuthoringServiceInterface;
use OCA\Buildiq\Kernel\Contracts\RegistrationFormTargetWarningsInterface;
use OCA\Buildiq\Kernel\Contracts\RuleActionDispatcherInterface;
use OCA\Buildiq\Kernel\Contracts\RuleEngineServiceInterface;
use OCA\Buildiq\Kernel\Contracts\RuleObjectReaderInterface;
use OCA\Buildiq\Kernel\Contracts\ServerBundleWriterInterface;
use OCA\Buildiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Buildiq\Kernel\Contracts\StoredDecisionTablesInterface;
use OCA\Buildiq\Kernel\Contracts\TemplateSeedServiceInterface;
use OCA\Buildiq\Kernel\Contracts\VersionPromotionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\VirtualAppCredentialRegistrarInterface;
use OCA\Buildiq\Kernel\Contracts\WidgetItemProjectorInterface;
use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Buildiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AppChannelApplierInterface::class, AppChannelApplier::class);
		$context->registerServiceAlias(AppHealthServiceInterface::class, AppHealthService::class);
		$context->registerServiceAlias(AutomationRunRecorderInterface::class, AutomationRunRecorder::class);
		$context->registerServiceAlias(AppNavigationServiceInterface::class, AppNavigationService::class);
		$context->registerServiceAlias(AppOverrideServiceInterface::class, AppOverrideService::class);
		$context->registerServiceAlias(AppPackageImportServiceInterface::class, AppPackageImportService::class);
		$context->registerServiceAlias(AppRepoParserInterface::class, AppRepoParser::class);
		$context->registerServiceAlias(AppVisibilityResolverInterface::class, AppVisibilityResolver::class);
		$context->registerServiceAlias(ApplicationCreationServiceInterface::class, ApplicationCreationService::class);
		$context->registerServiceAlias(ApplicationDeletionServiceInterface::class, ApplicationDeletionService::class);
		$context->registerServiceAlias(ApplicationInsightsServiceInterface::class, ApplicationInsightsService::class);
		$context->registerServiceAlias(ApplicationVersionServiceInterface::class, ApplicationVersionService::class);
		$context->registerServiceAlias(AutomationCompilerServiceInterface::class, AutomationCompilerService::class);
		$context->registerServiceAlias(AutomationWriteServiceInterface::class, AutomationWriteService::class);
		$context->registerServiceAlias(ConditionActionExecutorInterface::class, ConditionActionExecutor::class);
		$context->registerServiceAlias(ConnectionReporterInterface::class, ConnectionReporter::class);
		$context->registerServiceAlias(CopilotServiceInterface::class, CopilotService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(DocumentGenerationServiceInterface::class, DocumentGenerationService::class);
		$context->registerServiceAlias(ExportAppContentBundlerInterface::class, ExportAppContentBundler::class);
		$context->registerServiceAlias(ExportJobServiceInterface::class, ExportJobService::class);
		$context->registerServiceAlias(ExportServiceInterface::class, ExportService::class);
		$context->registerServiceAlias(FormLiveBindingIndexInterface::class, FormLiveBindingIndex::class);
		$context->registerServiceAlias(FormLiveValuesRecomputerInterface::class, FormLiveValuesRecomputer::class);
		$context->registerServiceAlias(GitHubAppSyncServiceInterface::class, GitHubAppSyncService::class);
		$context->registerServiceAlias(GitHubCatalogServiceInterface::class, GitHubCatalogService::class);
		$context->registerServiceAlias(GitHubFormCatalogServiceInterface::class, GitHubFormCatalogService::class);
		$context->registerServiceAlias(GitHubPushServiceInterface::class, GitHubPushService::class);
		$context->registerServiceAlias(GitHubShopSearchInterface::class, GitHubShopSearch::class);
		$context->registerServiceAlias(IconServiceInterface::class, IconService::class);
		$context->registerServiceAlias(LayoutDeltaServiceInterface::class, LayoutDeltaService::class);
		$context->registerServiceAlias(ListenerSlugContractInterface::class, ListenerSlugContract::class);
		$context->registerServiceAlias(ManifestFormDestinationsInterface::class, ManifestFormDestinations::class);
		$context->registerServiceAlias(ManifestResolverServiceInterface::class, ManifestResolverService::class);
		$context->registerServiceAlias(ObjectSchemaSlugResolverInterface::class, ObjectSchemaSlugResolver::class);
		$context->registerServiceAlias(PageLayoutAuthoringServiceInterface::class, PageLayoutAuthoringService::class);
		$context->registerServiceAlias(PageLayoutFrozenBaseInterface::class, PageLayoutFrozenBase::class);
		$context->registerServiceAlias(PageLayoutLayerStackInterface::class, PageLayoutLayerStack::class);
		$context->registerServiceAlias(PageLayoutPresenterInterface::class, PageLayoutPresenter::class);
		$context->registerServiceAlias(PermissionResolverInterface::class, PermissionResolver::class);
		$context->registerServiceAlias(RegistrationFormAuthoringServiceInterface::class, RegistrationFormAuthoringService::class);
		$context->registerServiceAlias(RegistrationFormTargetWarningsInterface::class, RegistrationFormTargetWarnings::class);
		$context->registerServiceAlias(RuleActionDispatcherInterface::class, RuleActionDispatcher::class);
		$context->registerServiceAlias(RuleEngineServiceInterface::class, RuleEngineService::class);
		$context->registerServiceAlias(RuleObjectReaderInterface::class, RuleObjectReader::class);
		$context->registerServiceAlias(ServerBundleWriterInterface::class, ServerBundleWriter::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(StoredDecisionTablesInterface::class, StoredDecisionTables::class);
		$context->registerServiceAlias(TemplateSeedServiceInterface::class, TemplateSeedService::class);
		$context->registerServiceAlias(VersionPromotionServiceInterface::class, VersionPromotionService::class);
		$context->registerServiceAlias(VirtualAppCredentialRegistrarInterface::class, VirtualAppCredentialRegistrar::class);
		$context->registerServiceAlias(WidgetItemProjectorInterface::class, WidgetItemProjector::class);
	}//end register()
}//end class
