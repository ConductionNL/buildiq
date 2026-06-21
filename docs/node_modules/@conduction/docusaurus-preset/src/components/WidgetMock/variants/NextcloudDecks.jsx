/**
 * Nextcloud · Decks widget (stock, framed for context).
 *
 * Three kanban columns with cards. Coloured borders mark priority
 * (.b = orange / urgent, .c = mint / done).
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function NextcloudDecks() {
  return (
    <div className={[styles.w, styles['w-decks']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.columns}>
        <div className={styles.col}>
          <div className={styles.card}></div>
          <div className={styles.card}></div>
        </div>
        <div className={styles.col}>
          <div className={[styles.card, styles.b].join(' ')}></div>
          <div className={styles.card}></div>
          <div className={styles.card}></div>
        </div>
        <div className={styles.col}>
          <div className={[styles.card, styles.c].join(' ')}></div>
        </div>
      </div>
    </div>
  );
}
