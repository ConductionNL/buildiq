# Component coverage map

Tracks which `preview/components/*.html` patterns ship as React components in
`@conduction/docusaurus-preset`. Started as a gap list during the connext landing
port; now reads as a coverage map. Anything still missing lives in **Open gaps**
at the bottom.

> Last refreshed 2026-05-06 against `src/components/index.js` and the static kit.

## Atomic primitives

Every higher-level component composes from these. Each one was extracted when
its CSS rule appeared in three or more component files.

- **HexBullet** — `primitives/HexBullet.jsx`. Three sizes (sm 8×9, md 12×14, lg 44×50) of the pointy-top clip-path hex. Used in eyebrows, pill bullets, status badges, footer triad.
- **HexThumbnail** — `primitives/HexThumbnail.jsx`. Single pointy-top hex tile holding an icon, photo (clip-path masked), or illustration. Four sizes (sm 56×64, md 88×100, lg 160×184, xl 240×280), six tones. Used by every academy card and hero.
- **AuthorByline** — `primitives/AuthorByline.jsx`. Avatar (initials or photo) + name + bullet separator + formatted date. Three tones (default, on-dark, muted). Used on every academy card and detail page.
- **Eyebrow** — `primitives/Eyebrow.jsx`. Uppercase Plex Mono caption with optional leading HexBullet.
- **Section** — `primitives/Section.jsx`. The 1280px-max wrapper. Background variants (default, tinted, inverse), spacing variants (default, tight, flush).
- **SectionHead** — `primitives/SectionHead.jsx`. Eyebrow + h2 + lede. Two layouts (split 1.1fr/1fr; stack centred).
- **Card** — `primitives/Card.jsx`. White / cobalt-100 / radius-lg. Tones: surface (default), ghost (dashed), inverse (cobalt-900).
- **Pill** — `primitives/Pill.jsx`. Mono-caps chip with optional bullet. Status badges, app tags, sector labels.
- **Button** — `primitives/Button.jsx`. Primary / secondary / ghost + on-dark variants for cobalt CTA panels.
- **NextBlue / CgYellow / KnvbOrange / CommonGroundPlus** — `primitives/BrandCitation.jsx`. Type-checked wrappers around the global `next-blue` / `cg-yellow` / `knvb-orange` / `commonground-plus` CSS classes. Replaces ad-hoc `<span className="next-blue">Nextcloud</span>` with `<NextBlue>Nextcloud</NextBlue>`.

## Composite components

Most consume one or more primitives.

- **Hero** — `Hero/Hero.jsx`. Two-column landing hero. Composes Eyebrow + Button.
- **StatsStrip** — `StatsStrip/StatsStrip.jsx`. Four-up cobalt-50 stats band.
- **CtaBanner** — `CtaBanner/CtaBanner.jsx`. Cobalt CTA panel. Composes ConductionBg + on-dark Button.
- **PlatformOverview** — `PlatformOverview/PlatformOverview.jsx`. Section-head + diagram wrapper.
- **PlatformDiagram** — `PlatformDiagram/PlatformDiagram.jsx`. Typed wrapper around the bespoke `<platform-diagram>` web component. Pass workspace + lists + flows as data. Reveals on scroll via `--pd-list-progress` / `--pd-hex-progress` CSS variables.
- **AppsPreview / AppCard** — `AppsPreview/AppsPreview.jsx`. Static 3-up apps grid. Composes SectionHead + HexBullet.
- **AppsGrid** — `AppsGrid/AppsGrid.jsx`. Filterable apps catalogue with category chips. Reuses AppCard.
- **SolutionCard / SolutionGrid** — `SolutionCard/SolutionCard.jsx`. Sector-tinted solution cards. Composes Pill.
- **PartnerCard / PartnerGrid / BecomePartner** — `PartnerCard/PartnerCard.jsx`. Three tiers (partner, certified, strategic). Plus `variant="other"` for the compact mini-avatar card used on partner-detail pages.
- **PartnerDirectory** — `PartnerDirectory/PartnerDirectory.jsx`. Self-contained faceted directory used on `/partners` and embedded on the homepage. Internally derives facets from the partner data; pair with a `becomePartner` object for the trailing CTA card. No dedicated static-kit specimen — composed from PartnerCard + FacetedFilters.
- **PartnerSidecard** — `PartnerSidecard/PartnerSidecard.jsx`. Sticky right rail used inside partner-detail pages. Holds the tier credential, "apps they ship", and "solutions they deliver". Bleeds into the Specialties section without wrapping the page.
- **ManagedCommonGround** — `ManagedCommonGround/ManagedCommonGround.jsx`. Yellow-themed managed-stack panel used on `/commonground` and `/support`. Component-list + price line + dual CTA. No dedicated static-kit specimen.
- **Clients** (+ `DEFAULT_CLIENTS`, `DEFAULT_PARTNERS`) — `Clients/Clients.jsx`. Three-row right-to-left honeycomb marquee with grayscale-to-colour hover, lane-pause-on-hover, and `prefers-reduced-motion` respect. Specimen: `preview/components/clients-marquee.html`.
- **ReferenceCard / ReferenceGrid** — `ReferenceCard/ReferenceCard.jsx`. Customer-reference cards on partner-detail pages.
- **PairCard / PairRow** — `PairCard/PairCard.jsx`. Compact "related item" cards on app- and solution-detail pages.
- **EmployeeCard / TeamGrid** — `EmployeeCard/EmployeeCard.jsx`. Three variants (compact, photo, detail) for the /about team grid.
- **FeatureList / FeatureItem** — `FeatureList/FeatureItem.jsx`. Single-column USPs with hex glyphs. Use `items={…}` array OR `<FeatureItem>` children, not both.
- **FeatureGrid / FeatureGridItem / FeatureGridGroup** — `FeatureGrid/FeatureGrid.jsx`. Compact spec list with hover-tooltip and stable/beta/soon status hexes.
- **HowSteps / HowStep** — `HowSteps/HowSteps.jsx`. Numbered process-step row. Auto-numbers and auto-tints (cobalt → orange → cobalt-700) when `number` and `tier` are omitted.
- **Pipeline / PipelineStep / IconList** — `Pipeline/Pipeline.jsx`. Horizontal hex-numbered pipeline with chevron flow lines and optional source/consumer end-boxes.
- **DetailHero** — `DetailHero/DetailHero.jsx`. Shared 1fr/360px hero pattern between app/solution/partner detail pages. Crumb + status + version + locales + downloads badge + h1 + tagline + 3 CTAs + right-column illustration.
- **FacetedFilters / FilterChip** — `FacetedFilters/FacetedFilters.jsx`. Sidebar facet checkbox panel + active-chip strip.
- **ConductionBg** — `ConductionBg/ConductionBg.jsx`. Parallax honeycomb background. Real component with self-contained runtime hook.
- **HexNetwork** — `HexNetwork/HexNetwork.jsx`. Centre hex with surrounding satellites. Used on `/support` to render the partner network around Conduction.
- **HexBackground** — `HexBackground/HexBackground.jsx`. Hex pattern fill primitive.
- **HexRain** — `HexRain/HexRain.jsx`. The "twelve apps" mini-game in the hero column. Lazy-loads the runtime via Head.
- **GameModal** — `GameModal/GameModal.jsx`. End-of-game dialog with cross-game progress tracking. Subscribes to `connext:gameend` events.
- **CookieCli** — `CookieCli/CookieCli.jsx`. Terminal-styled cookie consent banner with localStorage persistence.
- **ComposeBlock** — `ComposeBlock/ComposeBlock.jsx`. Branded code block (cobalt-900 + Plex Mono + filename pill + copy button). For docker-compose, bash recipes, and any verbatim copy-paste content. Used on `/demo`.
- **AgentTrace** — `AgentTrace/AgentTrace.jsx`. Terminal-styled agent execution trace with cursor + mode chrome. Specimen: `preview/components/agent-trace.html`.
- **AppMock** — `AppMock/AppMock.jsx`. Selector that renders one of 16 app-specific UI mockups (DeciDesk, DocuDesk, LarpingApp, MyDash + variants, NLDesign, OpenCatalogi, OpenConnector, OpenRegister, OpenWoo, PipelinQ, Procest, SoftwareCatalog, ZaakAfhandelApp). Each variant lives under `AppMock/variants/`. Specimen: `preview/components/app-mock.html`.
- **ExternalAppShelf** — `ExternalAppShelf/ExternalAppShelf.jsx`. Third-party-app shelf (Outlook-style, Mattermost, Keycloak, etc.) used on `/connext`. No dedicated specimen.
- **WidgetShelf** — `WidgetShelf/WidgetShelf.jsx`. Widget showcase grid with eyebrow + title + lede + N-column widget cards. No dedicated specimen.
- **Showcase** — `Showcase/Showcase.jsx`. Multi-item collapsible showcase with screenshot + body. Used on `/commonground` for the "5-lagen model". Specimen: `preview/components/showcase.html`.
- **RotatingCards** — `RotatingCards/RotatingCards.jsx`. Rotating card carousel. Specimen: `preview/components/rotating-cards.html`.
- **FAQ / FAQItem** — `FAQ/FAQ.jsx`. Disclosure-pattern FAQ block. Used on `/support`. No dedicated specimen.

## Academy components

Card-and-chrome patterns for academy.conduction.nl. One feed of blogs, guides,
case studies, webinars, and tutorials, distinguished by a `contentType:`
frontmatter. Specimen: `preview/components/academy.html`.

- **ContentCard / ContentCardGrid** — `ContentCard/ContentCard.jsx`. The "Keep learning…" card: hex-thumb panel on the left, author byline + title + summary + tag pills on the right. Three column counts, drops to single-column under 700px. Composes HexThumbnail, AuthorByline, Pill.
- **FeaturedCard** — `FeaturedCard/FeaturedCard.jsx`. Cobalt-900 hero spot with body copy left and a large pointy-top hex flanked by two satellite hexes right. The single KNVB orange satellite is the page's one orange accent (override with `accent="cobalt"` if a screen already burns its orange budget).
- **ContentTypeFilter** — `ContentTypeFilter/ContentTypeFilter.jsx`. Top-of-page chip row driven by `?type=`. Controlled mode (`value` + `onChange`) or uncontrolled link mode (`hrefForType`). The taxonomy lives in `ContentTypeFilter/contentTypes.js` so chips, card pills, and detail-hero pills all draw from one list.
- **NewsletterCta** — `NewsletterCta/NewsletterCta.jsx`. Cobalt-50 (or inverse cobalt-900) panel with title, lede, email input, submit, fineprint. Form is unwired by default; pass `action` or `onSubmit` to connect it.
- **RelatedPosts** — `RelatedPosts/RelatedPosts.jsx`. Bottom-of-detail-page block with a "Keep learning…" heading, a "View all" pill on the right, and a content-card grid below. Accepts `items` for data-driven render or children for hand-composed.
- **ContentDetailHero** — `ContentDetailHero/ContentDetailHero.jsx`. Header for individual posts. Crumb, content-type chip + tags, h1, summary, author byline, optional duration, then a 16:9 cover region. Distinct from `<DetailHero/>` which is for app/solution/partner pages with a 360px right-side hex.

## Diagram-set web-component wrappers

Thin React wrappers around the framework-agnostic web components in
`diagrams/`. Lazy-import the runtime, type-checked observed-attribute props,
slot-based children pass through. All exported from `Diagrams/Diagrams.jsx`.

- **Hex** wraps `<cn-hex>` (color, size, variant, layout, interactive)
- **Platform** wraps `<cn-platform>` (ground)
- **DomainTree** wraps `<cn-domain-tree>`
- **DiagramPipeline** wraps `<cn-pipeline>` (renamed from Pipeline to avoid the components/Pipeline name collision)
- **SideBox** wraps `<cn-side-box>`
- **HoneycombBg** wraps `<cn-honeycomb-bg>`
- **Pair** wraps `<cn-pair>` (leftLabel, leftCaption, leftColor, rightLabel, rightCaption, rightColor, bridgeLabel, arrow). Two systems linked by an orange arrow; the integration-page headline diagram. Owns the one-orange-per-scene budget. Extracted 2026-05-13 from the `.pair-banner` pattern in `preview/product-pages/integrations.html`.
- **ArchFlow** wraps `<cn-arch-flow>` (arrow: right | down | none). One row of a request-flow / architecture diagram. Child elements style themselves by attribute (`accent` solid cobalt, `hex` orange pointy-top, `muted` half-opacity). Stack multiple rows for a multi-layer system view. Extracted 2026-05-13 from the `.arch-diagram` pattern in `preview/product-pages/technical-docs.html`.

## Theme swizzles

- **Navbar** — `theme/Navbar/index.jsx`. Replaces Docusaurus's default Infima navbar. Items come from `themeConfig.navbar`. Switches the wordmark for the ConNext and Common Ground sub-brands via `theme/brand.jsx`.
- **Footer canal scene** — `theme/Footer/index.jsx`. Full canal scene (Amsterdam trapgevel skyline, kade with bikes/cars, cobalt canal with orange boats, brand block + link grid riding on the water). Templates injected via `dangerouslySetInnerHTML` so the runtime can `.content`-clone them. Re-hydrates on SPA route changes.
- **MDXPage** — `theme/MDXPage/index.jsx`. Drops the Docusaurus default `col col--8 col--offset-2` wrapper for pages with `hide_table_of_contents: true`, so marketing surfaces render full-width. Adds the `marketing-page` class on `<main>` so `brand.css` can zero-out stray top margins (no gap between navbar and hero).

## Open gaps

Three earlier gaps (PartnerCard.OtherCard variant, diagram-set web component
wrappers, brand-citation utilities) closed in April–May 2026 and are folded
into the lists above.

- **Locale switcher integration on the kit's static `landing.html`** — the top-navbar.html ships an NL/EN switcher (querystring driven), but the kit's individual pages don't yet have a translation dictionary so the switch is cosmetic on those pages. The connext site already has full Docusaurus i18n; the kit is one round of NL copy away from full parity.
- **Static specimen pages for composed components** — `ManagedCommonGround`, `PartnerDirectory`, `WidgetShelf`, `ExternalAppShelf`, `FAQ` ship as React but have no `preview/components/*.html` reference. They're composed from primitives that already have specimens, so the absence is reasonable, but a designer reviewing the kit can't see them outside the live site.
- **PartnerCard `logoAlt` prop** — accepted but never passed by any current MDX usage. Either drop or document an a11y fallback expectation.
- **PartnerDirectory `solutions` field** — included in `partners-catalog.js` per partner, never consumed by `<PartnerCard>` (harmless spread). Decide whether to surface (e.g. inside the card) or remove from the data.
