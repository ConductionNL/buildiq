/**
 * MyDash abstract — full-bleed cobalt background with widget grid.
 * Reference: live screenshot at http://localhost:8080/apps/mydash/.
 *
 * Layout: top app strip + 4-column grid where col 1 stacks small + big
 * primary tiles (Intranet / Calendar / Files), cols 2-4 hold info
 * widgets with avatar lists, empty states, and an upload-drop zone.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function MyDashMock() {
  return (
    <>
      <div className={styles.topbar}>
        <div className={styles.logo}></div>
        {Array.from({length: 14}).map((_, i) => <div key={i} className={styles.icon}></div>)}
        <div className={styles.spacer}></div>
        <div className={styles.bell}></div>
        <div className={styles.avatar}></div>
      </div>
      <div className={[styles.body, styles.mydash].filter(Boolean).join(' ')}>
        <div className={styles.grid}>
          {/* Column 1: small + big primary tiles */}
          <div className={styles.col}>
            <div className={styles.tile + ' ' + styles.empty}></div>
            <div className={styles.tile + ' ' + styles.primary}>
              <div className={styles.icon}></div>
              <div className={styles.label}></div>
            </div>
            <div className={styles.tile + ' ' + styles.empty}></div>
            <div className={styles.tile + ' ' + styles.primary}>
              <div className={styles.icon}></div>
              <div className={styles.label}></div>
            </div>
            <div className={styles.tile + ' ' + styles.primary}>
              <div className={styles.icon}></div>
              <div className={styles.label}></div>
            </div>
          </div>
          {/* Column 2: Important mail with avatar list */}
          <div className={styles.tile}>
            <div className={styles.tileHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.stack}>
              {['b','c','a','d','e','b'].map((cls, i) => (
                <div key={i} className={styles.item}>
                  <div className={[styles.av, styles[cls]].join(' ')}></div>
                  <div className={styles.lines}>
                    <div className={styles.l1}></div>
                    <div className={styles.l2}></div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          {/* Column 3: Bar-chart graph card (KPI overview) */}
          <div className={[styles.w, styles['w-graph-bar']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.bars}>
              <div className={styles.bar} style={{height: '40%'}}></div>
              <div className={styles.bar} style={{height: '65%'}}></div>
              <div className={styles.bar} style={{height: '52%'}}></div>
              <div className={styles.bar} style={{height: '78%'}}></div>
              <div className={[styles.bar, styles.accent].join(' ')} style={{height: '90%'}}></div>
              <div className={styles.bar} style={{height: '60%'}}></div>
              <div className={styles.bar} style={{height: '72%'}}></div>
            </div>
            <div className={styles.axis}></div>
          </div>
          {/* Column 4: Overdue + Document Anonymizer */}
          <div className={styles.col}>
            <div className={styles.tile} style={{flex: 1}}>
              <div className={styles.tileHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div style={{flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center'}}>
                <div style={{width: 22, height: 25, clipPath: 'var(--hex-pointy-top)', background: 'var(--c-cobalt-200)'}}></div>
              </div>
            </div>
            <div className={styles.tile}>
              <div className={styles.tileHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div style={{height: 32, border: '1px dashed var(--c-cobalt-200)', borderRadius: 'var(--radius-sm)', background: 'var(--c-cobalt-50)'}}></div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
