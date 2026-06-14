/**
 * Procest · Werkvoorraad widget.
 *
 * The case worker's queue, with stage pip on the left, case title in
 * the middle, owner avatar on the right. .now = active stage in
 * orange, .late = SLA breached in red.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function ProcestWerkvoorraad() {
  return (
    <div className={[styles.w, styles['w-werkvoorraad']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={[styles.item, styles.now].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={styles.av}></div></div>
        <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.b].join(' ')}></div></div>
        <div className={[styles.item, styles.late].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.c].join(' ')}></div></div>
        <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={styles.av}></div></div>
        <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.b].join(' ')}></div></div>
      </div>
    </div>
  );
}
