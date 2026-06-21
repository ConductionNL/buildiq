/**
 * Procest · case sidebar, Timeline tab.
 *
 * The case stage history vertical, with one done, one active in
 * orange, three to-do. Same .now / .late / .todo modifiers as the
 * AppMock procest timeline, just stacked instead of horizontal.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function ProcestTimeline() {
  return (
    <>
      <div className={styles.smStage}>
        <div className={styles.h}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={styles.smStage}>
        <div className={styles.h}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smStage, styles.now].join(' ')}>
        <div className={styles.h}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smStage, styles.todo].join(' ')}>
        <div className={styles.h}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smStage, styles.todo].join(' ')}>
        <div className={styles.h}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smStage, styles.todo].join(' ')}>
        <div className={styles.h}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
    </>
  );
}
