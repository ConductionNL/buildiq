/**
 * OpenBuild abstract — three-pane citizen-developer app builder: left
 * builder nav + centre dashboard + right manifest/detail rail.
 * Reference: live screenshot at http://localhost:8080/apps/openbuild/.
 *
 * Centre is the canonical "Dashboard" view: a KPI strip (Virtual apps /
 * Published / Templates / Versions) over two panels — a virtual-app card
 * list ("Virtual apps") and a template-catalogue list ("Templates").
 * Right rail abstracts the per-app manifest/schema editor.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function OpenBuildMock({ sidebar = null }) {
  return (
    <>
      <div className={styles.topbar}>
        <div className={styles.logo}></div>
        {Array.from({length: 14}).map((_, i) => <div key={i} className={styles.icon}></div>)}
        <div className={styles.spacer}></div>
        <div className={styles.bell}></div>
        <div className={styles.avatar}></div>
      </div>
      <div className={[styles.body, styles.openbuild].filter(Boolean).join(' ')}>
        {/* Left builder nav: Dashboard · Virtual apps · Schemas · Templates · Exports */}
        <div className={styles.nav}>
          <div className={styles.navHead}>
            <div className={styles.h}></div>
            <div className={styles.l}></div>
          </div>
          {[true, false, false, false, false].map((active, i) => (
            <div key={i} className={[styles.item, active && styles.active].filter(Boolean).join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.l}></div>
            </div>
          ))}
        </div>
        {/* Centre: builder dashboard */}
        <div className={styles.col}>
          {/* KPI strip: Virtual apps / Published / Templates / Versions */}
          <div className={styles.kpiRow}>
            <div className={styles.kpi}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={[styles.kpi, styles.forest].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={[styles.kpi, styles.amber].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={[styles.kpi, styles.lavender].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
          </div>
          {/* Virtual-app card list + template catalogue */}
          <div className={styles.panelRow}>
            <div className={styles.panel}>
              <div className={styles.head}><div className={styles.title}></div></div>
              <div className={styles.stack}>
                {['b','d','a','c'].map((cls, i) => (
                  <div key={i} className={styles.item}>
                    <div className={[styles.av, styles[cls]].join(' ')}></div>
                    <div className={styles.lines}><div className={styles.l1}></div><div className={styles.l2}></div></div>
                  </div>
                ))}
              </div>
            </div>
            <div className={styles.panel}>
              <div className={styles.head}><div className={styles.title}></div></div>
              <div className={styles.stack}>
                {[0,1,2,3].map(i => (
                  <div key={i} className={styles.item}>
                    <div className={styles.lines}><div className={styles.l1}></div></div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
        {/* Right rail: per-app manifest / schema editor. When AppMock passes
            a `sidebar` prop it renders there instead of this placeholder. */}
        {sidebar || (
          <div className={styles.detail}>
            <div className={styles.row + ' ' + styles.head}></div>
            <div className={styles.row}></div>
            <div className={styles.row + ' ' + styles.short}></div>
            <div style={{height: 8}}></div>
            <div className={styles.row + ' ' + styles.head}></div>
            <div className={styles.row + ' ' + styles.dark}></div>
            <div className={styles.row}></div>
            <div className={styles.row}></div>
            <div className={styles.row + ' ' + styles.short}></div>
            <div className={styles.row + ' ' + styles.accent}></div>
            <div className={styles.row + ' ' + styles.short}></div>
          </div>
        )}
      </div>
    </>
  );
}
