/**
 * Nextcloud · Calendar widget (stock, framed for context).
 *
 * 7-column day grid covering four weeks. .today = orange (the
 * dashboard's "right now"), .event = cobalt-300 dot, .muted = leading
 * or trailing days from neighbouring months.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

const DAYS = [
  2, 2, 1, 0, 0, 0, 2,
  0, 0, 0, 3, 1, 0, 0,
  0, 1, 0, 0, 0, 1, 0,
  0, 0, 0, 0, 1, 0, 0,
];

export default function NextcloudCalendar() {
  return (
    <div className={[styles.w, styles['w-calendar']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.grid}>
        {DAYS.map((kind, i) => (
          <div key={i} className={[
            styles.day,
            kind === 1 && styles.event,
            kind === 2 && styles.muted,
            kind === 3 && styles.today,
          ].filter(Boolean).join(' ')}></div>
        ))}
      </div>
    </div>
  );
}
