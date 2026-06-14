/**
 * <AtomZones />
 *
 * The "Five atoms, one chassis" visual reference. Renders five
 * zone cards, each focusing on one atom of the canonical Conduction
 * app chassis: Topbar, Left navigation, Main column, Page header,
 * Sidebar. The focused atom is outlined in KNVB orange; everything
 * else fades to 25% opacity so the reader sees *where* the atom
 * sits in context.
 *
 * Mirrors the static-HTML reference at
 *   preview/components/app-mock.html#five-atoms
 * but trimmed to the educational essentials: title, "applies to"
 * tag, one-paragraph description, and the chassis illustration.
 * Implementation-detail metadata (Inside / Tokens / Width / tips)
 * lives on the design-system kit page, not here.
 *
 * Used by Docusaurus consumers (nextcloud-vue.conduction.nl,
 * procest.conduction.nl, openregister.conduction.nl) to teach the
 * chassis-plus-atoms pattern on architecture pages.
 *
 * Self-contained: bundles its own chassis JSX + zone-focus styles
 * via :global() in AtomZones.module.css. Doesn't reuse <AppMock/>'s
 * variants — those intentionally skip the .pageHeader atom — so
 * the page-header zone can render correctly.
 */

import React from 'react';
import styles from './AtomZones.module.css';

const ZONES = [
  {
    code: '.topbar',
    title: 'Topbar',
    applies: <><strong>App</strong> &middot; <strong>Desk</strong> &middot; always</>,
    body:
      "The Nextcloud chrome row. Sits across every page unconditionally because every Conduction app lives inside Nextcloud's workspace. The shelf icons are the cross-app navigation; per-app links never go here.",
    focus: 'focusTopbar',
  },
  {
    code: '.nav',
    title: 'Left navigation',
    applies: <><strong>App</strong> &middot; required &middot; <em className={styles.never}>Desk: never</em></>,
    body:
      "The per-app sidebar. Carries this app's own primary navigation, plus a footer pinned to the bottom for global access (Settings, Feedback). The active item is the only place cobalt-100 backgrounds a row.",
    focus: 'focusNav',
  },
  {
    code: '.col',
    title: 'Main column',
    applies: <><strong>App</strong> &middot; <strong>Desk</strong> &middot; always</>,
    body:
      'The work surface. In an App pattern, the column opens with a .pageHeader (title + actions), then KPI strip and panels. In a Desk pattern, .col nests inside .grid to become a full-bleed widget canvas with no page header.',
    focus: 'focusCol',
  },
  {
    code: '.pageHeader',
    title: 'Page header',
    applies: <><strong>Index</strong> &middot; <strong>Detail</strong> &middot; <em className={styles.never}>Desk: never</em></>,
    body:
      "The first row of .col on every Index and Detail template. Carries the page title (left) and exactly two action buttons (right): one ghost (secondary), one primary (filled cobalt). The header tracks .col's width: full-spread when the sidebar is closed, constrained when it is open.",
    focus: 'focusPageHeader',
  },
  {
    code: '.detail',
    title: 'Sidebar',
    applies: <><strong>App</strong> &middot; optional &middot; <em className={styles.never}>Desk: never</em></>,
    body:
      'The right-hand sidebar. Optional, dismissible, anchored to the active record or the active view. Carries an icon + title + description in a header, then a tabbed body (Search / Columns by default). Class stays .detail for code; we call it the Sidebar in copy.',
    focus: 'focusDetail',
  },
];

/**
 * Reference chassis with all five atoms: topbar, nav (with footer),
 * col (with pageHeader + kpiRow + panelRow), and detail.rich.
 *
 * Class names are emitted as global strings so the chassis renders
 * with the same selectors used in the design-system kit page; the
 * AtomZones.module.css uses :global(...) to target them. This means
 * one source of styling for the chassis, regardless of whether it's
 * loaded inside a CSS-Modules pipeline (preset consumers) or via the
 * static kit page.
 */
function ChassisFrame({ focus }) {
  return (
    <div className={`${styles.zoneFrame} ${styles[focus]}`}>
      <div className="am-frame">
        <div className="am-topbar">
          <div className="am-logo"></div>
          {Array.from({ length: 14 }).map((_, i) => (
            <div key={i} className="am-icon"></div>
          ))}
          <div className="am-spacer"></div>
          <div className="am-bell"></div>
          <div className="am-avatar"></div>
        </div>
        <div className="am-body">
          <div className="am-nav">
            <div className="am-navHead">
              <div className="am-h"></div>
              <div className="am-l"></div>
            </div>
            {[true, false, false, false, false].map((active, i) => (
              <div
                key={i}
                className={`am-item ${active ? 'am-active' : ''}`}
              >
                <div className="am-ico"></div>
                <div className="am-l"></div>
              </div>
            ))}
            <div className="am-footer">
              <div className="am-item">
                <div className="am-ico"></div>
                <div className="am-l"></div>
              </div>
              <div className="am-item">
                <div className="am-ico"></div>
                <div className="am-l"></div>
              </div>
            </div>
          </div>
          <div className="am-col">
            <div className="am-pageHeader">
              <div className="am-title-bar"></div>
              <div className="am-spacer"></div>
              <div className="am-actions">
                <div className="am-btn am-ghost"></div>
                <div className="am-btn"></div>
              </div>
            </div>
            <div className="am-kpiRow">
              <div className="am-kpi">
                <div className="am-ico"></div>
                <div className="am-meta">
                  <div className="am-num"></div>
                  <div className="am-label"></div>
                </div>
              </div>
              <div className="am-kpi am-forest">
                <div className="am-ico"></div>
                <div className="am-meta">
                  <div className="am-num"></div>
                  <div className="am-label"></div>
                </div>
              </div>
              <div className="am-kpi am-amber">
                <div className="am-ico"></div>
                <div className="am-meta">
                  <div className="am-num"></div>
                  <div className="am-label"></div>
                </div>
              </div>
            </div>
            <div className="am-panelRow">
              <div className="am-panel">
                <div className="am-head">
                  <div className="am-title"></div>
                </div>
                <div className="am-stack">
                  <div className="am-item">
                    <div className="am-lines">
                      <div className="am-l1"></div>
                    </div>
                  </div>
                  <div className="am-item">
                    <div className="am-lines">
                      <div className="am-l1"></div>
                    </div>
                  </div>
                </div>
              </div>
              <div className="am-panel">
                <div className="am-head">
                  <div className="am-title"></div>
                </div>
                <div className="am-stack">
                  <div className="am-item">
                    <div className="am-av am-b"></div>
                    <div className="am-lines">
                      <div className="am-l1"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div className="am-detail am-rich">
            <div className="am-sb-head">
              <div className="am-ico"></div>
              <div className="am-meta">
                <div className="am-title"></div>
                <div className="am-desc"></div>
              </div>
              <div className="am-close"></div>
            </div>
            <div className="am-sb-tabs">
              <div className="am-sb-tab am-active">
                <div className="am-ico"></div>
                <div className="am-l"></div>
              </div>
              <div className="am-sb-tab">
                <div className="am-ico"></div>
                <div className="am-l"></div>
              </div>
            </div>
            <div className="am-sb-body">
              <div className="am-sb-input"></div>
              <div className="am-sb-filter">
                <div className="am-lbl"></div>
                <div className="am-field"></div>
              </div>
              <div className="am-sb-filter">
                <div className="am-lbl"></div>
                <div className="am-field"></div>
              </div>
              <div className="am-sb-filter">
                <div className="am-lbl"></div>
                <div className="am-field"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function ZoneCard({ zone }) {
  return (
    <div className={styles.zoneCard}>
      <div className={styles.zoneHead}>
        <code>{zone.code}</code>
        <h3>{zone.title}</h3>
        <span className={styles.applies}>{zone.applies}</span>
      </div>
      <p className={styles.lede}>{zone.body}</p>
      <ChassisFrame focus={zone.focus} />
    </div>
  );
}

export default function AtomZones() {
  return (
    <div className={styles.zones}>
      {ZONES.map((zone) => (
        <ZoneCard key={zone.code} zone={zone} />
      ))}
    </div>
  );
}
