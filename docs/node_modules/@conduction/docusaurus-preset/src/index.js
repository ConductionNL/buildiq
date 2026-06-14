/**
 * @conduction/docusaurus-preset
 *
 * Brand-default Docusaurus 3 config helper. Sites import createConfig()
 * and override only the parts that differ from the brand baseline.
 *
 *   // docusaurus.config.js
 *   const { createConfig } = require('@conduction/docusaurus-preset');
 *
 *   module.exports = createConfig({
 *     title: 'Conduction',
 *     tagline: 'Open-source apps for the Nextcloud workspace.',
 *     url: 'https://conduction.nl',
 *     baseUrl: '/',
 *   });
 *
 * createConfig() returns a complete Docusaurus config with brand tokens,
 * the four-locale i18n block (nl/en/de/fr, NL default), Plex Mono +
 * Figtree fonts, and Navbar / Footer scaffolding. Sites override any
 * field by passing it in or merging after the call.
 */

const path = require('path');
const fs = require('fs');

/**
 * Resolve the app version that drives the navbar "Stable · v{x.y.z}"
 * pill. Order of precedence:
 *
 *   1. opts.appVersion (explicit override)
 *   2. appinfo/info.xml <version> tag (Nextcloud app convention; every
 *      app in the Conduction fleet has one)
 *   3. package.json version (sites without an info.xml — Hydra,
 *      design-system itself, conduction-website)
 *   4. undefined (pill is hidden by the Navbar swizzle)
 *
 * The resolver runs at config build-time inside `process.cwd()`, which
 * is the consuming site's repo root. Failures are swallowed silently so
 * a missing info.xml never breaks the site build — the pill just hides.
 */
function resolveAppVersion(opts) {
  if (opts.appVersion) return String(opts.appVersion);

  /* Nextcloud apps: appinfo/info.xml carries the canonical version.
     Conduction docs sites live at <appRepo>/docs/ next to the app's
     <appRepo>/appinfo/, so we check both the cwd AND the parent
     before giving up. We avoid pulling in an XML parser for one tag
     — a non-greedy regex against the file content is robust enough
     for the standard `<version>x.y.z</version>` shape the app store
     mandates. */
  const cwd = process.cwd();
  const infoCandidates = [
    path.join(cwd, 'appinfo', 'info.xml'),
    path.join(cwd, '..', 'appinfo', 'info.xml'),
  ];
  for (const infoPath of infoCandidates) {
    try {
      if (fs.existsSync(infoPath)) {
        const xml = fs.readFileSync(infoPath, 'utf8');
        const m = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/);
        if (m && m[1]) return m[1];
      }
    } catch (e) {
      /* fall through */
    }
  }

  /* Non-Nextcloud sites: package.json version. Same parent-walk so
     a site building from <repo>/site/ still finds <repo>/package.json. */
  const pkgCandidates = [
    path.join(cwd, 'package.json'),
    path.join(cwd, '..', 'package.json'),
  ];
  for (const pkgPath of pkgCandidates) {
    try {
      if (fs.existsSync(pkgPath)) {
        const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
        /* Skip the docs-site's own placeholder package.json (every
           Conduction docs site is scaffolded with name="*-docs" and
           version="0.0.0"); fall through to the parent in that case. */
        if (pkg.version && pkg.version !== '0.0.0') return pkg.version;
      }
    } catch (e) {
      /* fall through */
    }
  }

  return undefined;
}

/**
 * Brand-default Organization JSON-LD. One canonical version of the
 * company's legal-entity facts (address, KvK, BTW, socials), shipped on
 * every Conduction site so AI crawlers (GPTBot, ClaudeBot, Perplexity-
 * Bot, OAI-SearchBot, Google AI Overviews) get the same answer to
 * "who is Conduction" regardless of which subdomain they landed on.
 * Updates here propagate to the fleet on the next preset release.
 *
 * Sites that aren't conduction.nl (per-app docs sites at
 * {slug}.conduction.nl, etc.) still reference the same Organization via
 * @id, so cross-site citations consolidate cleanly.
 */
const BRAND_ORGANIZATION_JSONLD = {
  '@context': 'https://schema.org',
  '@type': 'Organization',
  '@id': 'https://www.conduction.nl/#org',
  name: 'Conduction B.V.',
  alternateName: 'Conduction',
  url: 'https://www.conduction.nl/',
  logo: 'https://www.conduction.nl/img/brand/avatar-conduction-gold-on-white.svg',
  foundingDate: '2019',
  description:
    'Dutch open-source software company building EUPL-1.2 apps for the Nextcloud workspace.',
  address: {
    '@type': 'PostalAddress',
    streetAddress: 'Lauriergracht 14h',
    postalCode: '1016 RR',
    addressLocality: 'Amsterdam',
    addressCountry: 'NL',
  },
  email: 'info@conduction.nl',
  telephone: '+31-85-303-6840',
  taxID: 'NL860784241B01',
  vatID: 'NL860784241B01',
  identifier: {
    '@type': 'PropertyValue',
    propertyID: 'KvK',
    value: '76741850',
  },
  sameAs: [
    'https://codeberg.org/Conduction',
    'https://www.linkedin.com/company/conduction/',
  ],
};

/**
 * Build the per-site WebSite JSON-LD that ties the consuming site to
 * the shared Organization. WebSite carries the site title and URL the
 * site was configured with; Organization stays canonical.
 */
function buildWebsiteJsonLd(opts) {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    '@id': `${opts.url}/#website`,
    url: `${opts.url}/`,
    name: opts.title,
    publisher: {'@id': 'https://www.conduction.nl/#org'},
    inLanguage: (opts.i18n && opts.i18n.locales) || ['nl', 'en', 'de', 'fr'],
  };
}

/**
 * Default headTags emitted on every page. Two JSON-LD blocks
 * (Organization + WebSite) consumed by AI crawlers, Google rich
 * results, Bing AI, LinkedIn previews. Static SSG output, so non-JS
 * fetchers (GPTBot, ClaudeBot, PerplexityBot) see them too.
 *
 * Sites extend by passing `opts.headTags = [...]`; the preset merges
 * the site's tags after its own defaults.
 */
function buildAiHeadTags(opts) {
  const tags = [
    {
      tagName: 'script',
      attributes: {type: 'application/ld+json'},
      innerHTML: JSON.stringify(BRAND_ORGANIZATION_JSONLD),
    },
    {
      tagName: 'script',
      attributes: {type: 'application/ld+json'},
      innerHTML: JSON.stringify(buildWebsiteJsonLd(opts)),
    },
  ];

  /* Search Console verification meta tags. Sites pass tokens via
     opts.searchConsoleVerification = { google: '...', bing: '...',
     yandex: '...' }; each present token becomes a meta tag. Verifying
     via meta (vs DNS TXT) lets a non-DNS-admin teammate access Search
     Console / Bing Webmaster Tools. */
  const verification = opts.searchConsoleVerification || {};
  if (verification.google) {
    tags.push({
      tagName: 'meta',
      attributes: {name: 'google-site-verification', content: verification.google},
    });
  }
  if (verification.bing) {
    tags.push({
      tagName: 'meta',
      attributes: {name: 'msvalidate.01', content: verification.bing},
    });
  }
  if (verification.yandex) {
    tags.push({
      tagName: 'meta',
      attributes: {name: 'yandex-verification', content: verification.yandex},
    });
  }
  if (verification.facebook) {
    tags.push({
      tagName: 'meta',
      attributes: {name: 'facebook-domain-verification', content: verification.facebook},
    });
  }
  if (verification.pinterest) {
    tags.push({
      tagName: 'meta',
      attributes: {name: 'p:domain_verify', content: verification.pinterest},
    });
  }

  return tags;
}

/**
 * Default sitemap plugin options. Each locale outputs its own
 * sitemap.xml. /academy/tags/** is excluded site-wide because tag
 * pages are thin and confuse AI summarisers more than they help SEO.
 * ignorePatterns matches the *route path* after locale prefixing, so
 * we list every locale variant.
 *
 * Sites passing their own classic preset config can override by
 * including a `sitemap` key alongside `docs`/`blog`/`theme`.
 */
/**
 * Sitemap defaults. Google ignores `changefreq` and `priority` (and has
 * for years; the @docusaurus/plugin-sitemap defaults are wrong on this
 * point). `lastmod` is the only signal Google actually uses, and only
 * if the dates are accurate, so we ship lastmod from file mtime. Bing
 * still reads all three, harmless to omit.
 *
 * Sites with locale-specific tag pages and pagination should keep the
 * exclude list in sync. Pagination (`/page/N/`) and tag pages
 * (`/tags/{slug}/`) are documented Docusaurus duplicate-content traps;
 * we exclude them by default so they neither dilute crawl budget nor
 * confuse AI summarisers. (Do not write `/tags/*` followed by a slash
 * in this comment: the literal asterisk-slash sequence would close
 * the JSDoc block and break preset parsing for every consuming site.)
 */
const DEFAULT_SITEMAP_OPTIONS = {
  changefreq: null,
  priority: null,
  lastmod: 'date',
  ignorePatterns: [
    '/academy/tags/**',
    '/nl/academy/tags/**',
    '/en/academy/tags/**',
    '/de/academy/tags/**',
    '/fr/academy/tags/**',
    '/page/**',
    '/nl/page/**',
    '/en/page/**',
    '/de/page/**',
    '/fr/page/**',
  ],
  filename: 'sitemap.xml',
};

/**
 * Default themeConfig.metadata. Twitter + og:type baselines so social
 * cards render correctly. Per-page MDX frontmatter still wins (Helmet
 * de-dupes by meta name/property). Sites override the whole array by
 * passing `themeConfig.metadata = [...]` in opts.
 */
const DEFAULT_METADATA = [
  {name: 'twitter:site', content: '@ConductionNL'},
  {name: 'twitter:card', content: 'summary_large_image'},
  {property: 'og:type', content: 'website'},
];

/**
 * Default OG image. 1200x630 cobalt brand card shipped from the preset
 * static/img/. Sites can override by dropping their own
 * static/img/og-conduction.png (staticDirectories precedence puts the
 * site's file last). For per-app product cards, set themeConfig.image
 * explicitly in your site config.
 */
const DEFAULT_OG_IMAGE = 'img/og-conduction.png';

/**
 * Brand-default i18n block. Nederlands at the URL root, others at /en/, /de/, /fr/.
 */
const I18N = {
  defaultLocale: 'nl',
  locales: ['nl', 'en', 'de', 'fr'],
  localeConfigs: {
    nl: { label: 'Nederlands', htmlLang: 'nl-NL', direction: 'ltr' },
    en: { label: 'English',    htmlLang: 'en-GB', direction: 'ltr' },
    de: { label: 'Deutsch',    htmlLang: 'de-DE', direction: 'ltr' },
    fr: { label: 'Français',   htmlLang: 'fr-FR', direction: 'ltr' },
  },
};

/**
 * Wrap a user-supplied `opts.presets` array so the classic preset's
 * `sitemap` option inherits brand defaults (lastmod, ignorePatterns)
 * when the site hasn't set them explicitly. Before this helper, sites
 * that passed their own presets array silently lost the preset's
 * DEFAULT_SITEMAP_OPTIONS, so the fleet sitemaps never shipped
 * <lastmod> tags despite the preset claiming to add them. See
 * MEMORY.md project_preset-4.0-wrap-user-presets for the back story.
 *
 * Recognises both 'classic' and '@docusaurus/preset-classic' entries.
 * Leaves non-classic presets untouched. Explicit user values win:
 * `sitemap: null` opts out, `sitemap: { lastmod: false }` opts out
 * of lastmod specifically, and so on.
 */
function wrapClassicPresetDefaults(userPresets) {
  return userPresets.map(entry => {
    if (!Array.isArray(entry)) return entry;
    const [name, config] = entry;
    const isClassic =
      name === 'classic' || name === '@docusaurus/preset-classic';
    if (!isClassic || typeof config !== 'object' || config === null) return entry;
    if (config.sitemap === null) return entry; /* explicit opt-out */
    return [
      name,
      {
        ...config,
        sitemap: {
          ...DEFAULT_SITEMAP_OPTIONS,
          ...(config.sitemap || {}),
        },
      },
    ];
  });
}

/**
 * Brand-default navbar. Sites pass their own items[] and logo; the chrome
 * styling (cobalt-on-white, Plex-Mono caption) is locked.
 *
 * The right-side default carries the four chrome items that the brand
 * navbar swizzle (theme/Navbar/index.jsx) renders as icons + pill:
 *
 *   versionPill   "Stable · v{x.y.z}" reading customFields.appVersion;
 *                 hidden when no version is available, so non-Nextcloud
 *                 sites get a clean navbar.
 *   apiDocs       Book icon + "API Documentation", pointing at /api
 *                 (Redocusaurus convention). Sites without an OpenAPI
 *                 spec remove this item from their own config.
 *   github        GitHub mark, opens the org GitHub by default.
 *   localeDropdown  Existing locale switcher (nl/en/de/fr).
 *
 * The `opts.repoUrl` plumbing below overrides the GitHub item's href
 * to point at the specific app repo when the site provides it.
 */
const baseNavbar = (siteName, repoUrl) => ({
  title: siteName,
  logo: {
    alt: `${siteName} avatar`,
    src: 'img/logo.svg',
    srcDark: 'img/logo-dark.svg',
  },
  /* `custom-*` prefix is the Docusaurus convention for theme-defined
     navbar item types — items prefixed this way bypass the strict Joi
     schema validator in @docusaurus/theme-classic, so the brand Navbar
     swizzle can dispatch on them without registering each shape with
     core. The swizzle accepts both `custom-github` and the bare
     `github` (etc.) for forward-compat. */
  items: [
    { type: 'custom-versionPill', position: 'right' },
    { type: 'custom-apiDocs', position: 'right' },
    { type: 'custom-github', href: repoUrl || 'https://codeberg.org/Conduction', position: 'right' },
    { type: 'localeDropdown', position: 'right' },
  ],
});

/**
 * Brand-default footer columns (the "links" array).
 *
 * Three corporate columns rendered on the cobalt-900 footer panel.
 * Sites override these wholesale by passing `footer.links` to
 * createConfig(); a site that wants to keep just one brand column
 * (e.g. only "Conduction" on a product-page footer) can spread the
 * filtered output of this function into their own array. The other
 * footer fields (style, copyright) keep their brand defaults
 * independently.
 */
const baseFooterLinks = () => [
  {
    title: 'Product',
    items: [
      { label: 'ConNext', href: 'https://conduction.nl/connext' },
      { label: 'Common Ground', href: 'https://conduction.nl/commonground' },
      { label: 'App store', href: 'https://apps.conduction.nl/' },
      { label: 'Common Ground hosting', href: 'https://commonground.nu/' },
    ],
  },
  {
    title: 'Conduction',
    items: [
      { label: 'About', href: 'https://conduction.nl/about' },
      { label: 'Open source', href: 'https://conduction.nl/about#opensource' },
      { label: 'Team', href: 'https://conduction.nl/about#team' },
      { label: 'ISO', href: 'https://conduction.nl/iso' },
    ],
  },
  {
    title: 'Documentatie',
    items: [
      { label: 'Brand book', href: 'https://identity.conduction.nl/' },
      { label: 'Diagram set', href: 'https://identity.conduction.nl/diagrams/' },
    ],
  },
];

/**
 * Brand-default footer copyright string. KvK + BTW + IBAN + address are
 * the legal anchor that every Conduction-branded site needs to render
 * regardless of which product-specific columns the footer shows above
 * it. Sites override this only for non-Conduction surfaces (rare); on
 * product pages it inherits unchanged.
 */
const baseFooterCopyright = () =>
  `Conduction B.V. · KvK 76741850 · BTW NL860784241B01 · IBAN NL51 ABNA 0868951550 · Lauriergracht 14h, 1016 RR Amsterdam · ${new Date().getFullYear()}`;

/**
 * Brand-default footer (full object). Backward-compatible shim for
 * older sites that called `baseFooter()` directly; new callers should
 * compose with `baseFooterLinks` / `baseFooterCopyright` so per-property
 * overrides keep working.
 */
const baseFooter = () => ({
  style: 'dark',
  links: baseFooterLinks(),
  copyright: baseFooterCopyright(),
});

/**
 * Build a complete Docusaurus config from a small set of site-specific opts.
 *
 * Required:
 *   title, tagline, url, baseUrl
 *
 * Optional:
 *   organizationName, projectName, navbar (deep-merged into base),
 *   footer (per-property fallback: any of style/links/copyright the
 *     site omits keeps its brand default — pass `footer: { links: [...] }`
 *     to swap columns while inheriting the KvK/BTW copyright),
 *   minigames (default true; set false to drop the brand canal-footer's
 *     boat-sinking + kade-cyclist mini-games on product pages while
 *     keeping the static skyline + canal decoration),
 *   appVersion (explicit override for the navbar "Stable · v{x.y.z}"
 *     pill; defaults to appinfo/info.xml then package.json — see
 *     resolveAppVersion above),
 *   repoUrl (target of the navbar GitHub icon; defaults to the
 *     ConductionNL org root),
 *   customCss[] (appended to brand.css), plugins[], presets,
 *   i18n (overrides defaults)
 */
function createConfig(opts) {
  if (!opts || !opts.title || !opts.url) {
    throw new Error(
      '@conduction/docusaurus-preset: createConfig() requires { title, tagline, url, baseUrl }'
    );
  }

  /* brand.css is injected globally by the theme plugin (./theme.js) via
     getClientModules(), so customCss carries site-specific CSS only. */
  const customCss = opts.customCss || [];

  /* App version drives the navbar "Stable · v{x.y.z}" pill (resolved
     once at config time). Pulled from appinfo/info.xml, then
     package.json — see resolveAppVersion above. */
  const appVersion = resolveAppVersion(opts);

  return {
    title: opts.title,
    tagline: opts.tagline || '',
    favicon: opts.favicon || 'img/favicon.svg',
    url: opts.url,
    baseUrl: opts.baseUrl || '/',
    trailingSlash: true,

    organizationName: opts.organizationName || 'ConductionNL',
    projectName: opts.projectName || 'design-system',

    /* customFields is the canonical Docusaurus channel for build-time
       data the theme reads at runtime via useDocusaurusContext().
       appVersion drives the navbar "Stable · v{x.y.z}" pill; sites
       extend this by passing their own customFields in opts. */
    customFields: Object.assign(
      {
        ...(appVersion && {appVersion}),
      },
      opts.customFields || {}
    ),

    onBrokenLinks: 'warn',
    onBrokenMarkdownLinks: 'warn',

    /* Two static roots. Docusaurus wires staticDirectories through
       copy-webpack-plugin's parallel pattern processing (Promise.all),
       so for file collisions the winner is whichever pattern finishes
       reading first — non-deterministic in practice (preset wins on
       most disks because it ships smaller). Don't rely on this array
       order for overrides; ship a Docusaurus plugin like
       ./plugins/ai-crawling.js when you need deterministic precedence.
         1. preset's own ../static (canal-footer, conduction-bg,
            hex-rain, platform-diagram, brand img/favicon, logo, logo-
            dark, nextcloud-logo, default OG card)
         2. site's own static/ (CNAME, site-specific images, overrides)
       Files unique to one directory always copy; conflicts are
       essentially undefined behaviour. */
    staticDirectories: opts.staticDirectories || [
      path.resolve(__dirname, '..', 'static'),
      'static',
    ],

    i18n: opts.i18n || I18N,

    /* Sites can pass `opts.presets` to override docs/blog/theme. When
       they do, the preset wraps each classic preset entry to deep-merge
       DEFAULT_SITEMAP_OPTIONS into the entry's sitemap key (lastmod
       becomes automatic, ignorePatterns merge in). Without this wrap
       sites would have to copy-paste the sitemap config in every
       docusaurus.config.js, and the fleet would drift over time. */
    presets: opts.presets
      ? wrapClassicPresetDefaults(opts.presets)
      : [
          [
            'classic',
            {
              docs: {
                sidebarPath: './sidebars.js',
                editUrl: opts.editUrl,
              },
              blog: opts.blog === false ? false : {
                showReadingTime: true,
                blogTitle: opts.title + ' blog',
                blogDescription: 'Updates from Conduction',
              },
              theme: {
                customCss,
              },
              sitemap: DEFAULT_SITEMAP_OPTIONS,
            },
          ],
        ],

    /* Brand theme: registers ./theme/* swizzles (Navbar, Footer, …)
       and auto-loads brand.css. Site-specific themes can be added by
       passing themes: [...] in opts (this default is replaced wholesale). */
    themes: opts.themes || [require.resolve('./theme.js')],

    themeConfig: Object.assign(
      {
        colorMode: {
          defaultMode: 'light',
          disableSwitch: false,
          respectPrefersColorScheme: true,
        },
        navbar: Object.assign(baseNavbar(opts.title, opts.repoUrl), opts.navbar || {}),
        /* Per-property fallback so a site can override one slice of the
           footer (e.g. just `links`) and inherit the rest from the brand.
           Previously `opts.footer` replaced the whole footer wholesale,
           which forced sites to copy the KvK/BTW copyright string just to
           swap columns. */
        footer: (() => {
          const f = opts.footer || {};
          return {
            style:     f.style     || 'dark',
            links:     f.links     || baseFooterLinks(),
            copyright: f.copyright || baseFooterCopyright(),
          };
        })(),
        /* The brand canal-footer carries two interactive mini-games
           (boat-sinking + kade-cyclist). They're a kit-flavour win on
           the design-system showcase but distract on product-page
           landings. Surface as a top-level themeConfig flag so the
           Footer swizzle can drop the game DOM + lazy scripts when a
           site opts out, while still keeping the static skyline +
           canal decoration. Default true preserves prior behaviour. */
        minigames: opts.minigames !== false,
        /* Footer brand block (the wordmark + tagline + triad + socials
           on the left of the canal-footer grid).
             undefined  -> wordmark = 'Conduction' (product-page default;
                          previously this fell back to the site title,
                          which made every product-page footer wear the
                          product's wordmark instead of the company's).
             { wordmark: 'X' } -> single custom brand
             { brands: [{wordmark, logo, href}, ...] } -> dual-brand row,
                          rendered side by side. Used by product pages
                          built jointly with a partner (launchpad + Sendent
                          is the first case). */
        footerBrand: opts.footerBrand || null,
        /* Legal-bar links (Privacy / Terms / ISO) plus the two ISO
           9001 + 27001 certification badges on the right side of the
           canal-footer.

           Defaults point at the canonical Conduction pages on
           www.conduction.nl rather than relative routes. Earlier
           defaults used /privacy, /terms, /iso which 404'd on every
           per-app subdomain (openregister.conduction.nl/privacy etc.)
           because those routes only exist on the marketing site. The
           SEO audit found ~645 sitewide broken internal links across
           the fleet from this single mistake. Sites can override per
           slot to silence broken-link warnings:

             legalLinks: {
               privacy: false,     // hide the Privacy link
               terms:   false,     // hide the Terms link
               iso:     false,     // hide the ISO link AND the cert badges
                                   // (badges follow iso link by default)
               privacy: '/privacy', // self-host: pass a relative route
               certifications: true | false,
             }

           The marketing site at conduction-website passes legalLinks
           explicitly with relative routes so its self-hosted Privacy /
           Terms / ISO pages keep working as before. */
        legalLinks: Object.assign(
          {
            privacy: 'https://www.conduction.nl/privacy',
            terms: 'https://www.conduction.nl/terms',
            iso: 'https://www.conduction.nl/iso',
          },
          opts.legalLinks || {}
        ),
        /* AI-friendly social-card defaults. `image` ships from the
           preset's static/img/og-conduction.png and gets served at
           every consuming site's /img/og-conduction.png; drop your
           own static/img/og-conduction.png to override per-site, or
           pass `themeConfig.image: 'img/og-my-app.png'` to use a
           different file. `metadata` seeds twitter:site + twitter:card
           + og:type baselines; per-page MDX frontmatter still wins
           via Helmet de-dupe.

           These two slots are handled below via explicit overrides
           rather than the wholesale Object.assign so that user-set
           metadata extends (rather than replaces) the brand defaults
           and image falls back gracefully. */
        image: opts.themeConfig?.image || DEFAULT_OG_IMAGE,
        metadata: [
          ...DEFAULT_METADATA,
          ...(opts.themeConfig?.metadata || []),
        ],
      },
      /* Object.assign last so opts.themeConfig overrides primitives
         like colorMode and navbar, but the image + metadata keys
         above pre-merged the user's values so the spread doesn't
         clobber the brand defaults. */
      (() => {
        if (!opts.themeConfig) return {};
        /* eslint-disable-next-line no-unused-vars */
        const {image: _image, metadata: _metadata, ...rest} = opts.themeConfig;
        return rest;
      })()
    ),

    /* AI-crawler discovery: Organization + WebSite JSON-LD on every
       page, plus any site-specific tags the consumer adds. Sites
       inherit the canonical Conduction Organization automatically;
       per-app SoftwareApplication schemas are emitted by the
       <DetailHero> component on the pages it renders. */
    headTags: [
      ...buildAiHeadTags(opts),
      ...(opts.headTags || []),
    ],

    /* The AI-crawling plugin emits /robots.txt (and optionally /llms.txt)
       in postBuild, after webpack's copy-plugin has copied static files.
       It no-ops when the file already exists in outDir, so a site's own
       static/robots.txt or static/llms.txt always wins. Sites disable
       per-file or wholesale via opts.aiCrawling.disable. Hand-rolled
       plugins in opts.plugins are appended after these defaults.

       The IndexNow plugin pings api.indexnow.org with the sitemap URLs
       after a successful build so Bing (and the AI surfaces it feeds,
       Copilot / ChatGPT Search / DuckDuckGo) recrawl within minutes
       instead of the usual 1-4 weeks. No-ops without opts.indexnow.key
       (the per-site IndexNow key, generated once at bing.com/indexnow).
       Sites that prefer the long-tail crawl path opt out by passing
       indexnow: { disable: true } or just leaving the key unset. */
    plugins: [
      [
        require.resolve('./plugins/ai-crawling.js'),
        opts.aiCrawling || {},
      ],
      [
        require.resolve('./plugins/indexnow.js'),
        opts.indexnow || {},
      ],
      /* The Features Page plugin registers a `/features` route backed by
         the consuming app's `docs/features.json` (regenerated from
         openspec/specs/ by the org-wide Features Extract workflow stage).
         No-ops when features.json is absent, so it's safe to enable
         across the fleet ahead of full adoption. Sites opt out via
         opts.featuresPage = { disable: true } or override the page
         title / intro / route path. */
      [
        require.resolve('./plugins/features-page.js'),
        opts.featuresPage || {},
      ],
      ...(opts.plugins || []),
    ],
  };
}

module.exports = {
  createConfig,
  I18N,
  BRAND_ORGANIZATION_JSONLD,
  buildWebsiteJsonLd,
  buildAiHeadTags,
  DEFAULT_SITEMAP_OPTIONS,
  DEFAULT_METADATA,
  DEFAULT_OG_IMAGE,
  baseNavbar,
  baseFooter,
  baseFooterLinks,
  baseFooterCopyright,
};
