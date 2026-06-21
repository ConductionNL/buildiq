/**
 * <FeatureGrid /> + <FeatureGridGroup /> + <FeatureItem />
 *
 * Compact multi-column list for spec-grade features. Use when an app
 * has 10 to 60+ features that don't all warrant a full USP block.
 * Each item shows a status hex + a short label; the full description
 * reveals on hover and on keyboard focus.
 *
 * Mirrors preview/components/feature-grid.html exactly (auto-fill grid
 * with min 240px columns, bottom-up tooltip with 280px max-width and
 * a tail).
 *
 * Status modifiers on the hex bullet:
 *   - 'stable'  (default mint)
 *   - 'beta'    (orange)
 *   - 'soon'    (cobalt-300, "coming soon")
 *
 * Optional <FeatureGridGroup label="..."> headers split the grid into
 * named sections; sites can also pass an items array with `group: 'X'`
 * entries that the component will section automatically.
 *
 * Usage in MDX:
 *
 *   <FeatureGrid
 *     legend
 *     items={[
 *       {group: 'Core capabilities'},
 *       {label: 'JSON Schema validation', tip: 'Define a register shape...', status: 'stable'},
 *       {label: 'GraphQL endpoint',       tip: 'Auto-generated...',          status: 'stable'},
 *       {label: 'Schema versioning',      tip: 'Migrations are version-stable.', status: 'beta'},
 *       {group: 'Integrations'},
 *       {label: 'OpenCatalogi indexing',  tip: '...',                        status: 'stable'},
 *     ]}
 *   />
 *
 * Or compose with children (full MDX flexibility):
 *
 *   <FeatureGrid>
 *     <FeatureGridGroup label="Core capabilities" />
 *     <FeatureItem label="JSON Schema validation" tip="..." status="stable" />
 *     ...
 *   </FeatureGrid>
 */

import React from 'react';
import styles from './FeatureGrid.module.css';

const STATUS_CLASSES = {stable: '', beta: styles.beta, soon: styles.soon};

export function FeatureItem({label, tip, status = 'stable', href, className}) {
  const hexClass = [styles.h, STATUS_CLASSES[status]].filter(Boolean).join(' ');
  const body = (
    <>
      <span className={hexClass} aria-hidden="true" />
      <span className={styles.label}>{label}</span>
      {tip && <span className={styles.tip}>{tip}</span>}
    </>
  );
  if (href) {
    const isExternal = /^https?:\/\//.test(href);
    return (
      <a
        className={[styles.item, styles.itemLink, className].filter(Boolean).join(' ')}
        href={href}
        title={tip || label}
        {...(isExternal ? {target: '_blank', rel: 'noopener noreferrer'} : {})}
      >
        {body}
      </a>
    );
  }
  return (
    <div
      className={[styles.item, className].filter(Boolean).join(' ')}
      tabIndex={0}
      title={tip || label}
    >
      {body}
    </div>
  );
}

export function FeatureGridGroup({label, className}) {
  return <h4 className={[styles.group, className].filter(Boolean).join(' ')}>{label}</h4>;
}

export default function FeatureGrid({items, legend = false, children, className}) {
  return (
    <div className={className}>
      {legend && <Legend />}
      <div className={styles.grid}>
        {items
          ? items.map((it, i) => (
              it.group
                ? <FeatureGridGroup key={i} label={it.group} />
                : <FeatureItem key={i} {...it} />
            ))
          : children}
      </div>
    </div>
  );
}

function Legend() {
  return (
    <div className={styles.legend}>
      <span className={styles.legendItem}><span className={styles.h} aria-hidden="true" />Stable</span>
      <span className={styles.legendItem}><span className={[styles.h, styles.beta].join(' ')} aria-hidden="true" />Beta</span>
      <span className={styles.legendItem}><span className={[styles.h, styles.soon].join(' ')} aria-hidden="true" />Coming soon</span>
    </div>
  );
}
