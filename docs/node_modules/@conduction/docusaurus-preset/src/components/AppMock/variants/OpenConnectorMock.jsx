/**
 * OpenConnector abstract — source-connector-target pipeline view.
 *
 * Inferred from the app's role ("integration plane between Conduction
 * apps and external APIs"): the centre stage shows a single canonical
 * pipeline (lavender source → cobalt connector → forest target), with
 * a status table below for recent runs. Left nav for sources, jobs,
 * mappings, sync logs, schedules.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function OpenConnectorMock() {
  return (
    <>
      <div className={styles.topbar}>
        <div className={styles.logo}></div>
        {Array.from({length: 14}).map((_, i) => <div key={i} className={styles.icon}></div>)}
        <div className={styles.spacer}></div>
        <div className={styles.bell}></div>
        <div className={styles.avatar}></div>
      </div>
      <div className={[styles.body, styles.openconnector].filter(Boolean).join(' ')}>
        <div className={styles.nav}>
          <div className={styles.navHead}>
            <div className={styles.h}></div><div className={styles.l}></div>
          </div>
          {[false, true, false, false, false, false].map((active, i) => (
            <div key={i} className={[styles.item, active && styles.active].filter(Boolean).join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.l}></div>
            </div>
          ))}
        </div>
        <div className={styles.col}>
          <div className={styles.head}>
            <div className={styles.row + ' ' + styles.head} style={{width: 30}}></div>
            <div className={styles.actions}>
              <div className={styles.btn}></div>
            </div>
          </div>
          <div className={styles.stage}>
            <div className={styles.source}>
              <div className={styles.ico}></div>
              <div className={styles.row + ' ' + styles.short}></div>
            </div>
            <div className={styles.arrow}></div>
            <div className={styles.conn}>
              <div className={styles.ico}></div>
              <div className={styles.label}></div>
            </div>
            <div className={styles.arrow}></div>
            <div className={styles.target}>
              <div className={styles.ico}></div>
              <div className={styles.row + ' ' + styles.short}></div>
            </div>
          </div>
          <div className={styles.panel}>
            <div className={styles.head}>
              <div className={styles.title}></div>
              <div className={styles.statusPill}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
            </div>
            <div className={styles.stack}>
              {['a','d','b','c'].map((cls, i) => (
                <div key={i} className={styles.item}>
                  <div className={[styles.av, styles[cls]].join(' ')}></div>
                  <div className={styles.lines}>
                    <div className={styles.l1}></div>
                    <div className={styles.l2}></div>
                  </div>
                  <div className={styles.row + ' ' + styles.short} style={{width: 24}}></div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
