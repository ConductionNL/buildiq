/**
 * MyDash · Tiles & Grids variant.
 *
 * Demonstrates the Nextcloud-integration angle: large hex-icon
 * launcher tiles that deeplink inside Nextcloud, link out to external
 * URLs, launch a document, kick off a process, or themselves contain
 * a sub-grid of further tiles. The mock pictures exactly that mix:
 * four launcher tiles top-left, a 2×2 sub-grid bottom-left ("custom
 * tile group"), and two info widgets right.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function MyDashTilesMock() {
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
          {/* Column 1: Launcher tiles in cobalt + KNVB */}
          <div className={styles.col}>
            <div className={[styles.w, styles['w-launcher']].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.label}></div>
            </div>
            <div className={[styles.w, styles['w-launcher'], styles.amber].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.label}></div>
            </div>
            <div className={[styles.w, styles['w-launcher']].join(' ')}>
              <div className={styles.ico}></div>
              <div className={styles.label}></div>
            </div>
          </div>
          {/* Column 2: Sub-grid (custom tile group with 4 sub-tiles) */}
          <div className={[styles.w, styles['w-subgrid']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.grid}>
              <div className={styles.cell}><div className={styles.ico}></div></div>
              <div className={[styles.cell, styles.b].join(' ')}><div className={styles.ico}></div></div>
              <div className={[styles.cell, styles.c].join(' ')}><div className={styles.ico}></div></div>
              <div className={[styles.cell, styles.d].join(' ')}><div className={styles.ico}></div></div>
            </div>
          </div>
          {/* Column 3: Files widget — deeplinks into a folder */}
          <div className={[styles.w, styles['w-files']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.list}>
              <div className={[styles.item, styles.folder].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
              <div className={[styles.item, styles.doc].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
              <div className={[styles.item, styles.sheet].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
              <div className={[styles.item, styles.doc].join(' ')}><div className={styles.ico}></div><div className={styles.l}></div></div>
              <div className={styles.item}><div className={styles.ico}></div><div className={styles.l}></div></div>
            </div>
          </div>
          {/* Column 4: Two stacked widgets — calendar + decks */}
          <div className={styles.col}>
            <div className={[styles.w, styles['w-calendar']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.grid}>
                {[0,0,1,0,0,0,2, 0,0,0,3,0,0,0, 0,2,0,0,0,1,0, 0,0,0,0,0,0,0].map((kind, i) => (
                  <div key={i} className={[styles.day, kind === 1 && styles.event, kind === 2 && styles.muted, kind === 3 && styles.today].filter(Boolean).join(' ')}></div>
                ))}
              </div>
            </div>
            <div className={[styles.w, styles['w-decks']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.columns}>
                <div className={styles.col}>
                  <div className={styles.card}></div>
                  <div className={styles.card}></div>
                </div>
                <div className={styles.col}>
                  <div className={[styles.card, styles.b].join(' ')}></div>
                  <div className={styles.card}></div>
                  <div className={styles.card}></div>
                </div>
                <div className={styles.col}>
                  <div className={[styles.card, styles.c].join(' ')}></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
