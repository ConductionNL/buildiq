/**
 * OpenWoo abstract — Woo publication catalogue.
 *
 * Inferred from the app role (eleven Woo categories, citizen-search,
 * audit log, federation to data.overheid.nl): centre shows a 3×2 grid
 * of category cards (each in a different family colour because the
 * eleven categories are categorical, not ranked) with publish counts
 * and status pills. Left nav for categories / publication queue /
 * archive / audit / federation.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function OpenWooMock() {
  const cards = ['', 'b', 'c', 'd', 'e', 'b'];
  return (
    <>
      <div className={styles.topbar}>
        <div className={styles.logo}></div>
        {Array.from({length: 14}).map((_, i) => <div key={i} className={styles.icon}></div>)}
        <div className={styles.spacer}></div>
        <div className={styles.bell}></div>
        <div className={styles.avatar}></div>
      </div>
      <div className={[styles.body, styles.opencatalogi].filter(Boolean).join(' ')}>
        <div className={styles.nav}>
          <div className={styles.navHead}><div className={styles.h}></div><div className={styles.l}></div></div>
          {[true, false, false, false, false, false].map((active, i) => (
            <div key={i} className={[styles.item, active && styles.active].filter(Boolean).join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.l}></div>
            </div>
          ))}
        </div>
        <div className={styles.col}>
          <div className={styles.head}>
            <div className={styles.row + ' ' + styles.head} style={{width: '30%'}}></div>
            <div className={styles.actions}>
              <div className={styles.btn + ' ' + styles.ghost}></div>
              <div className={styles.btn}></div>
            </div>
          </div>
          <div className={styles.grid}>
            {cards.map((cls, i) => (
              <div key={i} className={[styles.card, cls && styles[cls]].filter(Boolean).join(' ')}>
                <div className={styles.ico}></div>
                <div className={styles.row + ' ' + styles.head}></div>
                <div className={styles.row}></div>
                <div className={styles.row + ' ' + styles.short}></div>
                <div className={styles.statusPill}>
                  <div className={styles.h}></div><div className={styles.t}></div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </>
  );
}
