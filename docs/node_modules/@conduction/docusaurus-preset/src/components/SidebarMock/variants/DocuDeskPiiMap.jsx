/**
 * DocuDesk · document sidebar, PII map tab.
 *
 * Inline pills marking redacted spans (red) and suggested redactions
 * (orange) within paragraphs. Reads as "here is what got covered up".
 * Below: a key/value list of PII categories detected (BSN, IBAN, etc.)
 * with counts.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function DocuDeskPiiMap() {
  return (
    <>
      <div className={[styles.row, styles.head].join(' ')}></div>
      <div className={styles.smPiiBlock}>
        <div className={styles.smPii} style={{ width: 30 }}></div>
        <div className={[styles.smPii, styles.redacted].join(' ')} style={{ width: 22 }}></div>
        <div className={styles.smPii} style={{ width: 40 }}></div>
        <div className={[styles.smPii, styles.suggested].join(' ')} style={{ width: 18 }}></div>
        <div className={styles.smPii} style={{ width: 26 }}></div>
        <div className={[styles.smPii, styles.redacted].join(' ')} style={{ width: 30 }}></div>
        <div className={styles.smPii} style={{ width: 16 }}></div>
        <div className={[styles.smPii, styles.suggested].join(' ')} style={{ width: 34 }}></div>
        <div className={styles.smPii} style={{ width: 22 }}></div>
      </div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
    </>
  );
}
