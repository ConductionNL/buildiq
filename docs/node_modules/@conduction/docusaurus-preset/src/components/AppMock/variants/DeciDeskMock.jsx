/**
 * DeciDesk abstract — left nav + centre with action row, KPI strip,
 * and two tables. Reference: localhost:8080/apps/decidesk.
 *
 * Hero of the centre is the trio of primary buttons (New Decision /
 * Action Item / Minutes); we render them as accent-pill rows top right.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function DeciDeskMock() {
  return (
    <>
      <div className={styles.topbar}>
        <div className={styles.logo}></div>
        {Array.from({length: 14}).map((_, i) => <div key={i} className={styles.icon}></div>)}
        <div className={styles.spacer}></div>
        <div className={styles.bell}></div>
        <div className={styles.avatar}></div>
      </div>
      <div className={[styles.body, styles.decidesk].filter(Boolean).join(' ')}>
        <div className={styles.nav}>
          <div className={styles.navHead}>
            <div className={styles.h}></div><div className={styles.l}></div>
          </div>
          {[true, false, false, false, false, false].map((active, i) => (
            <div key={i} className={[styles.item, active && styles.active].filter(Boolean).join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.l}></div>
            </div>
          ))}
        </div>
        <div className={styles.col}>
          <div className={styles.head}>
            <div className={styles.row + ' ' + styles.head} style={{width: 25}}></div>
            <div className={styles.actions}>
              <div className={styles.btn}></div>
              <div className={styles.btn}></div>
              <div className={styles.btn}></div>
            </div>
          </div>
          <div className={styles.kpiRow}>
            <div className={[styles.kpi, styles.amber].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={styles.kpi}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={[styles.kpi, styles.lavender].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
          </div>
          <div className={styles.panelRow}>
            <div className={styles.panel}>
              <div className={styles.head}><div className={styles.title}></div></div>
              <div className={styles.row + ' ' + styles.accent}></div>
            </div>
            <div className={styles.panel}>
              <div className={styles.head}><div className={styles.title}></div></div>
              <div className={styles.row + ' ' + styles.short}></div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
