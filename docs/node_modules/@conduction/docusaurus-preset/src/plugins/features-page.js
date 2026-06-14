/**
 * @conduction/docusaurus-preset/plugins/features-page
 *
 * Adds a `/features` route to every Conduction docs site, backed by the
 * `docs/features.json` artefact regenerated from `openspec/specs/` by the
 * org-wide Features Extract workflow stage
 * (https://codeberg.org/Conduction/.github/src/branch/main/.github/workflows/quality.yml).
 *
 * The route renders the brand `<FeaturesPage />` component, which in turn
 * maps each entry to a `<FeatureItem>` inside `<FeatureGrid>`. Every entry
 * is rendered with status `stable` (the extractor only emits specs whose
 * frontmatter declares `status: implemented` or `status: reviewed`, both
 * of which map to the mint hex bullet).
 *
 * Options:
 *   path         string  route path (default '/features')
 *   featuresFile string  path to features.json (default 'features.json',
 *                        resolved against `siteDir` — i.e. the directory
 *                        holding `docusaurus.config.js`)
 *   title        string  page title (default 'Features')
 *   intro        string  optional intro paragraph rendered above the grid
 *   disable      boolean skip the plugin entirely (the consuming site can
 *                        keep the entry in `createConfig({featuresPage:
 *                        {disable: true}})` instead of yanking the plugin)
 *
 * No-op when the resolved `featuresFile` doesn't exist on disk. This
 * keeps the plugin safe to enable across the fleet before every app has
 * adopted the workflow stage that produces the file.
 */

const fs = require('fs');
const path = require('path');

function loadFeatures(absPath) {
  try {
    if (!fs.existsSync(absPath)) return null;
    const raw = fs.readFileSync(absPath, 'utf8');
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;
    return parsed;
  } catch (e) {
    return null;
  }
}

function featuresPagePlugin(context, options = {}) {
  if (options.disable === true) return {name: 'conduction-features-page'};

  const featuresFile = options.featuresFile || 'features.json';
  const absPath = path.isAbsolute(featuresFile)
    ? featuresFile
    : path.resolve(context.siteDir, featuresFile);

  const features = loadFeatures(absPath);
  if (features === null) {
    /* Plugin is enabled but the consuming site hasn't produced
       features.json yet — most likely the Features Extract workflow
       stage hasn't run, or the app has no openspec/specs/ entries with
       a publishable status. Stay silent rather than build-fail. */
    return {name: 'conduction-features-page'};
  }

  const routePath = options.path || '/features';
  const title = options.title || 'Features';
  const intro = options.intro || null;

  return {
    name: 'conduction-features-page',

    async loadContent() {
      return {features, title, intro};
    },

    async contentLoaded({content, actions}) {
      const dataPath = await actions.createData(
        'conduction-features-page.json',
        JSON.stringify(content)
      );
      actions.addRoute({
        path: routePath,
        component: require.resolve('../components/FeaturesPage/FeaturesPage.jsx'),
        exact: true,
        modules: {data: dataPath},
      });
    },
  };
}

module.exports = featuresPagePlugin;
