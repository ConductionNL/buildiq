/**
 * DocuDesk · Pending signatures widget.
 *
 * Documents waiting on someone's signature, ranked by oldest. Reuses
 * the .w-jira atom (id + title + status pip): id slot reads as a
 * document type tag, pip slot reads as a status (orange = waiting,
 * red = blocked, mint = done in the last day, cobalt-200 = no action).
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function DocuDeskPendingSign() {
  return (
    <div className={[styles.w, styles['w-jira']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.blocked].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.todo].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
      </div>
    </div>
  );
}
