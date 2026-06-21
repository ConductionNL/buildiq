/**
 * Nextcloud · RSS feed widget (stock, framed for context).
 *
 * Three-column row: source badge, title, when. Mostly text, no avatar
 * or pip needed since the feed metadata IS the status.
 */
import React from 'react';
import styles from '../../AppMock/AppMock.module.css';

export default function NextcloudRss() {
  return (
    <div className={[styles.w, styles['w-rss']].join(' ')}>
      <div className={styles.wHead}>
        <div className={styles.h}></div>
        <div className={styles.t}></div>
      </div>
      <div className={styles.list}>
        <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
        <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
        <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
        <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
        <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
      </div>
    </div>
  );
}
