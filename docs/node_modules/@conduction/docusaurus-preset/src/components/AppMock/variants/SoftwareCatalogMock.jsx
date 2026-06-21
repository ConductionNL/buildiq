/**
 * SoftwareCatalog abstract — IT-asset inventory list.
 *
 * Inferred from the app role (software inventory, licences, contracts,
 * dependencies): centre shows a tabular app list with status pips
 * (mint = stable, orange = update available, red = end-of-life) plus
 * a graph card on top showing licence-renewal timeline. Left nav for
 * apps / licences / contracts / dependencies / discovery / archive.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function SoftwareCatalogMock() {
  return (
    <>
      <div className={styles.topbar}>
        <div className={styles.logo}></div>
        {Array.from({length: 14}).map((_, i) => <div key={i} className={styles.icon}></div>)}
        <div className={styles.spacer}></div>
        <div className={styles.bell}></div>
        <div className={styles.avatar}></div>
      </div>
      <div className={[styles.body, styles.openregister].filter(Boolean).join(' ')}>
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
          {/* Licence-renewal timeline (bar chart with one accent) */}
          <div className={[styles.w, styles['w-graph-bar']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.bars}>
              <div className={styles.bar} style={{height: '30%'}}></div>
              <div className={styles.bar} style={{height: '50%'}}></div>
              <div className={styles.bar} style={{height: '40%'}}></div>
              <div className={styles.bar} style={{height: '70%'}}></div>
              <div className={[styles.bar, styles.accent].join(' ')} style={{height: '88%'}}></div>
              <div className={styles.bar} style={{height: '60%'}}></div>
              <div className={styles.bar} style={{height: '45%'}}></div>
              <div className={styles.bar} style={{height: '38%'}}></div>
            </div>
            <div className={styles.axis}></div>
          </div>
          {/* App list with status pips */}
          <div className={[styles.w, styles['w-jira']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.list}>
              <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              <div className={[styles.item, styles.blocked].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              <div className={[styles.item, styles.todo].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
