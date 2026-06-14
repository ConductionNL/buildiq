/**
 * @conduction/docusaurus-preset/plugins/indexnow
 *
 * Docusaurus plugin that pings the IndexNow API after a successful
 * build so Bing (which feeds Copilot, ChatGPT Search, DuckDuckGo)
 * recrawls every URL in the site's sitemap within minutes instead
 * of the usual 1-4 weeks. Yandex also accepts the same payload.
 *
 * Reference: https://www.indexnow.org/documentation
 *
 * How it works
 *   1. Every consuming site exposes a unique key at /<key>.txt at
 *      build time (the key file's body must contain the key, that's
 *      the verification handshake IndexNow requires).
 *   2. After build, the plugin POSTs the full URL list to
 *      api.indexnow.org with that key.
 *   3. Bing fetches /<key>.txt to verify ownership, then schedules
 *      recrawl of every URL in the payload.
 *
 * Options:
 *   key       string  64-char IndexNow key. Required. Sites generate
 *                     once at https://www.bing.com/indexnow/getstarted
 *                     and reuse forever.
 *   keyLocation  string  optional; if the key file lives at a
 *                     non-default path, override here. Default:
 *                     <siteUrl>/<key>.txt
 *   disable   boolean  opt out without removing the plugin entry.
 *   host      string   IndexNow API host. Default api.indexnow.org;
 *                     Bing and Yandex both forward to each other,
 *                     so one POST notifies both.
 *
 * Why postBuild + not in CI: postBuild runs once per successful
 * build, so the ping fires only when content actually changed. If
 * we wired it as a separate workflow we'd need to detect changes
 * ourselves; the Docusaurus build is the natural trigger.
 *
 * If the IndexNow endpoint is unreachable (network blip, rate limit)
 * the plugin logs and continues; we never want a transient external
 * service to fail a deploy.
 */

const fs = require('fs');
const path = require('path');
const https = require('https');

function indexNowPlugin(context, options = {}) {
  if (!options || options.disable) {
    return {name: 'conduction-indexnow', postBuild() {}};
  }
  const key = options.key;
  if (!key) {
    return {
      name: 'conduction-indexnow',
      postBuild() {
        console.warn(
          '[indexnow] no `key` option provided; skipping. Set ' +
          'opts.indexnow.key (or pass through createConfig) to enable.'
        );
      },
    };
  }
  const host = options.host || 'api.indexnow.org';

  return {
    name: 'conduction-indexnow',

    async postBuild({outDir, siteConfig}) {
      const siteUrl = (siteConfig.url || '').replace(/\/$/, '');
      if (!siteUrl) {
        console.warn('[indexnow] siteConfig.url missing; skipping.');
        return;
      }

      /* Ensure the verification key file exists at /<key>.txt so
         IndexNow can confirm we own the host. Body is just the key
         per the IndexNow handshake protocol. */
      const keyFile = path.join(outDir, `${key}.txt`);
      try {
        fs.writeFileSync(keyFile, key, 'utf8');
      } catch (e) {
        console.warn(`[indexnow] failed to write ${keyFile}: ${e.message}`);
        return;
      }

      /* Build the URL list from the rendered sitemap.xml. Sitemap-
         backed instead of a directory walk because the sitemap is
         the same canonical list Google + Bing already trust, and
         it respects the site's ignorePatterns + i18n routes
         automatically. */
      const sitemapPath = path.join(outDir, 'sitemap.xml');
      let urls = [];
      try {
        const xml = fs.readFileSync(sitemapPath, 'utf8');
        const matches = xml.match(/<loc>([^<]+)<\/loc>/g) || [];
        urls = matches.map(m => m.replace(/<\/?loc>/g, ''));
      } catch (e) {
        console.warn(`[indexnow] could not read sitemap.xml: ${e.message}`);
        return;
      }
      if (urls.length === 0) {
        console.warn('[indexnow] sitemap.xml has no <loc> entries; skipping.');
        return;
      }
      /* IndexNow caps each POST at 10000 URLs. Most Conduction sites
         are well under that; this guard exists so a freak large sitemap
         doesn't 400 the request. */
      const capped = urls.slice(0, 10000);

      const host_without_protocol = siteUrl.replace(/^https?:\/\//, '');
      const keyLocation = options.keyLocation || `${siteUrl}/${key}.txt`;
      const payload = JSON.stringify({
        host: host_without_protocol,
        key,
        keyLocation,
        urlList: capped,
      });

      await new Promise(resolve => {
        const req = https.request(
          {
            hostname: host,
            port: 443,
            path: '/indexnow',
            method: 'POST',
            headers: {
              'Content-Type': 'application/json; charset=utf-8',
              'Content-Length': Buffer.byteLength(payload),
            },
            timeout: 10000,
          },
          res => {
            const ok = res.statusCode >= 200 && res.statusCode < 300;
            if (ok) {
              console.log(`[indexnow] submitted ${capped.length} URLs to ${host} (${res.statusCode})`);
            } else {
              console.warn(`[indexnow] ${host} returned ${res.statusCode}; deploy continues. URLs to retry next time will sync via the normal crawl.`);
            }
            res.resume();
            resolve();
          }
        );
        req.on('error', err => {
          console.warn(`[indexnow] request failed: ${err.message}; deploy continues.`);
          resolve();
        });
        req.on('timeout', () => {
          console.warn('[indexnow] request timed out after 10s; deploy continues.');
          req.destroy();
          resolve();
        });
        req.write(payload);
        req.end();
      });
    },
  };
}

module.exports = indexNowPlugin;
