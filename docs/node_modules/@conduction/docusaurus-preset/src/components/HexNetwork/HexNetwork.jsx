/**
 * <HexNetwork />
 *
 * Honeycomb-pattern grid of pointy-top hex tiles. Each tile holds a
 * logo or icon and an optional caption. One tile in the centre can be
 * highlighted as the brand anchor (Conduction hex, the workspace, the
 * thing the surrounding network connects to).
 *
 * Reference visuals:
 *   - honeycomb.io/partners (the cloud-provider hex grid around the
 *     central Honeycomb logo)
 *   - the existing Conduction honeycomb backdrop in
 *     <cn-honeycomb-bg>, but as a content-bearing grid not a backdrop.
 *
 * Two surfaces:
 *   - Integrations: connect ConNext to mail, calendar, XWiki, Decks,
 *     OpenProject etc. Centre = ConNext / Conduction logo.
 *   - Partners: same shape, partner logos around the Conduction hex.
 *
 * Usage in MDX:
 *
 *   <HexNetwork
 *     center={{name: 'ConNext', logo: '/img/connext-mark.svg'}}
 *     cells={[
 *       {name: 'Mail',        logo: '/img/integrations/mail.svg'},
 *       {name: 'Calendar',    logo: '/img/integrations/calendar.svg'},
 *       {name: 'XWiki',       logo: '/img/integrations/xwiki.svg'},
 *       {name: 'Decks',       logo: '/img/integrations/decks.svg'},
 *       {name: 'OpenProject', logo: '/img/integrations/openproject.svg'},
 *       {name: 'Talk',        logo: '/img/integrations/talk.svg'},
 *     ]}
 *     layout="3-3-3"
 *   />
 */

import React from 'react';
import styles from './HexNetwork.module.css';

const LAYOUTS = {
  '3-3-3':   [3, 3, 3],         // 9 cells,  centre row[1][1]
  '2-3-2':   [2, 3, 2],         // 7 cells,  centre row[1][1]
  '3-4-3':   [3, 4, 3],         // 10 cells, no natural centre slot
  '4-3-4':   [4, 3, 4],         // 11 cells, used for the full /support partner wall
  '3-3-3-3': [3, 3, 3, 3],      // 12 cells, centre row[1][1]
};

function Cell({cell, highlighted}) {
  if (!cell) {
    return <div className={[styles.cell, styles.empty].filter(Boolean).join(' ')} aria-hidden="true"><div className={styles.cellInner} /></div>;
  }
  const inner = cell.logo
    ? (typeof cell.logo === 'string'
        ? <img src={cell.logo} alt={cell.name || ''} className={styles.logo} />
        : <span className={styles.logo}>{cell.logo}</span>)
    : (cell.name && <span className={styles.wordmark}>{cell.name}</span>);
  return (
    <a
      className={[styles.cell, highlighted && styles.center, cell.href && styles.linked].filter(Boolean).join(' ')}
      href={cell.href || undefined}
      title={cell.name || undefined}
      aria-label={cell.name || undefined}
    >
      <div className={styles.cellInner}>{inner}</div>
    </a>
  );
}

export default function HexNetwork({
  center,
  cells = [],
  layout = '3-3-3',
  background = 'transparent',
  scroll = 'none',          // 'none' | 'down' | 'up'
  scrollSpeed = 60,         // seconds per cycle
  visibleRows = 3,          // rows visible inside the scroll viewport
  fade = true,              // soft mask-gradient on viewport top/bottom
  className,
}) {
  const rowCounts = LAYOUTS[layout] || LAYOUTS['3-3-3'];
  /* Distribute cells into rows, threading the centre into the middle row
     (floor((rows-1)/2)) so it stays visually anchored regardless of how
     many rows the layout has. The centre prop wins over any cell that
     would otherwise occupy that slot. */
  const centreRow = Math.floor((rowCounts.length - 1) / 2);
  const rows = [];
  let idx = 0;
  rowCounts.forEach((count, rowIdx) => {
    const row = [];
    for (let c = 0; c < count; c++) {
      const isCentreSlot = rowIdx === centreRow && c === Math.floor(count / 2) && center;
      if (isCentreSlot) {
        row.push({cell: center, highlighted: true});
      } else {
        row.push({cell: cells[idx], highlighted: false});
        idx += 1;
      }
    }
    rows.push(row);
  });

  const composed = [styles.network, styles[`bg-${background}`], className].filter(Boolean).join(' ');
  const renderRows = (rowsToRender, keyPrefix = '') => rowsToRender.map((row, ri) => (
    <div
      key={`${keyPrefix}${ri}`}
      className={[styles.row, ri % 2 === 1 && styles.offset].filter(Boolean).join(' ')}
    >
      {row.map((entry, ci) => (
        <Cell key={ci} cell={entry.cell} highlighted={entry.highlighted} />
      ))}
    </div>
  ));

  /* Static render: just the grid. */
  if (scroll === 'none') {
    return (
      <div className={composed}>
        <div className={styles.grid}>{renderRows(rows)}</div>
      </div>
    );
  }

  /* Scrolling render: viewport with mask-faded edges, track that holds
     the rows twice for a seamless loop. The track animates translateY;
     'down' shows content moving downward (rows enter at top, exit at
     bottom), 'up' is the reverse (credits-roll). Hover and focus pause
     the loop, prefers-reduced-motion turns it off. */
  const scrollClass = scroll === 'down' ? styles.scrollDown : styles.scrollUp;
  const trackStyle = {animationDuration: `${scrollSpeed}s`};
  const viewportStyle = {'--hex-visible-rows': visibleRows};
  const viewportClass = [styles.viewport, fade && styles.viewportFade].filter(Boolean).join(' ');
  return (
    <div className={composed}>
      <div className={viewportClass} style={viewportStyle}>
        <div className={[styles.track, scrollClass].join(' ')} style={trackStyle}>
          {renderRows(rows, 'a-')}
          {renderRows(rows, 'b-')}
        </div>
      </div>
    </div>
  );
}
