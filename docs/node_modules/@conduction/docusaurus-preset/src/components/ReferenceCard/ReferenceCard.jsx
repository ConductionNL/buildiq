/**
 * <ReferenceCard /> + <ReferenceGrid />
 *
 * Customer-reference card from preview/components/reference-cards.html.
 * Used on partner detail pages and the /references section.
 *
 * Visual structure:
 *   ┌─────────────────────────────┐
 *   │ [SECTOR-TAG]                │
 *   │ Customer name               │
 *   │ What we did paragraph       │
 *   │ ──────────                  │
 *   │ ⬢ App  ⬢ App  ⬢ App         │
 *   └─────────────────────────────┘
 */

import React from 'react';
import HexBullet from '../primitives/HexBullet';
import styles from './ReferenceCard.module.css';

export function ReferenceGrid({columns = 3, children, className}) {
  const composed = [styles.grid, styles['cols-' + columns], className].filter(Boolean).join(' ');
  return <div className={composed}>{children}</div>;
}

export default function ReferenceCard({sector, name, what, stack = [], className}) {
  return (
    <div className={[styles.card, className].filter(Boolean).join(' ')}>
      {sector && <span className={styles.sectorTag}>{sector}</span>}
      {name && <h4 className={styles.name}>{name}</h4>}
      {what && <p className={styles.what}>{what}</p>}
      {stack.length > 0 && (
        <div className={styles.stack}>
          {stack.map((app, i) => (
            <span key={i} className={styles.pill}>
              <HexBullet size="sm" color="var(--c-blue-cobalt)" />
              {app}
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
