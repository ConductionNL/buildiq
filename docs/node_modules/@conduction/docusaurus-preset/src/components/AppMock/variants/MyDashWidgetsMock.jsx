/**
 * MyDash · Widget integration variant.
 *
 * Demonstrates the cross-app widget angle: any Conduction app that
 * registers a Nextcloud dashboard widget shows up here. The mock
 * pictures the canonical mix:
 *  - DocuDesk  → upload-to-anonymise dropzone widget
 *  - Procest   → werkvoorraad (case queue) widget
 *  - Mail      → Important mail (avatar list)
 *  - Calendar  → upcoming-events mini grid
 *  - Jira      → external-app status board
 *  - RSS       → headlines feed
 *  - Video     → video-call shortcut
 */

import React from 'react';
import styles from '../AppMock.module.css';

export default function MyDashWidgetsMock() {
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
          {/* Column 1: Procest werkvoorraad + DocuDesk upload */}
          <div className={styles.col}>
            <div className={[styles.w, styles['w-werkvoorraad']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.list}>
                <div className={[styles.item, styles.now].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={styles.av}></div></div>
                <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.b].join(' ')}></div></div>
                <div className={[styles.item, styles.late].join(' ')}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.c].join(' ')}></div></div>
                <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={styles.av}></div></div>
                <div className={styles.item}><div className={styles.stage}></div><div className={styles.l1}></div><div className={[styles.av, styles.b].join(' ')}></div></div>
              </div>
            </div>
            <div className={[styles.w, styles['w-upload']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.zone}>
                <div className={styles.ico}></div>
                <div className={styles.label}></div>
              </div>
            </div>
          </div>
          {/* Column 2: Mail (Important mail) */}
          <div className={[styles.w, styles['w-mail']].join(' ')}>
            <div className={styles.wHead}>
              <div className={styles.h}></div><div className={styles.t}></div>
            </div>
            <div className={styles.list}>
              {['','b','c','d','','e','b'].map((cls, i) => (
                <div key={i} className={[styles.item, cls && styles[cls]].filter(Boolean).join(' ')}>
                  <div className={styles.av}></div>
                  <div className={styles.lines}>
                    <div className={styles.l1}></div>
                    <div className={styles.l2}></div>
                  </div>
                </div>
              ))}
            </div>
          </div>
          {/* Column 3: Jira status board + RSS feed */}
          <div className={styles.col}>
            <div className={[styles.w, styles['w-jira']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.list}>
                <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
                <div className={[styles.item, styles.review].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
                <div className={[styles.item, styles.todo].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
                <div className={[styles.item, styles.blocked].join(' ')}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
                <div className={styles.item}><div className={styles.id}></div><div className={styles.l}></div><div className={styles.pip}></div></div>
              </div>
            </div>
            <div className={[styles.w, styles['w-rss']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.list}>
                <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
                <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
                <div className={styles.item}><div className={styles.src}></div><div className={styles.title}></div><div className={styles.when}></div></div>
              </div>
            </div>
          </div>
          {/* Column 4: Calendar + Video call shortcut */}
          <div className={styles.col}>
            <div className={[styles.w, styles['w-calendar']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.grid}>
                {[2,2,1,0,0,0,2, 0,0,0,3,1,0,0, 0,1,0,0,0,1,0, 0,0,0,0,1,0,0].map((kind, i) => (
                  <div key={i} className={[styles.day, kind === 1 && styles.event, kind === 2 && styles.muted, kind === 3 && styles.today].filter(Boolean).join(' ')}></div>
                ))}
              </div>
            </div>
            <div className={[styles.w, styles['w-video']].join(' ')}>
              <div className={styles.wHead}>
                <div className={styles.h}></div><div className={styles.t}></div>
              </div>
              <div className={styles.frame}>
                <div className={styles.play}></div>
                <div className={styles.duration}></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
