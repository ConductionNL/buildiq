/**
 * DeciDesk · Action items widget.
 *
 * Action items assigned to the viewer, sorted by due date. .w-jira
 * atom: id slot is the source decision, pip slot is status (orange =
 * due this week, red = overdue, mint = done, cobalt-200 = upcoming).
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function DeciDeskActions() {
  return (
    <div className={[styles.w, styles['w-jira']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={[styles.item, styles.blocked].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.todo].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
      </div>
    </div>
  );
}
