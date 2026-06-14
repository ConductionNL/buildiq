/**
 * <StatsStrip />
 *
 * Four-up tile of headline numbers, sub-hero band on cobalt-50.
 * Each stat is { value, label }; label can be a string or ReactNode
 * (so MDX can pass <>line one<br/>line two</> for the two-line look).
 *
 * Mirrors preview/components/stats-strip.html.
 *
 * Usage in MDX:
 *
 *   <StatsStrip stats={[
 *     { value: '24', label: <>apps in the ecosystem,<br/>all open-source</> },
 *     { value: '2 min', label: 'install time' },
 *     { value: '€0', label: 'license fee, support is optional' },
 *     { value: 'EUPL', label: 'no vendor lock-in' },
 *   ]} />
 */

import React from 'react';
import styles from './StatsStrip.module.css';

export default function StatsStrip({stats = []}) {
  if (!stats.length) return null;

  return (
    <section className={styles.strip} aria-label="Key statistics">
      <div className={styles.inner} style={{'--stats-count': stats.length}}>
        {stats.map((stat, i) => (
          <div key={i} className={styles.stat}>
            <div className={styles.value}>{stat.value}</div>
            <div className={styles.label}>{stat.label}</div>
          </div>
        ))}
      </div>
    </section>
  );
}
