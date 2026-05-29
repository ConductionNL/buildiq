// @ts-check

/**
 * OpenBuild documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, i18n scaffolding, KvK / BTW
 * copyright). Site-specific overrides — locale (en only), sidebar
 * path, mermaid theme, custom prism themes, openbuild-only navbar
 * items — are passed through createConfig() opts.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'OpenBuild',
  tagline: 'Citizen-developer app builder for Nextcloud — compose apps from registers, connectors, workflows, and documents without code',
  url: 'https://openbuild.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'openbuild',

  /* English-only for now (ADR-030). Dutch is omitted because shipping
     `i18n.locales: ['en', 'nl']` without translated markdown triggers
     SSR rendering errors on tutorial pages when the docs source moves
     faster than the locale's `current.json`. Re-enable by adding 'nl'
     back once the Dutch translation pass has been completed and the
     metadata audited for stale references. The brand preset's default
     i18n block is replaced wholesale here. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
    localeConfigs: {
      en: { label: 'English' },
    },
  },

  /* The openbuild docs source lives at the repo root of `docs/` rather
     than under a `docs/` subfolder, so we override the preset's default
     `presets:` block to point `docs.path` at './' and disable the blog
     plugin. customCss carries openbuild-specific CSS only — brand
     tokens and the theme swizzles are auto-loaded by the brand theme
     entry in `themes:` below. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          /* docs.path: './' makes plugin-content-docs scan every file
             in docs/, which collides with plugin-content-pages's own
             scan of docs/src/pages/. Exclude src/ (pages live there)
             plus the standard node_modules bucket. */
          exclude: ['**/node_modules/**', 'src/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://codeberg.org/Conduction/openbuild/src/branch/main/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with openbuild's own (Documentation sidebar link,
     openbuild GitHub link). Object.assign in createConfig is shallow,
     so items: replaces wholesale — re-include the locale dropdown and
     add the openbuild GitHub repo link explicitly. */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: 'https://codeberg.org/Conduction/openbuild',
        label: 'Codeberg',
        position: 'right',
      },
      { type: 'localeDropdown', position: 'right' },
    ],
  },

  /* Per-property footer override (preset 1.2.0+): we pass `links` only,
     so the brand `style: 'dark'` and the brand KvK/BTW/IBAN/address
     copyright string both inherit unchanged. */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer's interactive mini-games on this product-page
     footer (preset 1.3.0+). The static skyline + canal decoration are
     kept; the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). prism + mermaid land alongside. */
  themeConfig: {
    image: 'img/og-openbuild.png',
    prism: {
      theme: require('prism-react-renderer/themes/github'),
      darkTheme: require('prism-react-renderer/themes/dracula'),
    },
    mermaid: {
      theme: { light: 'default', dark: 'dark' },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   markdown + onBrokenAnchors directly so they make it into the final
   Docusaurus config. trailingSlash is left at the preset's default
   (true) so /docs/intro/ resolves cleanly under GH Pages. */
config.onBrokenAnchors = 'warn';
config.markdown = {
  mermaid: true,
  /* Tutorial pages reference screenshots populated by
     `tests/e2e/docs-screenshots.spec.ts`. The Playwright capture run
     is separate from the docs build, so the build needs to succeed
     even when a fresh checkout doesn't have every PNG yet. Warn
     instead of failing — the absence is visible at preview time and
     the capture spec brings everything back on demand. */
  hooks: {
    onBrokenMarkdownImages: 'warn',
  },
};

module.exports = config;
