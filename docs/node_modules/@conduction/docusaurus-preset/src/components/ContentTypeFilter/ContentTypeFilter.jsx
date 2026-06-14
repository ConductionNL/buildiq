/**
 * <ContentTypeFilter />
 *
 * Top-of-page chip row that filters a feed by a single key. Defaults
 * to academy content types ("Blogs", "Guides", "Case studies", …) but
 * is reused on the academy landing as the product filter row by
 * passing a different `types` + `labels` map (see APP_LABELS in
 * @conduction/docusaurus-preset/data/apps-registry).
 *
 * "All" is the default chip and is always present. Driven by a query
 * parameter (`?type=` for content types, `?app=` for products) so the
 * filter survives reload and copy-paste.
 *
 * Mirrors the chip row in preview/components/academy.html.
 *
 * Modes:
 *   - controlled: pass `value` (string | null) and `onChange` (fn)
 *   - uncontrolled link mode: pass `hrefForType` (fn) to render <a>
 *     chips with hrefs and let the surrounding page re-render on
 *     navigation. Useful for static-site filtering via query string.
 *
 * Counts: pass `counts` keyed by type ({ blog: 18, guide: 9, ... })
 * to render a small monospaced count next to each label. Pass a number
 * to `allCount` for the "Everything" chip total. Counts are optional.
 *
 * Usage in MDX (uncontrolled, query-string driven):
 *
 *   <ContentTypeFilter
 *     value={type}
 *     hrefForType={(t) => t ? `?type=${t}` : '?'}
 *     counts={{ blog: 18, guide: 9, 'case-study': 6, webinar: 5, tutorial: 4 }}
 *   />
 *
 * Usage in JSX (controlled):
 *
 *   const [type, setType] = useState(null);
 *   <ContentTypeFilter value={type} onChange={setType} />
 *
 * Usage as a product filter (reuses the same component):
 *
 *   import {APP_LABELS, APP_SLUGS} from '@conduction/docusaurus-preset/data/apps-registry';
 *   <ContentTypeFilter
 *     value={app}
 *     onChange={setApp}
 *     types={APP_SLUGS}
 *     labels={APP_LABELS}
 *     counts={appCounts}
 *     allLabel="All apps"
 *     allCount={totalCount}
 *   />
 */

import React from 'react';
import {translate} from '@docusaurus/Translate';
import {
  CONTENT_TYPES,
  CONTENT_TYPE_PLURAL_LABELS,
} from './contentTypes';
import styles from './ContentTypeFilter.module.css';

const ALL = '__all__';

export default function ContentTypeFilter({
  value = null,
  onChange,
  hrefForType,
  counts,
  allLabel,
  allCount,
  types = CONTENT_TYPES,
  labels,
  className,
}) {
  const resolvedAllLabel = allLabel ?? translate({
    id: 'preset.contentTypeFilter.all',
    message: 'Everything',
    description: 'Default label on the "all" chip of the content type filter when no allLabel is passed',
  });
  const isLinkMode = typeof hrefForType === 'function';
  const labelFor = (key) => {
    if (labels && labels[key]) return labels[key];
    return CONTENT_TYPE_PLURAL_LABELS[key] || key;
  };

  const renderChip = (key, label, count) => {
    const active = (key === ALL && value == null) || key === value;
    const composed = [
      styles.chip,
      active ? styles.active : null,
    ].filter(Boolean).join(' ');

    if (isLinkMode) {
      return (
        <a
          key={key}
          href={hrefForType(key === ALL ? null : key)}
          className={composed}
        >
          <span className={styles.label}>{label}</span>
          {typeof count === 'number' && (
            <span className={styles.count}>{count}</span>
          )}
        </a>
      );
    }

    return (
      <button
        key={key}
        type="button"
        className={composed}
        onClick={() => onChange && onChange(key === ALL ? null : key)}
        aria-pressed={active}
      >
        <span className={styles.label}>{label}</span>
        {typeof count === 'number' && (
          <span className={styles.count}>{count}</span>
        )}
      </button>
    );
  };

  return (
    <div className={[styles.row, className].filter(Boolean).join(' ')}>
      {renderChip(ALL, resolvedAllLabel, allCount)}
      {types.map((t) =>
        renderChip(t, labelFor(t), counts && counts[t])
      )}
    </div>
  );
}

export {CONTENT_TYPES, CONTENT_TYPE_PLURAL_LABELS} from './contentTypes';
