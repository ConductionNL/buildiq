/**
 * DocuDesk · document sidebar, Signatures tab.
 *
 * List of recipients with status pip: signed (mint), pending (orange),
 * blocked / declined (red). Avatar colour mix matches AppMock palette.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function DocuDeskSignatures() {
  return (
    <>
      <div className={[styles.row, styles.head].join(' ')}></div>
      <div className={[styles.row, styles.short].join(' ')}></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smPerson}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.b].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.c, styles.pending].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.d, styles.pending].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.e, styles.blocked].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
    </>
  );
}
