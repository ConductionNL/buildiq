/**
 * OpenConnector · Recent runs widget.
 *
 * Last few integration runs with status pip (mint = success, orange =
 * partial, red = failed, cobalt-200 = scheduled). Reuses .w-jira.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function OpenConnectorRuns() {
  return (
    <div className={[styles.w, styles['w-jira']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.blocked].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.todo].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
      </div>
    </div>
  );
}
