/**
 * LarpingApp abstract — character + scene workshop.
 *
 * Inferred from the app role (LARP setting management, character sheets,
 * scenes, NPCs, factions): centre shows a character grid (3×2 cards
 * with hex avatars in different family tones) plus a scene timeline
 * across the top so a session organiser can move stages forward. Left
 * nav for characters / scenes / NPCs / factions / rules / archive.
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function LarpingAppMock() {
  const charTones = ['', 'b', 'c', 'd', 'e', 'b'];
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
          {/* Scene timeline (one done, one active in orange, three to-do) */}
          <div className={[styles.w, styles['w-graph-bar']].join(' ')} style={{minHeight: 0}}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
          </div>
          {/* Character grid */}
          <div className={styles.grid}>
            {charTones.map((cls, i) => (
              <div key={i} className={[styles.card, cls && styles[cls]].filter(Boolean).join(' ')}>
                <div className={styles.ico}></div>
                <div className={styles.row + ' ' + styles.head}></div>
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
