/**
 * Nextcloud · Important mail widget (stock, framed for context).
 *
 * Avatar list with two text lines (sender + subject). Class colour
 * variants on .item give the avatar palette mix. We frame this as
 * "the surface Conduction widgets sit next to" so MKB readers see
 * the integration point clearly.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function NextcloudMail() {
  return (
    <div className={[styles.w, styles['w-mail']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={styles.item}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={[styles.item, styles.b].join(' ')}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={[styles.item, styles.c].join(' ')}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={[styles.item, styles.d].join(' ')}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
        <div className={styles.item}><div className={styles.av}></div><div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div></div>
      </div>
    </div>
  );
}
