<?php

/**
 * PHPStan scan stub for OpenRegister's AppHost Settings engine (ADR-040).
 *
 * Analysis-only — referenced from phpstan.neon `scanFiles` and NEVER loaded at
 * runtime (the runtime stubs live in tests/stubs/openregister-stubs.php, guarded
 * by class_exists). Lets SettingsSection and AdminSettings resolve the OR base
 * classes they extend when the openregister sibling app is absent from the
 * analysis path.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\AppHost\Settings;

use OCP\IURLGenerator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialState;
use OCP\Settings\IDelegatedSettings;
use OCP\Settings\IIconSection;
use OCP\Settings\ISettings;

/**
 * PHPStan-only stub for the AppHost admin settings section base class.
 *
 * The real class lives in the openregister sibling app (ADR-040).
 */
class GenericSettingsSection implements IIconSection
{
    /**
     * Construct a settings section.
     *
     * @param string       $sectionId    The section id.
     * @param string       $name         The display name.
     * @param string       $appId        The app id.
     * @param string       $iconFile     The icon file name.
     * @param int          $priority     The display priority.
     * @param IURLGenerator $urlGenerator The URL generator.
     */
    public function __construct(
        protected string $sectionId,
        protected string $name,
        protected string $appId,
        protected string $iconFile,
        protected int $priority,
        protected IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Return the section id.
     *
     * @return string
     */
    public function getID(): string
    {
        return $this->sectionId;
    }//end getID()

    /**
     * Return the display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }//end getName()

    /**
     * Return the display priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }//end getPriority()

    /**
     * Return the absolute URL to the section icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath($this->appId, $this->iconFile);
    }//end getIcon()
}//end class

/**
 * PHPStan-only stub for the AppHost admin settings panel base class.
 *
 * The real class lives in the openregister sibling app (ADR-040).
 *
 * ⚠️ It implements `IDelegatedSettings`, NOT `ISettings` — this stub said
 * `ISettings`, and a stub that understates the real contract fails static
 * analysis for code that is correct at runtime. Concretely:
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` takes a
 * `class-string<IDelegatedSettings>`, and against the old stub phpstan could
 * only see "string given". Keep this in step with
 * `OCA\OpenRegister\AppHost\Settings\GenericAdminSettings`.
 */
class GenericAdminSettings implements IDelegatedSettings
{
    /**
     * Construct an admin settings panel.
     *
     * @param string        $appId        The app id.
     * @param string        $sectionId    The section id.
     * @param int           $priority     The display priority.
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState The initial state service.
     * @param IAppConfig    $appConfig    The app config service.
     */
    public function __construct(
        protected string $appId,
        protected string $sectionId,
        protected int $priority,
        protected IAppManager $appManager,
        protected IInitialState $initialState,
        protected IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Return the settings form template response.
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(appName: $this->appId, templateName: 'settings-admin');
    }//end getForm()

    /**
     * Return the section id.
     *
     * @return string
     */
    public function getSection(): string
    {
        return $this->sectionId;
    }//end getSection()

    /**
     * Return the display priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }//end getPriority()

    /**
     * Display name of the delegated settings block.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return null;
    }//end getName()

    /**
     * App-config keys a delegated admin may write through this panel.
     *
     * @return array<string, list<string>>
     */
    public function getAuthorizedAppConfig(): array
    {
        return [];
    }//end getAuthorizedAppConfig()
}//end class

namespace OCA\OpenRegister\AppHost\Service;

/**
 * ADR-080 store-plane stubs for static analysis.
 *
 * The real classes live in OpenRegister; phpstan/psalm do not have that app on
 * their path, so StoreController's injected dependency would otherwise be an
 * "unknown class". This is the same treatment the OR lifecycle and AppHost
 * settings contracts already get above.
 *
 * A stub is enough precisely BECAUSE the dependency is injected rather than
 * inherited — an `extends` on a cross-app class cannot be stubbed away, it is
 * rejected outright ("extends unknown class", which phpstan will not let you
 * ignore).
 */
final class StoreDescriptor
{
    /**
     * Constructor.
     *
     * @param string                $appId           Owning app id.
     * @param string                $schema          Remote schema slug.
     * @param string                $defaultRegister Remote register segment.
     * @param array<string, string> $cardFields      Card field => remote property.
     *
     * @return void
     */
    public function __construct(
        public readonly string $appId,
        public readonly string $schema,
        public readonly string $defaultRegister,
        public readonly array $cardFields = [],
    ) {
    }//end __construct()
}//end class

/**
 * Stub of the engine-owned store discovery client (ADR-080).
 */
class GenericStoreService
{
    public const OUTCOME_OK = 'ok';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOME_UNREACHABLE = 'store_unreachable';

    public const OUTCOME_INVALID = 'store_invalid_response';

    /**
     * Whether a registry is configured.
     *
     * @param StoreDescriptor $descriptor Store parameters.
     *
     * @return bool
     */
    public function isConfigured(StoreDescriptor $descriptor): bool
    {
        return false;
    }//end isConfigured()

    /**
     * Search the remote store.
     *
     * @param StoreDescriptor $descriptor Store parameters.
     * @param string|null     $query      Free-text term.
     * @param string|null     $kind       Kind discriminator.
     *
     * @return array{outcome: string, cards: array<int, array<string, mixed>>}
     */
    public function search(StoreDescriptor $descriptor, ?string $query=null, ?string $kind=null): array
    {
        return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'cards' => []];
    }//end search()

    /**
     * Resolve one remote item by slug.
     *
     * @param StoreDescriptor $descriptor Store parameters.
     * @param string          $slug       Item slug.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(StoreDescriptor $descriptor, string $slug): ?array
    {
        return null;
    }//end resolve()
}//end class
