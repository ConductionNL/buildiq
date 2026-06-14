/**
 * Nextcloud · stock activity feed.
 *
 * Every Nextcloud sidebar's first tab. Shown so MKB readers can
 * place where Conduction tabs sit. List of person + action rows
 * with avatars in the family palette mix.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function NextcloudActivity() {
  return (
    <>
      <div className={styles.smPerson}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smPerson, styles.b].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smPerson, styles.c].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smPerson, styles.d].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={styles.smPerson}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smPerson, styles.e].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
      <div className={[styles.smPerson, styles.b].join(' ')}>
        <div className={styles.av}></div>
        <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
      </div>
    </>
  );
}
