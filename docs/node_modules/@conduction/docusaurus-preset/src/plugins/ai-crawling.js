/**
 * @conduction/docusaurus-preset/plugins/ai-crawling
 *
 * Docusaurus plugin that ships the AI-crawler-friendly fleet baseline:
 *   - /robots.txt  default: open posture (allows search and training
 *     bots), generated from the template at static/robots.txt.template
 *   - /llms.txt    optional: a llmstxt.org-format site index. Sites
 *     pass `opts.llmsTxt` to provide their own content; absent that,
 *     no llms.txt is emitted (the spec needs hand-curated copy and a
 *     generic placeholder would be worse than nothing).
 *
 * Why a postBuild plugin instead of static/ files: Docusaurus wires
 * staticDirectories through copy-webpack-plugin's parallel pattern
 * processing, which makes file-collision resolution non-deterministic
 * (patterns are emitted via Promise.all and the first-emitted asset
 * wins). Shipping defaults via this plugin moves the write to the
 * post-build hook, which runs strictly after all static files have
 * been copied. The plugin no-ops when the target file already exists
 * in the build output, so a site's own static/robots.txt or
 * static/llms.txt always wins.
 *
 * Options:
 *   robotsTxt   string  override the default robots.txt body
 *   llmsTxt     string  llms.txt body to emit (no default)
 *   disable     boolean | object  fine-grained opt-out:
 *                 disable: true                  skip the whole plugin
 *                 disable: {robotsTxt: true}     skip just robots.txt
 *                 disable: {llmsTxt: true}       skip just llms.txt
 */

const fs = require('fs');
const path = require('path');

const TEMPLATE_PATH = path.resolve(
  __dirname,
  '..',
  '..',
  'static',
  'robots.txt.template'
);

function readRobotsTemplate() {
  try {
    return fs.readFileSync(TEMPLATE_PATH, 'utf8');
  } catch (e) {
    return 'User-agent: *\nAllow: /\n';
  }
}

/**
 * Walk every staticDirectory the site exposes and look for the given
 * filename at the root. Used to decide whether the plugin should emit
 * its default — sites that ship their own static/robots.txt (or
 * static/llms.txt) take precedence over the plugin's fallback.
 */
function siteShipsFile(context, filename) {
  const dirs = context.siteConfig?.staticDirectories || ['static'];
  const siteDir = context.siteDir;
  for (const dir of dirs) {
    const resolved = path.isAbsolute(dir) ? dir : path.resolve(siteDir, dir);
    if (fs.existsSync(path.join(resolved, filename))) return true;
  }
  return false;
}

function aiCrawlingPlugin(context, options = {}) {
  const disable = options.disable === true
    ? {robotsTxt: true, llmsTxt: true}
    : (options.disable || {});

  /* Decide once at plugin-load time which files the plugin will emit.
     Checking now (not in postBuild) means we read source staticDir
     contents while disk state is stable, avoiding the postBuild race
     where webpack's CopyPlugin may not have flushed its assets yet. */
  const shouldEmitRobots = !disable.robotsTxt
    && !siteShipsFile(context, 'robots.txt');
  const shouldEmitLlms = !disable.llmsTxt
    && options.llmsTxt
    && !siteShipsFile(context, 'llms.txt');

  return {
    name: 'conduction-ai-crawling',

    async postBuild({outDir, siteConfig}) {
      const tasks = [];

      if (shouldEmitRobots) {
        const target = path.join(outDir, 'robots.txt');
        let body = options.robotsTxt || readRobotsTemplate();
        /* If the site config has a url, append a Sitemap line so
           crawlers without locale-suffix discovery still find the
           per-locale sitemaps. Sites supplying their own robotsTxt
           body are trusted to include their own Sitemap lines. */
        if (!options.robotsTxt && siteConfig?.url) {
          const u = siteConfig.url.replace(/\/$/, '');
          body = body.trimEnd() + `\n\nSitemap: ${u}/sitemap.xml\n`;
        }
        tasks.push(fs.promises.writeFile(target, body, 'utf8'));
      }

      if (shouldEmitLlms) {
        const target = path.join(outDir, 'llms.txt');
        tasks.push(fs.promises.writeFile(target, options.llmsTxt, 'utf8'));
      }

      await Promise.all(tasks);
    },
  };
}

module.exports = aiCrawlingPlugin;
module.exports.readRobotsTemplate = readRobotsTemplate;
