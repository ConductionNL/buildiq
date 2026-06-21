/**
 * DeciDesk · decision sidebar, Detail tab.
 *
 * Body of a single decision: title, decision summary paragraph, then
 * structured fields (date, source meeting, decision-maker) and an
 * action-items section showing who owns the follow-up.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function DeciDeskDecision() {
  return (
    <>
      <div className={[styles.row, styles.head].join(' ')}></div>
      <div className={styles.row}></div>
      <div className={[styles.row, styles.med].join(' ')}></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.smPerson}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.b, styles.pending].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
    </>
  );
}
