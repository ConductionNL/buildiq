/**
 * <PartnerSidecard />
 *
 * Narrow info panel for the partner detail page right column. Three
 * stacked sections:
 *   1. Tier badge.       Pulls the same colour treatment as <PartnerCard>
 *                        (Host = cobalt-50 pill, Service = gold pill,
 *                        Certified = white pill on a cobalt-fill card,
 *                        with a small gold Conduction avatar to read as
 *                        a Conduction-issued credential).
 *   2. Apps supported.   Chip row from partner.apps.
 *   3. Solutions shipped. Chip row from solutionsBySlugs(partner.solutions),
 *                        each linking to its /solutions/<slug> page.
 *
 * Optional `cta` renders a primary button at the bottom of the card —
 * usually the same "Talk to {partner}" target as the hero's primary CTA,
 * so the rail is self-sufficient when the user scrolls past the hero.
 *
 * The card itself doesn't position-stick. The consuming page wraps a
 * 2-col layout (main + rail) and applies the sticky behaviour to the
 * column wrapper, so the rail starts after the hero and trails the
 * scrolling body content.
 *
 * Usage:
 *
 *   import {PARTNERS} from '@site/src/data/partners-catalog';
 *   import {solutionsBySlugs} from '@site/src/data/solutions-catalog';
 *
 *   const partner = PARTNERS.find(p => p.name === 'Acato');
 *   const solutions = solutionsBySlugs(partner.solutions);
 *
 *   <PartnerSidecard
 *     partner={partner}
 *     solutions={solutions}
 *     cta={{label: 'Talk to Acato', href: 'mailto:hello@acato.nl'}}
 *   />
 */

import React from 'react';
import {appHrefByName} from '../../data/apps-registry';
import styles from './PartnerSidecard.module.css';

const TIER_LABELS = {
  host: 'Host',
  service: 'Service',
  certified: 'Certified',
};

const TIER_BLURB = {
  host:
    'Host partners ship our open-source apps to their customers. They sell, host, and resell Nextcloud-supported variants through their own channels. No SLA with Conduction, no direct line to our support, not eligible to respond to tenders with our products.',
  service:
    'Service partners hold an SLA with Conduction. Their team can call us directly for third-line support, with named contacts and input on bug priority and feature requests. They run rollouts; we back them up.',
  certified:
    'Certified partners are trained, audited, and on the joint roadmap with Conduction. Eligible to co-sale on public tenders with our products, and the Conduction-credential mark on their own collateral.',
};

export default function PartnerSidecard({
  partner,
  solutions = [],
  cta,
  className,
}) {
  if (!partner) return null;
  const tier = partner.tier || 'host';
  const tierLabel = TIER_LABELS[tier] || tier;
  const composed = [styles.rail, styles[`tier-${tier}`], className].filter(Boolean).join(' ');

  return (
    <aside className={composed}>
      <div className={styles.tierCard}>
        {tier === 'certified' && (
          <img
            className={styles.tierBadge}
            src="/img/brand/avatar-conduction-gold.svg"
            alt="Conduction-certified mark"
            width="44"
            height="44"
          />
        )}
        <div className={styles.tierLabel}>{`${tierLabel} partner`}</div>
        <p className={styles.tierBlurb}>{TIER_BLURB[tier]}</p>
      </div>

      {partner.apps && partner.apps.length > 0 && (
        <div className={styles.section}>
          <h4 className={styles.sectionTitle}>Apps they ship</h4>
          <div className={styles.chipRow}>
            {partner.apps.map((app) => {
              const url = appHrefByName(app);
              return url ? (
                <a key={app} href={url} className={styles.chip}>{app}</a>
              ) : (
                <span key={app} className={styles.chip}>{app}</span>
              );
            })}
          </div>
        </div>
      )}

      {solutions.length > 0 && (
        <div className={styles.section}>
          <h4 className={styles.sectionTitle}>Solutions they deliver</h4>
          <div className={styles.chipColumn}>
            {solutions.map((s) => (
              <a key={s.slug} href={s.href} className={styles.solutionLink}>
                <span className={styles.solutionIcon} aria-hidden="true">{s.icon}</span>
                <span className={styles.solutionTitle}>{s.shortTitle || s.title}</span>
              </a>
            ))}
          </div>
        </div>
      )}

      {cta && (
        <a href={cta.href} className={styles.cta}>
          {cta.label}
        </a>
      )}
    </aside>
  );
}
