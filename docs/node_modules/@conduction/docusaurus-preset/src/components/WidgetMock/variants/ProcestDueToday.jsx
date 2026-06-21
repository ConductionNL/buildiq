/**
 * Procest · Due today widget.
 *
 * Cases whose deadline lands today or earlier. Reuses .w-werkvoorraad
 * but with more late items in red and one orange-active to read as
 * "if you do nothing, this list grows red". Different framing from
 * the regular werkvoorraad widget which is queue-shaped, not
 * deadline-shaped.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function ProcestDueToday() {
  return (
    <div className={[styles.w, styles['w-werkvoorraad']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={[styles.item, styles.late].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.b].join(' ')}></div></div>
        <div className={[styles.item, styles.late].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={styles.av}></div></div>
        <div className={[styles.item, styles.now].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.c].join(' ')}></div></div>
        <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.b].join(' ')}></div></div>
      </div>
    </div>
  );
}
