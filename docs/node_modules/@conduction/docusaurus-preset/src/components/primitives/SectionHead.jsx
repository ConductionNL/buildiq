/**
 * <SectionHead />
 *
 * The eyebrow + h2 + lede block used at the top of every section in
 * preview/pages/landing.html (.section-head). Splits left (eyebrow +
 * h2) from right (lede) on a 1.1fr/1fr grid, collapses to single
 * column under 900px.
 *
 * Mirrors .section-head from landing.html's per-page CSS, which
 * recurs in apps-catalog, solutions-catalog, support, partners,
 * about, etc.
 *
 * Usage:
 *
 *   <SectionHead
 *     eyebrow="Most installed"
 *     title="Three apps that ship the most outcomes."
 *     lede="Start with the apps that solve a concrete problem on day one."
 *   />
 */

import React from 'react';
import Eyebrow from './Eyebrow';
import styles from './SectionHead.module.css';

export default function SectionHead({eyebrow, title, lede, align = 'split', className}) {
  return (
    <div className={[styles.head, styles['align-' + align], className].filter(Boolean).join(' ')}>
      <div className={styles.copy}>
        {eyebrow && <Eyebrow>{eyebrow}</Eyebrow>}
        {title && <h2 className={styles.title}>{title}</h2>}
      </div>
      {lede && <p className={styles.lede}>{lede}</p>}
    </div>
  );
}
