/**
 * MyDash · BI on registers variant.
 *
 * Demonstrates the BI-graph angle: any chart you want, drawn directly
 * on a register without ETL. The mock shows four graph cards in a
 * 2×2 grid (bar chart, line chart, donut KPI, second bar with a
 * different accent), plus a ranked-list table widget on the right.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function MyDashBiMock() {
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
          {/* Column 1: Bar chart on top, line chart below */}
          <div className={styles.col}>
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
              </div>
              <div className={styles.axis}></div>
            </div>
            <div className={[styles.w, styles['w-graph-line']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.chart}>
                <svg viewBox="0 0 100 40" preserveAspectRatio="none">
                  <path className={styles.fill}   d="M0,32 L14,24 L28,28 L42,18 L56,22 L70,12 L84,16 L100,8 L100,40 L0,40 Z"/>
                  <path className={styles.stroke} d="M0,32 L14,24 L28,28 L42,18 L56,22 L70,12 L84,16 L100,8"/>
                  <circle className={styles.dot} cx="100" cy="8" r="2.5"/>
                </svg>
              </div>
              <div className={styles.axis}></div>
            </div>
          </div>
          {/* Column 2: Donut KPI + second bar chart stacked */}
          <div className={styles.col}>
            <div className={[styles.w, styles['w-graph-donut']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.chart}>
                <div className={styles.donut}></div>
                <div className={styles.legend}>
                  <div className={styles.row}><span className={[styles.swatch, styles.a].join(' ')}></span><span className={styles.label}></span></div>
                  <div className={styles.row}><span className={[styles.swatch, styles.b].join(' ')}></span><span className={styles.label}></span></div>
                  <div className={styles.row}><span className={[styles.swatch, styles.c].join(' ')}></span><span className={styles.label}></span></div>
                </div>
              </div>
            </div>
            <div className={[styles.w, styles['w-graph-bar']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.bars}>
                <div className={styles.bar} style={{height: '70%'}}></div>
                <div className={styles.bar} style={{height: '45%'}}></div>
                <div className={[styles.bar, styles.accent].join(' ')} style={{height: '88%'}}></div>
                <div className={styles.bar} style={{height: '55%'}}></div>
                <div className={styles.bar} style={{height: '38%'}}></div>
              </div>
              <div className={styles.axis}></div>
            </div>
          </div>
          {/* Column 3: KPI cards stack */}
          <div className={styles.col}>
            <div className={styles.tile}>
              <div className={styles.tileHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div style={{display: 'flex', alignItems: 'baseline', gap: 4}}>
                <div style={{height: 12, width: 28, background: 'var(--c-cobalt-900)', borderRadius: 1}}></div>
                <div style={{height: 4, width: 16, background: 'var(--c-mint-500)', borderRadius: 1}}></div>
              </div>
              <div style={{height: 3, width: '70%', background: 'var(--c-cobalt-200)', borderRadius: 1}}></div>
            </div>
            <div className={styles.tile}>
              <div className={styles.tileHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div style={{display: 'flex', alignItems: 'baseline', gap: 4}}>
                <div style={{height: 12, width: 22, background: 'var(--c-cobalt-900)', borderRadius: 1}}></div>
                <div style={{height: 4, width: 14, background: 'var(--c-orange-knvb)', borderRadius: 1}}></div>
              </div>
              <div style={{height: 3, width: '60%', background: 'var(--c-cobalt-200)', borderRadius: 1}}></div>
            </div>
            <div className={styles.tile}>
              <div className={styles.tileHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div style={{display: 'flex', alignItems: 'baseline', gap: 4}}>
                <div style={{height: 12, width: 30, background: 'var(--c-cobalt-900)', borderRadius: 1}}></div>
                <div style={{height: 4, width: 12, background: 'var(--c-mint-500)', borderRadius: 1}}></div>
              </div>
              <div style={{height: 3, width: '80%', background: 'var(--c-cobalt-200)', borderRadius: 1}}></div>
            </div>
          </div>
          {/* Column 4: Top-N table widget */}
          <div className={[styles.w, styles['w-rss']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.list}>
              <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
              <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
              <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
              <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
              <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
              <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
