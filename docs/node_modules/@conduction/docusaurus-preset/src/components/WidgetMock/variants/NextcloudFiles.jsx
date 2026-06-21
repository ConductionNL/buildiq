/**
 * Nextcloud · Recent files widget (stock, framed for context).
 *
 * File list with type-coloured icons: .folder = orange, .doc =
 * terracotta, .sheet = forest. Last item plain (image / unknown).
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function NextcloudFiles() {
  return (
    <div className={[styles.w, styles['w-files']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={[styles.item, styles.folder].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
        <div className={[styles.item, styles.doc].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
        <div className={[styles.item, styles.sheet].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
        <div className={[styles.item, styles.doc].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
        <div className={styles.item}><div className={styles.ico}></div><div className={styles.l}></div></div>
      </div>
    </div>
  );
}
