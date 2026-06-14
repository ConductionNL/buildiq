/**
 * OpenCatalogi · Recent publications widget.
 *
 * The five most recent items federated to the public catalogue, each
 * with a publication-type id badge, title, and status pip (mint =
 * published, orange = pending review, vermillion = withdrawn).
 * Reuses .w-jira atom.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function OpenCatalogiPublications() {
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
        <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
        <div className={[styles.item, styles.blocked].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
      </div>
    </div>
  );
}
