/**
 * Procest · case sidebar, xWiki tab.
 *
 * The xWiki integration: Procest pulls the case-handling protocol
 * page out of xWiki and shows it next to the live case. Body reads
 * as wiki text: a heading, two paragraphs, a subheading, a list of
 * checked steps. Worth saying out loud since it is the integration
 * point most likely to surprise readers (xWiki under Nextcloud is
 * not a standard pairing).
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function ProcestXWiki() {
  return (
    <>
      <div className={[styles.row, styles.head].join(' ')}></div>
      <div className={styles.row}></div>
      <div className={styles.row}></div>
      <div className={[styles.row, styles.med].join(' ')}></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.row}></div>
      <div className={[styles.row, styles.med].join(' ')}></div>
      <div className={[styles.row, styles.short].join(' ')}></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.row}></div>
      <div className={styles.row}></div>
      <div className={[styles.row, styles.short].join(' ')}></div>
    </>
  );
}
