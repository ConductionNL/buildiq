/**
 * OpenCatalogi · publication sidebar, History tab.
 *
 * Version history of a publication. Each row is a person who made
 * the edit (avatar) plus the version label and timestamp. Latest
 * (mint pip) at the top, withdrawn version (red pip) further down.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function OpenCatalogiPublicationHistory() {
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
      <div className={[styles.smPerson, styles.d].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.c, styles.blocked].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
      <div className={[styles.smPerson, styles.e].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
        <div className={styles.pip}></div>
      </div>
    </>
  );
}
