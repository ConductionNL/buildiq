/**
 * <AppsGrid />
 *
 * Filterable apps grid: a row of category chips above a 3-up grid of
 * <AppCard/> tiles. Mirrors preview/components/apps-grid.html. Each
 * app declares one or more categories; clicking a chip narrows the
 * grid to apps in that category. The first chip is always "All".
 *
 * For non-interactive 3-up rows (e.g. "Most installed" on landing.html),
 * use <AppsPreview/> instead. AppsGrid is the catalogue surface.
 *
 * Usage in MDX:
 *
 *   <AppsGrid
 *     categories={['All', 'Data', 'Processes', 'Connectors', 'Documents', 'AI']}
 *     apps={[
 *       {
 *         name: 'OpenRegister',
 *         tagline: 'Schemas, registers, structured data objects.',
 *         status: 'STABLE', version: 'v3.1',
 *         href: '/apps/openregister',
 *         icon: <svg>...</svg>,
 *         categories: ['Data'],
 *       },
 *       // ...
 *     ]}
 *   />
 */

import React, {useState, useMemo} from 'react';
import {AppCard} from '../AppsPreview/AppsPreview';
import styles from './AppsGrid.module.css';

const ALL_KEY = 'All';

export default function AppsGrid({categories, apps = [], className}) {
  const cats = categories && categories.length > 0
    ? categories
    : [ALL_KEY, ...uniqueCategories(apps)];

  const [active, setActive] = useState(cats[0] || ALL_KEY);

  const visible = useMemo(() => {
    if (!active || active === ALL_KEY) return apps;
    return apps.filter(a => Array.isArray(a.categories) && a.categories.includes(active));
  }, [apps, active]);

  return (
    <div className={[styles.section, className].filter(Boolean).join(' ')}>
      <div className={styles.chips} role="tablist" aria-label="Filter apps by category">
        {cats.map((c, i) => (
          <button
            key={i}
            type="button"
            role="tab"
            aria-selected={c === active}
            className={[styles.chip, c === active ? styles.chipActive : null].filter(Boolean).join(' ')}
            onClick={() => setActive(c)}
          >
            {c}
          </button>
        ))}
      </div>

      <div className={styles.grid}>
        {visible.map((app, i) => <AppCard key={i} app={app} />)}
      </div>

      {visible.length === 0 && (
        <p className={styles.empty}>No apps match this filter.</p>
      )}
    </div>
  );
}

function uniqueCategories(apps) {
  const seen = new Set();
  for (const a of apps) {
    if (Array.isArray(a.categories)) {
      for (const c of a.categories) seen.add(c);
    }
  }
  return Array.from(seen);
}
