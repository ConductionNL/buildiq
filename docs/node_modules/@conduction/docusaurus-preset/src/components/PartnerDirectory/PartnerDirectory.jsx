/**
 * <PartnerDirectory />
 *
 * Filterable partner directory: composes <FacetedFilters/> (sidebar),
 * <PartnerGrid/>, <PartnerCard/>, and an optional <BecomePartner/> CTA.
 *
 * Facets are auto-derived from the partners array:
 *   - tier:     Certified / Service / Host (only shown when ≥1 partner)
 *   - offering: union of every partners[].apps[] AND .solutions[]
 *               value, sorted by count. Solutions get pretty labels;
 *               unknown slugs fall back to the slug itself.
 *
 * Filter logic: a partner is shown when it matches every active facet.
 * Within a facet, multiple selections are OR-ed (any-of). Across
 * facets, selections are AND-ed (all-of). The offering facet matches
 * against the union of the partner's apps and solutions, so picking
 * "Woo" shows every partner that ships the Woo solution.
 *
 * Usage in MDX:
 *
 *   <PartnerDirectory
 *     partners={[
 *       {href: '/partners/acato', tier: 'certified', name: 'Acato',
 *        logo: '/img/partners/acato.png',
 *        summary: <>Digital agency uit Almere…</>,
 *        apps: ['OpenCatalogi', 'OpenRegister', 'OpenConnector'],
 *        solutions: ['woo']},
 *       …
 *     ]}
 *     becomePartner={{
 *       href: '#become-a-partner',
 *       title: 'Ship Conduction to your customers.',
 *       body:  'Three tiers: Host, Service, Certified.',
 *       ctaLabel: 'Apply below',
 *     }}
 *   />
 */

import React, {useState, useMemo} from 'react';
import FacetedFilters from '../FacetedFilters/FacetedFilters';
import PartnerCard, {PartnerGrid, BecomePartner} from '../PartnerCard/PartnerCard';
import styles from './PartnerDirectory.module.css';

const TIER_LABELS = {host: 'Host', service: 'Service', certified: 'Certified'};
const TIER_ORDER = ['certified', 'service', 'host'];

// Pretty labels for solution slugs that the catalog uses. Anything not
// in this map falls back to the raw slug, so a new solution keeps
// working without an explicit registration.
const SOLUTION_LABELS = {
  woo: 'Woo',
  archief: 'Archief',
  'software-catalog': 'Software catalog',
  'mkb-workspace': 'MKB workspace',
  zaakafhandeling: 'Zaakafhandeling',
  'legacy-erp': 'Legacy ERP',
  'nen-7510': 'NEN 7510',
};

function deriveFacets(partners) {
  const tierItems = TIER_ORDER
    .map(value => ({
      value,
      label: TIER_LABELS[value],
      count: partners.filter(p => p.tier === value).length,
    }))
    .filter(item => item.count > 0);

  // Combined offering facet: apps + solutions. Each entry records its
  // kind so the filter can match against the right partner field.
  // Items are stored under a stable value namespaced by kind
  // ('app:OpenCatalogi', 'solution:woo') so an app and a solution
  // with the same name never collide.
  const offeringCounts = new Map(); // value → {label, count, kind, raw}
  const bump = (kind, raw, label) => {
    const value = `${kind}:${raw}`;
    const existing = offeringCounts.get(value);
    if (existing) existing.count += 1;
    else offeringCounts.set(value, {value, label, count: 1, kind, raw});
  };
  for (const p of partners) {
    for (const a of p.apps || []) bump('app', a, a);
    for (const s of p.solutions || []) bump('solution', s, SOLUTION_LABELS[s] || s);
  }
  const offeringItems = Array.from(offeringCounts.values())
    .sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
    .map(({value, label, count}) => ({value, label, count}));

  const facets = [];
  if (tierItems.length     > 0) facets.push({key: 'tier',     label: 'Partner tier',       items: tierItems});
  if (offeringItems.length > 0) facets.push({key: 'offering', label: 'Apps and solutions', items: offeringItems});
  return facets;
}

function partnerMatches(partner, selected) {
  const tierFilter = selected.tier || [];
  if (tierFilter.length > 0 && !tierFilter.includes(partner.tier)) return false;

  const offeringFilter = selected.offering || [];
  if (offeringFilter.length > 0) {
    const partnerApps = partner.apps || [];
    const partnerSolutions = partner.solutions || [];
    const ok = offeringFilter.some(value => {
      const [kind, raw] = value.split(':');
      if (kind === 'app') return partnerApps.includes(raw);
      if (kind === 'solution') return partnerSolutions.includes(raw);
      return false;
    });
    if (!ok) return false;
  }

  return true;
}

export default function PartnerDirectory({
  partners = [],
  becomePartner,
  gridColumns = 2,
  emptyState = 'No partners match the current filters.',
  className,
}) {
  const [selected, setSelected] = useState({});
  const facets = useMemo(() => deriveFacets(partners), [partners]);
  const filtered = useMemo(
    () => partners.filter(p => partnerMatches(p, selected)),
    [partners, selected],
  );

  return (
    <div className={[styles.layout, className].filter(Boolean).join(' ')}>
      <FacetedFilters
        facets={facets}
        selected={selected}
        onChange={setSelected}
      />
      <div className={styles.results}>
        {filtered.length > 0 ? (
          <PartnerGrid columns={gridColumns}>
            {filtered.map((p, i) => (
              <PartnerCard key={p.href || p.name || i} {...p} />
            ))}
            {becomePartner && <BecomePartner {...becomePartner} />}
          </PartnerGrid>
        ) : (
          <>
            <p className={styles.empty}>{emptyState}</p>
            {becomePartner && (
              <PartnerGrid columns={gridColumns}>
                <BecomePartner {...becomePartner} />
              </PartnerGrid>
            )}
          </>
        )}
      </div>
    </div>
  );
}
