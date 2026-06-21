/**
 * NLDesign abstract — theme settings panel.
 *
 * Inferred from the app role (NL Design System theme for Nextcloud):
 * centre shows a settings layout with a colour-swatch row, a type
 * specimen, and a component preview block. Left nav for theme /
 * colours / typography / spacing / components / overrides.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function NLDesignMock() {
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
          {/* Colour-swatch panel */}
          <div className={styles.panel}>
            <div className={styles.head}><div className={styles.title}></div></div>
            <div style={{display: 'flex', gap: 6, flexWrap: 'wrap'}}>
              {['var(--c-blue-cobalt)','var(--c-orange-knvb)','var(--c-mint-500)','var(--c-lavender-500)','var(--c-forest-500)','var(--c-terracotta-500)','var(--c-cobalt-300)','var(--c-cobalt-100)'].map((bg, i) => (
                <span key={i} style={{width: 22, height: 22, borderRadius: 4, background: bg}}></span>
              ))}
            </div>
          </div>
          {/* Type specimen */}
          <div className={styles.panel}>
            <div className={styles.head}><div className={styles.title}></div></div>
            <div style={{display: 'flex', alignItems: 'baseline', gap: 8}}>
              <div style={{height: 14, width: 18, background: 'var(--c-cobalt-900)', borderRadius: 1}}></div>
              <div style={{height: 11, width: 14, background: 'var(--c-cobalt-700)', borderRadius: 1}}></div>
              <div style={{height: 8,  width: 12, background: 'var(--c-cobalt-400)', borderRadius: 1}}></div>
              <div style={{height: 6,  width: 10, background: 'var(--c-cobalt-300)', borderRadius: 1}}></div>
              <div style={{height: 4,  width: 8,  background: 'var(--c-cobalt-200)', borderRadius: 1}}></div>
            </div>
            <div className={styles.row}></div>
            <div className={styles.row + ' ' + styles.short}></div>
          </div>
          {/* Component preview */}
          <div className={styles.panel}>
            <div className={styles.head}><div className={styles.title}></div></div>
            <div style={{display: 'flex', gap: 6, flexWrap: 'wrap'}}>
              <div className={styles.btn}></div>
              <div className={styles.btn + ' ' + styles.ghost}></div>
              <div className={styles.statusPill}><div className={styles.h}></div><div className={styles.t}></div></div>
              <div className={[styles.statusPill, styles.beta].join(' ')}><div className={styles.h}></div><div className={styles.t}></div></div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
