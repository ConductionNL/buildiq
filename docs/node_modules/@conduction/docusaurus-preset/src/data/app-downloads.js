/**
 * @conduction/docusaurus-preset/data
 *
 * Build-time access to the ConductionNL app-download stats. The JSON
 * itself is refreshed every weekday by .github/workflows/app-downloads.yml
 * and committed to design-system/data/app-downloads.json.
 *
 * Usage in MDX:
 *
 *   import {totalDownloads, downloadsForApp} from '@conduction/docusaurus-preset/data';
 *
 *   <StatsStrip stats={[
 *     {value: totalDownloads.toLocaleString('en'), label: 'app store downloads'},
 *     ...
 *   ]} />
 *
 *   <DetailHero appId="openregister" ... />   // looks up downloads automatically
 */

/* The JSON lives at design-system/data/app-downloads.json — three
   levels up from this file inside the monorepo. When the preset is
   installed via npm into a consumer site, that path resolves outside
   the package directory and webpack 404s the import. Wrap in
   require + try/catch so the consumer build falls back to an empty
   stats payload instead of failing the whole build over a missing
   download counter. */
let data;
try {
  // eslint-disable-next-line global-require, import/no-unresolved
  data = require('../../../data/app-downloads.json');
} catch (e) {
  data = {
    generated_at: null,
    totals: { downloads: 0, apps_total: 0, apps_in_store: 0 },
    apps: [],
  };
}

export default data;

export const totalDownloads = data.totals.downloads;
export const totalDownloadsGithub = data.totals.downloads_github ?? data.totals.downloads;
export const totalDownloadsCodeberg = data.totals.downloads_codeberg ?? 0;
export const appsTotal = data.totals.apps_total;
export const appsInStore = data.totals.apps_in_store;
export const generatedAt = data.generated_at;

const byId = new Map(data.apps.map((a) => [a.id, a]));

export function appStats(appId) {
  return byId.get(appId) || null;
}

export function downloadsForApp(appId) {
  const app = byId.get(appId);
  if (!app) return 0;
  // New shape: combined GitHub (legacy) + Codeberg (live) total.
  if (typeof app.downloads_total === 'number') return app.downloads_total;
  // Back-compat with the GitHub-only JSON shape.
  const gh = app.github ? app.github.downloads : 0;
  const cb = app.codeberg ? app.codeberg.downloads : 0;
  return gh + cb;
}

export function formatDownloads(n, locale = 'en') {
  return Number(n || 0).toLocaleString(locale);
}
