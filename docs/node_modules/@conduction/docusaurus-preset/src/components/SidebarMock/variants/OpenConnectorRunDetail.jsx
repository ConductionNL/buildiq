/**
 * OpenConnector · run sidebar, Logs tab.
 *
 * Single integration run inspected: header with run id and timing,
 * then a vertical log stream with severity-coded timestamps. Last
 * line is a warn or error so the eye lands on it.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function OpenConnectorRunDetail() {
  return (
    <>
      <div className={[styles.row, styles.head].join(' ')}></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.smLog}><div className={styles.ts}></div><div className={styles.msg}></div></div>
      <div className={styles.smLog}><div className={styles.ts}></div><div className={styles.msg}></div></div>
      <div className={styles.smLog}><div className={styles.ts}></div><div className={styles.msg}></div></div>
      <div className={[styles.smLog, styles.warn].join(' ')}><div className={styles.ts}></div><div className={styles.msg}></div></div>
      <div className={styles.smLog}><div className={styles.ts}></div><div className={styles.msg}></div></div>
      <div className={[styles.smLog, styles.error].join(' ')}><div className={styles.ts}></div><div className={styles.msg}></div></div>
    </>
  );
}
