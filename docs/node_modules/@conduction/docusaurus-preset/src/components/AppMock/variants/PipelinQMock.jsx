/**
 * PipelinQ abstract — sales kanban + KPI strip.
 *
 * Inferred from the app role (CRM with kanban deal-flow, customers,
 * deals, quotes): centre shows the kanban board with five columns
 * (lead, qualified, proposal, won, lost) plus a KPI strip on top
 * (pipeline value, win rate, deals this week). Left nav for kanban /
 * customers / contacts / deals / quotes.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function PipelinQMock() {
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
          <div className={styles.navHead}><div className={styles.h}></div><div className={styles.l}></div></div>
          {[true, false, false, false, false].map((active, i) => (
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
          <div className={styles.kpiRow}>
            <div className={[styles.kpi, styles.lavender].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={styles.kpi}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
            <div className={[styles.kpi, styles.amber].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.meta}><div className={styles.num}></div><div className={styles.label}></div></div>
            </div>
          </div>
          {/* Kanban board (5 columns) */}
          <div className={[styles.w, styles['w-decks']].join(' ')} style={{flex: 1}}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.columns} style={{gridTemplateColumns: 'repeat(5, 1fr)'}}>
              <div className={styles.col}>
                <div className={styles.card}></div>
                <div className={styles.card}></div>
                <div className={styles.card}></div>
              </div>
              <div className={styles.col}>
                <div className={[styles.card, styles.b].join(' ')}></div>
                <div className={styles.card}></div>
              </div>
              <div className={styles.col}>
                <div className={[styles.card, styles.c].join(' ')}></div>
                <div className={styles.card}></div>
                <div className={styles.card}></div>
              </div>
              <div className={styles.col}>
                <div className={[styles.card, styles.c].join(' ')}></div>
              </div>
              <div className={styles.col}>
                <div className={styles.card}></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
