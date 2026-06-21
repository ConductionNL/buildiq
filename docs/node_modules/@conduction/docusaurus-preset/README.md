# @conduction/docusaurus-preset

Brand-default Docusaurus 3 config for Conduction sites. Tokens, theme, navbar, footer, four-locale i18n (nl / en / de / fr), all pre-wired.

## Status

Source lives inside the [design-system monorepo](https://github.com/ConductionNL/design-system); published to npm under the `@conduction` scope as a single package. Install in any product site with `npm i @conduction/docusaurus-preset`. The diagram web-component runtime (`<cn-hex>`, `<cn-platform>`, etc.) ships inside this same package under `@conduction/docusaurus-preset/diagrams` — no separate install needed.

## Brand rules

A few non-negotiables encoded by the package CSS and worth knowing about:

- **App Builder hex is always KNVB orange.** The hex in `<PlatformDiagram/>` representing the "compose your own app" pathway carries `var(--c-orange-knvb)` regardless of whether the cluster ships a `COMING SOON` badge. It is the proposition the eye should land on. Don't gate it on app state.
- **Pointy-top hexes only, no rotation.** Every `<cn-*>` hex shape is pointy-top by design. Flat-top hexes belong to other brands.
- **One orange accent per scene.** KNVB orange is the highlight, used once per diagram. The new `<Pair/>` and `<ArchFlow hex>` components own that budget when present.

## What it gives you

- **Brand CSS** — tokens (cobalt palette, KNVB orange, Plex Mono, hex clip-paths, Common Ground yellow) auto-applied to Docusaurus's Infima theme variables. Navbar, footer, sidebar, buttons, code blocks all inherit the brand without a single swizzle.
- **i18n config** — four locales pinned: NL default at the URL root, EN/DE/FR at `/en/`, `/de/`, `/fr/`. `htmlLang` per locale, `trailingSlash: true`, locale dropdown ready.
- **Brand-default navbar** — locale-dropdown + GitHub link. Sites override `items[]` for site-specific navigation.
- **Brand-default footer** — three-column link grid + Conduction-tells (KvK, BTW, address). Per-property override: pass `footer: { links: [...] }` to swap columns and inherit the brand copyright unchanged. Spread `baseFooterLinks()` to keep one or two brand columns alongside site-specific ones.
- **Sensible defaults** — `trailingSlash`, `onBrokenLinks: 'warn'`, `respectPrefersColorScheme`, dark-mode brand mapping.
- **AI-crawler baseline** — Organization + WebSite JSON-LD on every page, `SoftwareApplication` JSON-LD from `<DetailHero>`, `FAQPage` JSON-LD from `<FAQ>`, default `og:image` + Twitter card meta, sitemap options, and a `postBuild` plugin that emits `robots.txt` when the site does not ship its own. See the AI baseline section below for the validator and content requirements.

## Usage

```js
// docusaurus.config.js
const { createConfig } = require('@conduction/docusaurus-preset');

module.exports = createConfig({
  title: 'Conduction',
  tagline: 'Open-source apps for the Nextcloud workspace.',
  url: 'https://conduction.nl',
  baseUrl: '/',
  organizationName: 'ConductionNL',
  projectName: 'design-system',

  // Optional overrides
  navbar: {
    items: [
      { to: '/docs/intro', label: 'Docs', position: 'left' },
      { to: '/blog', label: 'Blog', position: 'left' },
      // brand-default items (locale dropdown, GitHub) are merged in
    ],
  },

  // Site-specific CSS appended after brand.css
  customCss: ['./src/css/site.css'],
});
```

The function returns a complete Docusaurus config with brand defaults pre-applied. Override any field by passing it in or merging after.

## API

`createConfig(opts)` — returns a Docusaurus config object.

| Option | Required | Default | Purpose |
| --- | --- | --- | --- |
| `title` | ✓ | — | Site title (also used as the navbar title and footer reference). |
| `tagline` |  | `''` | Site tagline. |
| `url` | ✓ | — | Production URL, e.g. `https://connext.conduction.nl`. |
| `baseUrl` |  | `'/'` | Path under the URL. Almost always `/` since each site has its own subdomain. |
| `favicon` |  | `'img/favicon.svg'` | Favicon path under `static/`. |
| `organizationName` |  | `'ConductionNL'` | GitHub org. |
| `projectName` |  | `'design-system'` | GitHub repo. |
| `i18n` |  | nl / en / de / fr, NL default | Override the brand-default i18n block. |
| `navbar` |  | locale dropdown + GitHub | Merged into the brand-default navbar object. |
| `footer` |  | three-column link grid + KvK/BTW copyright | Per-property fallback: any of `style` / `links` / `copyright` you omit keeps the brand default. Pass `footer: { links: [...] }` to swap columns and inherit the brand copyright. |
| `footerBrand` |  | `{ wordmark: 'Conduction' }` | Overrides the wordmark in the canal-footer's left brand block. Pass `{ wordmark: 'X' }` for a single brand or `{ brands: [{wordmark, logo, href}, ...] }` for product pages co-built with a partner (rendered side by side). |
| `minigames` |  | `true` | Set `false` to drop the brand canal-footer's boat-sinking + kade-cyclist mini-games on product pages. The static skyline + canal decoration are kept. |
| `customCss` |  | `[]` | Site-specific CSS, appended to `brand.css`. |
| `presets` |  | `[['classic', …]]` | Replaces the default preset list. |
| `plugins` |  | `[]` | Docusaurus plugins. |
| `themeConfig` |  | `{}` | Merged into the brand-default `themeConfig`. |
| `editUrl` |  | undefined | Edit-this-doc link. |
| `blog` |  | enabled | Set `false` to disable the blog plugin. |

Also exported: `I18N`, `baseNavbar(siteName)`, `baseFooter()`, `baseFooterLinks()`, `baseFooterCopyright()` for sites that want to compose manually. Common pattern on a product page: pass site-specific columns plus the Conduction column from the brand default:

```js
const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

module.exports = createConfig({
  // …
  footer: {
    links: [
      { title: 'MyProduct', items: [/* …site links */] },
      // Spread the brand "Conduction" column to keep the corporate anchor.
      ...baseFooterLinks().filter((c) => c.title === 'Conduction'),
    ],
    // copyright: omitted -> brand KvK/BTW/IBAN inherits.
  },
});
```

## Brand CSS exports

| Path | Purpose |
| --- | --- |
| `@conduction/docusaurus-preset/css/brand.css` | The default. Imported by `createConfig()` automatically. |
| `@conduction/docusaurus-preset/css/tokens.css` | Just the tokens, no Infima mapping. Use if you swizzle the entire theme. |

## OpenCatalogi content plugin

A future `@conduction/docusaurus-plugin-opencatalogi` will pull pages from a Nextcloud OpenCatalogi register at build time. The shape:

```js
// docusaurus.config.js
module.exports = createConfig({
  title: 'Conduction',
  url: 'https://conduction.nl',
  plugins: [
    [
      '@conduction/docusaurus-plugin-opencatalogi',
      {
        // Where to fetch from (production: a public read-only API; local: localhost)
        apiUrl: process.env.OPENCATALOGI_URL || 'http://localhost:8080/index.php/apps/openregister/api',
        register: 'connext-content',
        schema: 'page',
        // Where to drop the generated Markdown
        output: 'i18n/{locale}/docusaurus-plugin-content-pages',
        // Pull translations per locale; fall back to defaultLocale where missing
        locales: ['nl', 'en', 'de', 'fr'],
      },
    ],
  ],
});
```

The plugin runs during `docusaurus build`, fetches OpenCatalogi objects, writes Markdown files per locale into the i18n folders, and Docusaurus picks them up as if they were committed. End result: editors update content in the Nextcloud OpenCatalogi UI, push triggers a build, the static site is regenerated. No runtime API calls; the production site stays a static deploy.

That plugin is not yet built. Until it lands, sites use plain Markdown in `docs/` and `i18n/<locale>/`.

## Local development against a local Nextcloud

Once the OpenCatalogi plugin is built, the dev workflow becomes:

```bash
# Start the local Nextcloud (Conduction's docker-compose dev environment)
cd ~/nextcloud-docker-dev && docker compose up -d

# Start Docusaurus, pointing at localhost:8080
cd ~/.../sites/www
OPENCATALOGI_URL=http://localhost:8080/index.php/apps/openregister/api npm start
```

`npm start` runs Docusaurus's hot-reload dev server. The plugin re-fetches on each rebuild so editing in the Nextcloud UI shows up after a save.

## Consume from a product site

```bash
npm i @conduction/docusaurus-preset @docusaurus/core @docusaurus/preset-classic react react-dom
```

Then use `createConfig()` in your `docusaurus.config.js` as shown in [Usage](#usage) above. The diagram web components register themselves on the client when `<Hero/>` or `<Diagrams/>` mount — no extra import needed in MDX. To register them eagerly (e.g. for a docs page that uses `<cn-hex>` directly without a wrapper), import the runtime module:

```js
// site src/clientModules/diagrams.js
import '@conduction/docusaurus-preset/diagrams';
```

This is how product sites such as `mydash.conduction.nl/docs/...` adopt the brand without copying CSS or theme code, and stay in sync as the design-system evolves.

## AI-crawler baseline

Every site that consumes this preset inherits a contract that AI crawlers (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google AI Overviews) expect. The schemas, meta tags, and `robots.txt` ship automatically; sites only have to opt in to the content that surfaces them.

**What the preset ships**

| Surface | Source | How a site uses it |
| --- | --- | --- |
| Organization + WebSite JSON-LD | `headTags` injected by `createConfig` | Automatic on every page |
| `og:image`, `twitter:site`, `twitter:card`, `og:type` | `themeConfig.image` + `themeConfig.metadata` defaults | Override per site by passing `themeConfig.image: 'img/og-my-app.png'` |
| Default `robots.txt` | `conduction-ai-crawling` postBuild plugin | Drop `static/robots.txt` to override |
| `SoftwareApplication` JSON-LD | `<DetailHero appId="my-app" .../>` | Pages that should advertise the app must render `<DetailHero>` with an `appId` that resolves in `src/data/apps-registry.js`. No DetailHero means no schema. |
| `FAQPage` JSON-LD | `<FAQ>` with `<FAQItem question=...>` children | Drop a `<FAQ>` block onto a page; the schema is auto-emitted from the children |
| Sitemap options | Default `sitemap` config on the classic preset | Sites that override `presets` must include their own `sitemap` block |

**Validating a site**

The preset ships a generic 8-check validator as a `bin`. Wire it into the site's build:

```jsonc
// docs/package.json
{
  "scripts": {
    "build": "docusaurus build",
    "postbuild": "validate-ai-baseline",
    "validate:ai-baseline": "validate-ai-baseline"
  }
}
```

`npm run build` now exits non-zero if any of these regress: `robots.txt` exists with a Sitemap line and an AI-bot allow line, `sitemap.xml` has at least one URL, the homepage emits Organization + WebSite JSON-LD plus `og:image` / `og:type` / `twitter:site` / `twitter:card`, and the `og:image` URL resolves to a real file. Sites can extend the validator with extra checks (per-app SoftwareApplication, FAQPage on specific pages, etc.) by adding their own `scripts/validate-ai-baseline-site.mjs` and chaining it.

**Per-app docs site checklist**

For a per-app docs site to satisfy the full schema contract, the landing page must render `<DetailHero appId="my-app" .../>` with an `appId` that exists in `src/data/apps-registry.js`. That single render emits the `SoftwareApplication` JSON-LD with category mapping (Data and Processes -> BusinessApplication, Connectors -> DeveloperApplication, etc.), `operatingSystem: 'Nextcloud'`, and the EUPL-1.2 license URL. Sites that build a custom landing without `<DetailHero>` get only Organization + WebSite, not the per-app schema.

**Opting out**

```js
createConfig({
  title: '...',
  url: '...',
  baseUrl: '/',
  aiCrawling: { disable: true },          // skip the whole postBuild plugin
  // or, finer-grained:
  aiCrawling: { disable: { robotsTxt: true } },  // ship our own static/robots.txt
});
```

## Traditional SEO baseline

The same `createConfig` call also wires the traditional-search baseline that pairs with the AI-crawler one. Google, Bing, DuckDuckGo and the AI surfaces those engines feed (Copilot, ChatGPT Search, Perplexity) all benefit.

**What's shipped automatically**

- **Sitemap with `lastmod`** from file mtime; `priority` and `changefreq` are dropped because Google ignores them. `/page/N/` pagination and `/academy/tags/` thin pages are excluded sitewide so they don't dilute crawl budget.
- **Footer legal links default to absolute URLs on `www.conduction.nl`** (`/privacy`, `/terms`, `/iso`). Earlier defaults used relative routes that 404'd on every per-app subdomain — the SEO audit found ~645 sitewide broken internal links across the fleet from this single mistake. Marketing sites that self-host these pages pass `legalLinks: { privacy: '/privacy', ... }` to opt back into relative routing.
- **Search Console / Bing Webmaster / Yandex / Facebook / Pinterest verification meta tags** via `opts.searchConsoleVerification`. Each present token becomes a `<meta>` tag in the global head, which lets a non-DNS-admin teammate verify the property via the console UI:

```js
createConfig({
  // ...
  searchConsoleVerification: {
    google: 'abc123...',     // -> <meta name="google-site-verification">
    bing:   'xyz...',        // -> <meta name="msvalidate.01">
    yandex: '...',           // -> <meta name="yandex-verification">
    facebook: '...',         // -> <meta name="facebook-domain-verification">
    pinterest: '...',        // -> <meta name="p:domain_verify">
  },
});
```

**`BreadcrumbList` JSON-LD**

- Docs pages: emitted automatically by Docusaurus 3.10+ via the bundled `DocBreadcrumbs/StructuredData` component. Older Docusaurus versions render the same data as inline microdata (`itemscope`/`itemprop`), which Google still reads.
- Marketing / landing pages: `<DetailHero>` emits a `BreadcrumbList` JSON-LD block from its existing `crumb` prop. Pages that pass `crumb={[...]}` to `<DetailHero>` get the schema for free; no additional component needed.

**`TechArticle` JSON-LD on docs pages**

The preset's `DocItem/Content` swizzle prepends a `TechArticle` JSON-LD block to every documentation page. Fields derived from the page's frontmatter and Docusaurus metadata:

- `headline` and `description` from frontmatter title + description
- `datePublished` and `dateModified` from `metadata.lastUpdatedAt` (git mtime by default)
- `author` from frontmatter `author:` or `authors:` (string, object, or array). Defaults to "Conduction" as the team author
- `publisher` references the shared Conduction `Organization` via `@id`
- `mainEntityOfPage` resolves to the doc's canonical URL

Sites can opt out per-page by setting `techArticle: false` in the doc's frontmatter.

**IndexNow integration for Bing + AI surfaces**

`@conduction/docusaurus-preset/plugins/indexnow` is auto-loaded by `createConfig`. Sites enable it by passing a key:

```js
createConfig({
  // ...
  indexnow: {
    key: 'abc123...', // 64-char key from bing.com/indexnow/getstarted
  },
});
```

The plugin writes `<key>.txt` to the build output (for IndexNow's ownership handshake) and POSTs the full sitemap URL list to `api.indexnow.org` after a successful build. Bing recrawls within minutes; Yandex consumes the same payload. DuckDuckGo, Copilot, and ChatGPT Search all read Bing's index, so a single ping covers most non-Google surfaces.

Failure-tolerant: timeouts or 5xx responses log a warning and let the deploy continue. Disable via `indexnow: { disable: true }`.

**Per-page title format**

Docusaurus defaults to `{Page} | {Site}`, which produces `OpenRegister | OpenRegister` on per-app homepages. Override per page via frontmatter `title:` for now; a `titleFormat` option may land in a future release.

## Releasing

Releases auto-publish on push to `main`, driven by [semantic-release](https://semantic-release.gitbook.io/) reading [conventional-commit](https://www.conventionalcommits.org/) messages. The [.github/workflows/publish-packages.yml](../.github/workflows/publish-packages.yml) workflow walks every commit since the last `@conduction/docusaurus-preset-v*` tag and decides what to ship:

| Commit prefix | Release |
| --- | --- |
| `feat:` | minor bump |
| `fix:` | patch bump |
| `feat!:` or `BREAKING CHANGE:` footer | major bump |
| anything else (`chore:`, `docs:`, `refactor:`, …) | no release |

Path filtering via [semantic-release-monorepo](https://github.com/pmowrer/semantic-release-monorepo) restricts the commit scan to commits that touched files inside `docusaurus-preset/`. Edits to `preview/`, `sites/`, `brand/`, etc. never trigger a release. The diagram primitives ship inside the same tarball under `src/diagrams/`, so there is exactly one npm release per qualifying push.

A manual `workflow_dispatch` is also accepted, with an optional `dry_run` flag that runs `semantic-release --dry-run` (no publish, no tag, no release) — useful for sanity-checking the next-version decision before merging a feature branch.

**One-time setup**: configure the npm Trusted Publisher (OIDC) link.

1. Open the package settings on npm: <https://www.npmjs.com/package/@conduction/docusaurus-preset/access>. Scroll to "Trusted Publisher" and click "Set up connection".
2. Fill in:
   - Publisher: GitHub Actions
   - Organization or user: `ConductionNL`
   - Repository: `design-system`
   - Workflow filename: `publish-packages.yml`
   - Environment name: leave empty (or set to a GitHub Environment if you later want a manual approval gate)
3. Click "Set up connection".

That's it. There is no token to generate, no secret to install, no expiry to track. The workflow's `permissions: id-token: write` lets the runner request a short-lived OIDC token; npm validates the `{repo, workflow}` claim against the trust config and issues a one-shot publish credential. Each release is also signed with a [provenance attestation](https://docs.npmjs.com/generating-provenance-statements) so consumers can verify the build came from this exact workflow run.

**Per release:**

```bash
# Make a change inside docusaurus-preset/ and commit with a conventional prefix.
$EDITOR docusaurus-preset/src/components/Hero.jsx

git add docusaurus-preset/src/components/Hero.jsx
git commit -m "feat: add tagline slot to Hero"
git push origin main   # semantic-release decides + publishes
```

`package.json#version` on `main` stays stale on purpose — semantic-release uses the `@conduction/docusaurus-preset-v*` tag stream as its source of truth and bumps `package.json` in-place during the publish run without committing it back. The version on npm and the GitHub Releases page are authoritative; the field in `main`'s `package.json` only matters during a publish.

If a publish fails mid-run, fix the issue and push another commit — `semantic-release` will retry the bump on the next run. npm rejects re-publishing the same version, so the next attempt picks the following patch.

## License

EUPL-1.2 + MIT.
