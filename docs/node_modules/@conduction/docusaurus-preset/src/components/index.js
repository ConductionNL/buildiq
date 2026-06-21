/**
 * @conduction/docusaurus-preset/components
 *
 * Public React components for use in MDX, JSX pages, and theme
 * swizzles. Each component mirrors a section in the design-system
 * kit (preview/components/<name>.html) so the visual is the source
 * of truth and the React code follows.
 *
 * Usage in MDX:
 *
 *   import { Hero, StatsStrip, CtaBanner } from '@conduction/docusaurus-preset/components';
 *
 *   <Hero
 *     eyebrow="Twelve open-source apps · one Nextcloud"
 *     title="Install your stack."
 *     subtitle="In two minutes."
 *     primaryCta={{label: "Install from Nextcloud app store"}}
 *   />
 *
 *   <StatsStrip stats={[{value: '24', label: 'apps'}, ...]} />
 *
 *   <CtaBanner title="Ready to install?" />
 */

/* Atomic primitives, also exported as their own subpath for direct
   `import {HexBullet} from '@conduction/docusaurus-preset/components/primitives';`
   imports if a site only needs the atoms. */
export * from './primitives';

export {default as Hero} from './Hero/Hero.jsx';
export {default as StatsStrip} from './StatsStrip/StatsStrip.jsx';
export {default as CtaBanner} from './CtaBanner/CtaBanner.jsx';
export {default as DownloadPanel} from './DownloadPanel/DownloadPanel.jsx';
export {default as PlatformOverview} from './PlatformOverview/PlatformOverview.jsx';
export {default as AppsPreview, AppCard} from './AppsPreview/AppsPreview.jsx';
export {default as AppGlyph, APP_GLYPH_SLUGS, hasAppGlyph} from './AppGlyph/AppGlyph.jsx';

/* Card-family components (Batch 2). Each pairs with a *Grid sibling
   that handles the surrounding layout, so callers can drop a row
   of cards in MDX with a single import. */
export {default as SolutionCard, SolutionGrid} from './SolutionCard/SolutionCard.jsx';
export {default as PartnerCard, PartnerGrid, BecomePartner} from './PartnerCard/PartnerCard.jsx';
export {default as PartnerDirectory} from './PartnerDirectory/PartnerDirectory.jsx';
export {default as PartnerSidecard} from './PartnerSidecard/PartnerSidecard.jsx';
export {default as PartnersFor} from './PartnersFor/PartnersFor.jsx';
export {default as ManagedCommonGround} from './ManagedCommonGround/ManagedCommonGround.jsx';
export {default as Clients, DEFAULT_CLIENTS, DEFAULT_PARTNERS} from './Clients/Clients.jsx';
export {default as ReferenceCard, ReferenceGrid} from './ReferenceCard/ReferenceCard.jsx';
export {default as PairCard, PairRow} from './PairCard/PairCard.jsx';
export {default as FeatureList, FeatureItem} from './FeatureList/FeatureList.jsx';
export {default as HowSteps, HowStep} from './HowSteps/HowSteps.jsx';
export {default as SetupSteps, SetupStep} from './SetupSteps/SetupSteps.jsx';
export {default as FAQ, FAQItem} from './FAQ/FAQ.jsx';
export {default as EmployeeCard, TeamGrid} from './EmployeeCard/EmployeeCard.jsx';
export {default as DetailHero} from './DetailHero/DetailHero.jsx';
export {default as ConductionBg} from './ConductionBg/ConductionBg.jsx';
export {default as PlatformDiagram} from './PlatformDiagram/PlatformDiagram.jsx';
export {default as HexRain} from './HexRain/HexRain.jsx';
export {default as Pipeline, PipelineStep, IconList} from './Pipeline/Pipeline.jsx';
export {default as FacetedFilters, FilterChip} from './FacetedFilters/FacetedFilters.jsx';
export {default as CookieCli} from './CookieCli/CookieCli.jsx';
export {default as GameModal} from './GameModal/GameModal.jsx';

/* Diagram-set web-component React wrappers (cn-hex, cn-platform,
   cn-domain-tree, cn-pipeline, cn-side-box, cn-honeycomb-bg, cn-pair,
   cn-arch-flow). Type-checked, autocompletable React surface for the
   framework-agnostic diagram set in @conduction/diagrams. Brand is
   flat-hex only; the 3D prism wrapper was removed in v3.0.0. */
export {Hex, Platform, DomainTree, DiagramPipeline, SideBox, HoneycombBg, Pair, ArchFlow} from './Diagrams/Diagrams.jsx';

/* LeafCard — metadata header for an Open Register leaf integration.
   <LeafGrid> renders a responsive grid of LeafCards for the overview
   page; <LeafCard> on its own anchors the top of a per-leaf docs
   page. See docs/Integrations/leaf-system.md for the worked use. */
export {default as LeafCard, LeafGrid} from './LeafCard/LeafCard.jsx';
export {default as ComposeBlock} from './ComposeBlock/ComposeBlock.jsx';
export {default as AppsGrid} from './AppsGrid/AppsGrid.jsx';
export {default as AppMock} from './AppMock/AppMock.jsx';
export {default as WidgetMock} from './WidgetMock/WidgetMock.jsx';
export {default as SidebarMock} from './SidebarMock/SidebarMock.jsx';
export {default as MockScene} from './MockScene/MockScene.jsx';
export {default as IntegrationIcon} from './IntegrationIcon/IntegrationIcon.jsx';
export {default as AtomZones} from './AtomZones/AtomZones.jsx';
export {default as HexNetwork} from './HexNetwork/HexNetwork.jsx';
export {default as Showcase} from './Showcase/Showcase.jsx';
export {default as RotatingCards} from './RotatingCards/RotatingCards.jsx';
export {default as HexBackground} from './HexBackground/HexBackground.jsx';
export {default as AgentTrace} from './AgentTrace/AgentTrace.jsx';
export {default as McpToolShelf} from './McpToolShelf/McpToolShelf.jsx';
export {default as ExternalAppShelf} from './ExternalAppShelf/ExternalAppShelf.jsx';
export {default as WidgetShelf} from './WidgetShelf/WidgetShelf.jsx';
export {default as FeatureGrid, FeatureGridGroup, FeatureItem as FeatureGridItem} from './FeatureGrid/FeatureGrid.jsx';
export {default as FeaturesPage} from './FeaturesPage/FeaturesPage.jsx';

/* Academy components (Batch 4). Card-and-chrome patterns for
   academy.conduction.nl: a single feed of blogs, guides, case studies,
   webinars, and tutorials. Content is MDX with a `contentType:`
   frontmatter. The taxonomy lives in ContentTypeFilter/contentTypes.js
   and is mirrored as JSON Schema in /schemas/academy/content.schema.json. */
export {default as ContentCard, ContentCardGrid} from './ContentCard/ContentCard.jsx';
export {default as FeaturedCard} from './FeaturedCard/FeaturedCard.jsx';
export {
  default as ContentTypeFilter,
  CONTENT_TYPES,
  CONTENT_TYPE_PLURAL_LABELS,
} from './ContentTypeFilter/ContentTypeFilter.jsx';
export {
  CONTENT_TYPE_LABELS,
  CONTENT_TYPE_BULLET_COLOR,
} from './ContentTypeFilter/contentTypes.js';
export {default as NewsletterCta} from './NewsletterCta/NewsletterCta.jsx';
export {default as RelatedPosts} from './RelatedPosts/RelatedPosts.jsx';
export {default as ContentDetailHero} from './ContentDetailHero/ContentDetailHero.jsx';
export {default as AppCrossLinks} from './AppCrossLinks/AppCrossLinks.jsx';
export {APPS_REGISTRY, APP_SLUGS, APP_LABELS, getApp, getApps} from '../data/apps-registry';

/* Academy modules — composite-card and per-module index page that
   group a multi-part academy series (e.g. deskdesk-tutorial,
   openwoo-getting-started) into one entity. ModuleCard renders on
   the academy landing in place of the individual member cards;
   ModulePage wraps /academy/modules/{slug}.mdx index pages. Both
   read frontmatter from the consuming site's `academy-modules`
   plugin via usePluginData('academy-modules'). */
export {default as ModuleCard} from './ModuleCard/ModuleCard.jsx';
export {default as ModulePage} from './ModulePage/ModulePage.jsx';
export {
  AUDIENCES,
  AUDIENCE_LABELS,
  AUDIENCE_PLURAL_LABELS,
  AUDIENCE_BULLET_COLOR,
  AUDIENCE_SHORT_LABELS,
} from '../data/audience';

/* Tutorial-body components. Drop-in replacements for the ad-hoc
   "What you need", "Troubleshooting", and "Next steps" h2 + bullet
   patterns that academy tutorials kept duplicating. Designed for use
   inside an MDX academy post body. */
export {default as HexCard} from './HexCard/HexCard.jsx';
export {default as PullQuote} from './PullQuote/PullQuote.jsx';
export {default as Outcomes, Outcome} from './Outcomes/Outcomes.jsx';
export {default as Prerequisites, PrerequisiteItem} from './Prerequisites/Prerequisites.jsx';
export {default as Troubleshooting, TroubleshootingItem} from './Troubleshooting/Troubleshooting.jsx';
export {default as NextSteps, NextStep} from './NextSteps/NextSteps.jsx';
export {default as ContactCta} from './ContactCta/ContactCta.jsx';
