/**
 * <AppCrossLinks />
 *
 * Cross-links between the three surfaces a visitor lands on while
 * learning about a Conduction app:
 *
 *   1. /apps/<slug>                              product page
 *   2. https://<slug>.conduction.nl              documentation
 *   3. /academy?app=<slug>                       academy filtered
 *
 * URLs come from the shared apps-registry so all three surfaces stay
 * in lockstep without per-page constants.
 *
 * Two variants:
 *   - rail   sticky right-rail card. Used on /apps/<slug>.mdx, paired
 *            with a 2-col grid (see partners/<slug>.mdx for the same
 *            layout pattern).
 *   - inline page-bottom block, three link tiles in a row. Used at
 *            the bottom of an academy post and on each app's docs
 *            overview page (or auto-injected via the DocItemFooter
 *            swizzle when the docs site sets themeConfig.conduction
 *            .appId in docusaurus.config.js).
 *
 * `surface` tells the component which row to omit, so we never link
 * the user to the page they're already on:
 *   - surface="product"  hides the "Open product page" row
 *   - surface="academy"  hides the "Read in the academy" row
 *   - surface="docs"     hides the "Read the documentation" row
 *   - surface omitted    all three rows render
 *
 * Usage:
 *
 *   import {AppCrossLinks} from '@conduction/docusaurus-preset/components';
 *
 *   <AppCrossLinks
 *     variant="rail"
 *     apps={['opencatalogi']}
 *     surface="product"
 *   />
 *
 *   <AppCrossLinks
 *     variant="inline"
 *     apps={frontMatter.apps}
 *     surface="academy"
 *   />
 */

import React from 'react';
import {APPS_REGISTRY} from '../../data/apps-registry';
import AppGlyph from '../AppGlyph/AppGlyph.jsx';
import styles from './AppCrossLinks.module.css';

const ROWS = [
  {key: 'product', surfaceKey: 'product', hrefKey: 'productHref', label: 'Open product page',   hint: 'Capabilities, pricing, install link'},
  {key: 'docs',    surfaceKey: 'docs',    hrefKey: 'docsHref',    label: 'Read the documentation', hint: 'Reference, guides, API'},
  {key: 'academy', surfaceKey: 'academy', hrefKey: 'academyHref', label: 'Read in the academy',    hint: 'Tutorials, case studies, blog'},
];

const ICONS = {
  product: (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M3 7l9-4 9 4-9 4-9-4z" />
      <path d="M3 12l9 4 9-4" />
      <path d="M3 17l9 4 9-4" />
    </svg>
  ),
  docs: (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6" />
      <path d="M9 13h6M9 17h6M9 9h2" />
    </svg>
  ),
  academy: (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M3 7l9-4 9 4-9 4-9-4z" />
      <path d="M5 11v5a7 7 0 0 0 14 0v-5" />
      <path d="M21 7v5" />
    </svg>
  ),
};

function AppCard({app, surface, variant}) {
  const reg = APPS_REGISTRY[app];
  if (!reg) return null;

  const rows = ROWS.filter((row) => row.surfaceKey !== surface);
  const tagId = `app-cross-links-${reg.slug}`;

  return (
    <div className={styles.card} aria-labelledby={tagId}>
      <div className={styles.head}>
        <span className={styles.hex} aria-hidden="true">
          <AppGlyph app={app} className={styles.hexGlyph} />
        </span>
        <div>
          <p className={styles.eyebrow}>About this app</p>
          <h4 className={styles.title} id={tagId}>{reg.name}</h4>
        </div>
      </div>

      <ul className={styles.rows}>
        {rows.map((row) => (
          <li key={row.key} className={styles.row}>
            <a
              href={reg[row.hrefKey]}
              className={styles.rowLink}
              target={row.key === 'docs' ? '_blank' : undefined}
              rel={row.key === 'docs' ? 'noopener noreferrer' : undefined}
            >
              <span className={styles.rowIcon} aria-hidden="true">{ICONS[row.key]}</span>
              <span className={styles.rowText}>
                <span className={styles.rowLabel}>{row.label}</span>
                <span className={styles.rowHint}>{row.hint}</span>
              </span>
              <span className={styles.rowArrow} aria-hidden="true">→</span>
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function AppCrossLinks({
  apps = [],
  variant = 'inline',
  surface,
  className,
  heading,
}) {
  const known = apps.filter((slug) => APPS_REGISTRY[slug]);
  if (known.length === 0) return null;

  const composed = [
    styles.root,
    styles[`variant-${variant}`],
    className,
  ].filter(Boolean).join(' ');

  return (
    <aside className={composed} aria-label="Related app links">
      {heading && <h3 className={styles.heading}>{heading}</h3>}
      <div className={styles.list}>
        {known.map((slug) => (
          <AppCard key={slug} app={slug} surface={surface} variant={variant} />
        ))}
      </div>
    </aside>
  );
}
