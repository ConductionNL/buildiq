/**
 * <HexCard />
 *
 * Tinted academy/tutorial panel with a top-left hex badge holding an
 * icon. The shared shell behind <ContactCta />, <Outcomes />, and
 * <Prerequisites /> — all three were rebuilt in isolation around the
 * same cobalt-50 surface, so this primitive consolidates the surface,
 * border, padding, and badge slot in one place.
 *
 * Anatomy:
 *   ┌──────────────────────────────────────────┐
 *   │ ⬢  Title                       [actions] │
 *   │    body / list / paragraph               │
 *   └──────────────────────────────────────────┘
 *
 * The badge is a <HexThumbnail size="sm" tone="cobalt-deep"> with a
 * white glyph. Cobalt + white keeps the orange accent budget free for
 * the orange checkmark/bullet glyphs inside <Outcomes /> and
 * <Prerequisites />, and for the orange CTA arrow in <ContactCta />.
 *
 * Usage:
 *
 *   <HexCard title="Wat je leert" icon="lightbulb">
 *     <ul>...</ul>
 *   </HexCard>
 *
 *   <HexCard
 *     title="Wil je meer weten?"
 *     icon="mail"
 *     decoration
 *     actions={<a className="…">Mail ons →</a>}
 *   >
 *     <p>Mail ons. We helpen je…</p>
 *   </HexCard>
 *
 * Mirrors preview/components/hex-card.html.
 */

import React from 'react';
import HexThumbnail from '../primitives/HexThumbnail';
import styles from './HexCard.module.css';

const ICONS = {
  mail: (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="3" y="5" width="18" height="14" rx="2"/>
      <path d="M3 7l9 7 9-7"/>
    </svg>
  ),
  lightbulb: (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M9 18h6"/>
      <path d="M10 21h4"/>
      <path d="M12 3a6 6 0 0 0-4 10.5c.7.8 1 1.6 1 2.5v1h6v-1c0-.9.3-1.7 1-2.5A6 6 0 0 0 12 3z"/>
    </svg>
  ),
  clipboard: (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="6" y="4" width="12" height="17" rx="2"/>
      <rect x="9" y="2" width="6" height="4" rx="1"/>
      <path d="M9 11h6"/>
      <path d="M9 15h6"/>
    </svg>
  ),
};

export default function HexCard({
  title,
  icon,
  iconNode,
  decoration = false,
  actions,
  children,
  className,
  as: Tag = 'aside',
}) {
  const composed = [
    styles.card,
    decoration && styles.hasDecoration,
    actions && styles.hasActions,
    className,
  ].filter(Boolean).join(' ');

  const glyph = iconNode || (icon && ICONS[icon]) || null;

  return (
    <Tag className={composed}>
      {decoration && (
        <span className={styles.decoFrame} aria-hidden="true">
          <span className={`${styles.deco} ${styles.deco1}`} />
          <span className={`${styles.deco} ${styles.deco2}`} />
          <span className={`${styles.deco} ${styles.deco3}`} />
        </span>
      )}

      {glyph && (
        <span className={styles.badge} aria-hidden="true">
          <HexThumbnail size="sm" tone="cobalt-deep">{glyph}</HexThumbnail>
        </span>
      )}

      <div className={styles.head}>
        {title && <h3 className={styles.title}>{title}</h3>}
      </div>

      <div className={styles.body}>
        {children}
      </div>

      {actions && (
        <div className={styles.actions}>{actions}</div>
      )}
    </Tag>
  );
}
