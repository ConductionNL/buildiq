/**
 * <PartnerCard /> + <PartnerGrid />
 *
 * Partner card from preview/components/partner-cards.html.
 *
 * Three tiers (low → high):
 *   - host      (default, white panel + cobalt-50 tier pill) —
 *               ships our apps, no SLA with Conduction.
 *   - service   (white panel + gold tier pill) — SLA, third-line
 *               support, roadmap input.
 *   - certified (cobalt-fill inverse + white tier pill) — trained,
 *               joint roadmap, tender-eligible.
 *
 * Visual structure:
 *   ┌─────────────────────────────┐
 *   │ [logo plate]      TIER     │
 *   │ Name                        │
 *   │ Summary paragraph           │
 *   │ ──────────                  │
 *   │ ⬢ App  ⬢ App  ⬢ App         │  ← apps-used pills
 *   └─────────────────────────────┘
 *
 * The companion <BecomePartner/> card slots into the trailing grid
 * cell as the call-to-action.
 *
 * Usage:
 *
 *   <PartnerGrid>
 *     <PartnerCard
 *       href="/partners/yard"
 *       tier="host"
 *       name="YARD"
 *       logo="/img/partners/yard.png"
 *       summary={<>Digital design- en development-bureau uit Utrecht...</>}
 *       apps={['LaunchPad', 'OpenCatalogi']}
 *     />
 *     <BecomePartner href="/partners/become" />
 *   </PartnerGrid>
 */

import React from 'react';
import HexBullet from '../primitives/HexBullet';
import {appHrefByName} from '../../data/apps-registry';
import styles from './PartnerCard.module.css';

const TIER_LABELS = {host: 'Host', service: 'Service', certified: 'Certified'};

export function PartnerGrid({columns = 3, children, className}) {
  const composed = [styles.grid, styles['cols-' + columns], className].filter(Boolean).join(' ');
  return <div className={composed}>{children}</div>;
}

export default function PartnerCard({
  variant = 'full',
  href,
  tier = 'host',
  name,
  logo,
  logoAlt,
  summary,
  why,
  apps = [],
  className,
}) {
  /* Compact "other" variant: small mini-avatar (44x50 hex) on the
     left, name + why on the right. Used at the bottom of partner-
     detail pages to point to a handful of related partners. The
     mini-avatar gets a tier-coloured ring to echo the tier styling
     on the full card. */
  if (variant === 'other') {
    const composed = [
      styles.other,
      styles['tier-' + tier],
      className,
    ].filter(Boolean).join(' ');
    const Tag = href ? 'a' : 'div';
    return (
      <Tag href={href} className={composed}>
        {logo && (
          <div className={styles.miniAvatar}>
            <img src={logo} alt={logoAlt || name + ' logo'} />
          </div>
        )}
        <div>
          {name && <div className={styles.otherName}>{name}</div>}
          {(why || summary) && <div className={styles.otherWhy}>{why || summary}</div>}
        </div>
      </Tag>
    );
  }

  /* Default: full partner card.
     The card wrapper is always a <div> rather than an <a>, even when
     a partner detail href is set, so the per-app pills can be
     individually clickable links to /apps/<slug>. The card-wide click
     target is rendered as a stretched-link overlay (.cardLink) that
     covers the whole card via position:absolute; nested <a> pills
     sit on top via z-index, so clicks reach the pill instead of the
     overlay when they land on a pill, and reach the overlay otherwise. */
  const composed = [
    styles.card,
    styles['tier-' + tier],
    className,
  ].filter(Boolean).join(' ');
  const bulletColor = tier === 'certified' ? 'var(--c-orange-knvb)' : 'var(--c-blue-cobalt)';

  return (
    <div className={composed}>
      {href && (
        <a
          href={href}
          className={styles.cardLink}
          aria-label={name ? `${name} partner page` : 'Partner page'}
        />
      )}
      <span className={styles.tier}>{TIER_LABELS[tier] || tier}</span>
      {logo && (
        <div className={styles.avatar}>
          <img src={logo} alt={logoAlt || name + ' logo'} />
        </div>
      )}
      {name && <div className={styles.name}>{name}</div>}
      {summary && <div className={styles.summary}>{summary}</div>}
      {apps.length > 0 && (
        <div className={styles.apps}>
          {apps.map((app, i) => {
            const appUrl = appHrefByName(app);
            const inner = (
              <>
                <HexBullet size="sm" color={bulletColor} />
                {app}
              </>
            );
            return appUrl ? (
              <a
                key={app}
                href={appUrl}
                className={styles.appPill}
              >
                {inner}
              </a>
            ) : (
              <span key={app} className={styles.appPill}>{inner}</span>
            );
          })}
        </div>
      )}
    </div>
  );
}

export function BecomePartner({href, eyebrow = 'Become a partner', title, body, ctaLabel}) {
  return (
    <a href={href} className={[styles.card, styles.become].join(' ')}>
      <div className={styles.becomeEyebrow}>{eyebrow}</div>
      {title && <h4 className={styles.becomeTitle}>{title}</h4>}
      {body && <p className={styles.becomeBody}>{body}</p>}
      {ctaLabel && <span className={styles.becomeArrow}>{ctaLabel} →</span>}
    </a>
  );
}
