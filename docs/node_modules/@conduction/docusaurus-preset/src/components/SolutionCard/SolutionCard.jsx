/**
 * <SolutionCard /> + <SolutionGrid />
 *
 * Solution card from preview/components/solution-cards.html.
 *
 * A card represents a *solution*, never an app. The visual structure:
 *
 *   ┌─────────────────────────────┐
 *   │  ⬢ icon-hex     SECTOR-TAG  │  ← top row
 *   │                             │
 *   │  Title goes here.           │
 *   │  Outcome paragraph below.   │
 *   │  ──────────────             │  ← divider above pills
 *   │  ⬢ App  ⬢ App  ⬢ App        │  ← built-on pills
 *   └─────────────────────────────┘
 *
 * Sector tints the icon-hex (public=cobalt, mkb=cobalt-700, health=
 * orange) but everything else stays the same.
 *
 * Usage in MDX:
 *
 *   <SolutionGrid columns={2}>
 *     <SolutionCard
 *       href="/solutions/woo"
 *       sector="public"
 *       sectorLabel="Publieke sector"
 *       icon={<svg>...</svg>}
 *       title="WOO compliance, by Friday."
 *       outcome={<>A live WOO portal at your <span className="next-blue">Nextcloud</span>-hosted domain.</>}
 *       builtOn={['OpenCatalogi', 'OpenRegister', 'OpenConnector']}
 *     />
 *   </SolutionGrid>
 */

import React from 'react';
import Pill from '../primitives/Pill';
import styles from './SolutionCard.module.css';

const SECTOR_DEFAULT_LABELS = {
  public: 'Publieke sector',
  mkb: 'MKB',
  health: 'Zorg',
};

export function SolutionGrid({columns = 2, children, className}) {
  const classes = [styles.grid, styles['cols-' + columns], className].filter(Boolean).join(' ');
  return <div className={classes}>{children}</div>;
}

export default function SolutionCard({
  href,
  sector = 'public',
  sectorLabel,
  icon,
  title,
  outcome,
  builtOn = [],
  className,
}) {
  const composed = [styles.card, styles['sector-' + sector], className].filter(Boolean).join(' ');
  const Tag = href ? 'a' : 'div';
  return (
    <Tag href={href} className={composed}>
      <div className={styles.top}>
        <div className={styles.iconHex}>{icon}</div>
        <span className={styles.sectorTag}>
          {sectorLabel || SECTOR_DEFAULT_LABELS[sector] || sector}
        </span>
      </div>

      {title && <h3 className={styles.title}>{title}</h3>}
      {outcome && <p className={styles.outcome}>{outcome}</p>}

      {builtOn.length > 0 && (
        <div className={styles.builtOn}>
          {builtOn.map((app, i) => (
            <Pill key={i} bullet bulletColor="var(--c-blue-cobalt)">{app}</Pill>
          ))}
        </div>
      )}
    </Tag>
  );
}
