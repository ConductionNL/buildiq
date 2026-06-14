/**
 * PipelinQ · Deals closing widget.
 *
 * Deals expected to close this week, ranked by value. Reuses .w-mail
 * atom (avatar list with two lines): the avatar reads as the assigned
 * sales rep, l1 is the deal name, l2 is value plus stage.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function PipelinQDeals() {
  return (
    <div className={[styles.w, styles['w-mail']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={[styles.item, styles.b].join(' ')}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={[styles.item, styles.c].join(' ')}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={styles.item}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={[styles.item, styles.d].join(' ')}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
      </div>
    </div>
  );
}
