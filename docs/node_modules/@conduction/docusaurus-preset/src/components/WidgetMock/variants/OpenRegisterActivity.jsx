/**
 * OpenRegister · Activity widget.
 *
 * Read and write rate over the past N hours, drawn as a line chart.
 * The dot at the end is "now". Reuses .w-graph-line.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function OpenRegisterActivity() {
  return (
    <div className={[styles.w, styles['w-graph-line']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.chart}>
        <svg viewBox="0 0 100 40" preserveAspectRatio="none">
          <path className={styles.fill}   d="M0,30 L12,28 L24,32 L36,22 L48,26 L60,16 L72,18 L84,10 L100,14 L100,40 L0,40 Z"/>
          <path className={styles.stroke} d="M0,30 L12,28 L24,32 L36,22 L48,26 L60,16 L72,18 L84,10 L100,14"/>
          <circle className={styles.dot} cx="100" cy="14" r="2.5"/>
        </svg>
      </div>
      <div className={styles.axis}></div>
    </div>
  );
}
