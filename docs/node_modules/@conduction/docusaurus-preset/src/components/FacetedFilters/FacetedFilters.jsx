/**
 * <FacetedFilters /> + <FilterChip />
 *
 * Sidebar facet panel from preview/components/faceted-filters.html.
 * Each facet is a labelled checkbox list with optional counts; clicking
 * an item toggles its selection. The companion <FilterChip/> renders
 * an active-chips strip above the results so users can pop selections
 * one by one.
 *
 * Controlled component: pass `selected` (object keyed by facet) and
 * `onChange` to manage state externally. For ad-hoc usage without a
 * parent state, the component falls back to internal state with the
 * same shape.
 *
 * Usage in MDX:
 *
 *   const [selected, setSelected] = useState({});
 *   const filtered = useMemo(() => filterSolutions(solutions, selected), [selected]);
 *
 *   <Section background="default">
 *     <div style={{display: 'grid', gridTemplateColumns: '240px 1fr', gap: 56}}>
 *       <FacetedFilters
 *         facets={[
 *           {key: 'sector', label: 'Sector', items: [
 *             {value: 'public', label: 'Publieke sector', count: 5},
 *             {value: 'mkb',    label: 'MKB',             count: 3},
 *             {value: 'health', label: 'Zorg',            count: 2},
 *           ]},
 *           {key: 'goal', label: 'Doel', items: [...]},
 *         ]}
 *         selected={selected}
 *         onChange={setSelected}
 *       />
 *       <SolutionGrid>{filtered.map(s => <SolutionCard {...s}/>)}</SolutionGrid>
 *     </div>
 *   </Section>
 */

import React, {useState, useCallback} from 'react';
import styles from './FacetedFilters.module.css';

export function FilterChip({label, onRemove, className}) {
  return (
    <span className={[styles.chip, className].filter(Boolean).join(' ')}>
      {label}
      {onRemove && (
        <button
          type="button"
          aria-label={`Remove ${label}`}
          className={styles.chipRemove}
          onClick={onRemove}
        >
          ×
        </button>
      )}
    </span>
  );
}

export default function FacetedFilters({
  facets = [],
  selected,
  onChange,
  clearLabel = 'Clear all filters',
  className,
}) {
  const isControlled = selected !== undefined && onChange !== undefined;
  const [internal, setInternal] = useState({});
  const value = isControlled ? selected : internal;
  const set = isControlled ? onChange : setInternal;

  const toggle = useCallback((facetKey, itemValue) => {
    set((prev) => {
      const current = prev[facetKey] || [];
      const next = current.includes(itemValue)
        ? current.filter(v => v !== itemValue)
        : [...current, itemValue];
      return {...prev, [facetKey]: next};
    });
  }, [set]);

  const clearAll = useCallback(() => set({}), [set]);

  const hasAny = Object.values(value).some(v => Array.isArray(v) && v.length > 0);

  return (
    <aside className={[styles.filters, className].filter(Boolean).join(' ')}>
      {facets.map((facet) => {
        const facetKey = facet.key || facet.label;
        const sel = value[facetKey] || [];
        return (
          <fieldset key={facetKey} className={styles.facet}>
            <h3 className={styles.facetTitle}>{facet.label}</h3>
            <ul className={styles.facetList}>
              {facet.items.map((item) => {
                const checked = sel.includes(item.value);
                const id = `f-${facetKey}-${item.value}`;
                return (
                  <li key={item.value}>
                    <input
                      type="checkbox"
                      id={id}
                      checked={checked}
                      onChange={() => toggle(facetKey, item.value)}
                    />
                    <label htmlFor={id}>{item.label}</label>
                    {typeof item.count === 'number' && (
                      <span className={styles.count}>{item.count}</span>
                    )}
                  </li>
                );
              })}
            </ul>
          </fieldset>
        );
      })}

      {hasAny && (
        <button type="button" className={styles.clearAll} onClick={clearAll}>
          {clearLabel}
        </button>
      )}
    </aside>
  );
}
