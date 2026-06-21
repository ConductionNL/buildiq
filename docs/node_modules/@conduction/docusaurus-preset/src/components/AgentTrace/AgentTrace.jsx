/**
 * <AgentTrace />
 *
 * Fake terminal log of an AI agent doing work. Reference: the dark
 * terminal block on honeycomb.io's hero showing a Claude-style tool
 * trace ("Found the root cause", "honeycomb - run_query (MCP)…",
 * "Pollinating..."). Translated to Conduction tokens: cobalt-900
 * background, white body text, KNVB-orange status, cobalt-200/400
 * ghosted continuation lines, mint-300 success, lavender-300 tool
 * names. Monospace throughout.
 *
 * The component is presentational only — it doesn't run anything.
 * Pass it a list of lines and it renders them with the right
 * indentation, bullets, and colour. Optional cursor + bottom mode
 * strip mirror the Claude CLI footer.
 *
 * Used wherever a marketing surface needs to *show* what an agent
 * does without embedding a real screencap. Pairs well with
 * <AppMock> on the same page (the agent log is the "behind the
 * scenes", the AppMock is the "what the user sees").
 *
 * Usage in MDX:
 *
 *   <AgentTrace
 *     lines={[
 *       { kind: 'expand',     text: '+65 lines (expand)' },
 *       { kind: 'continuation', text: 'input: "latency errors", dataset_slug: "frontend") , max_queries: 15)' },
 *       { kind: 'note',  bullet: 'done', text: 'Found the root cause. Reading more to understand the issue.' },
 *       { kind: 'tool',  text: 'openconnector - run_pipeline (MCP)(register_slug: "zaak", limit: 50)' },
 *       { kind: 'expand',     text: '+38 lines (ctrl+o to expand)' },
 *       { kind: 'note',  bullet: 'info', text: 'Reading register…', muted: '(ctrl+o to expand)' },
 *       { kind: 'status', text: 'Synthesising…' },
 *     ]}
 *     cursor
 *     mode="accept edits on (shift+tab to cycle)"
 *   />
 *
 * Props:
 *   - lines: list of `{kind, text, bullet?, muted?, indent?}` rows.
 *     - kind 'expand'       → muted "+N lines" expandable marker
 *     - kind 'continuation' → indented muted continuation of the
 *                              previous tool call line
 *     - kind 'note'         → primary line with optional bullet:
 *                              'done' (white), 'info' (workspace-blue),
 *                              'orange' (KNVB), 'mint' (mint-300)
 *     - kind 'tool'         → tool-call line, lavender accented
 *     - kind 'status'       → KNVB-orange "ing…" status line
 *   - cursor:    boolean — render a blinking cursor at the bottom
 *   - mode:      string — bottom strip caption ("accept edits on…")
 */

import React from 'react';
import styles from './AgentTrace.module.css';

function bulletClass(b) {
  switch (b) {
    case 'done':   return styles.bDone;
    case 'info':   return styles.bInfo;
    case 'orange': return styles.bOrange;
    case 'mint':   return styles.bMint;
    default:       return styles.bDone;
  }
}

export default function AgentTrace({lines = [], cursor = false, mode, className}) {
  return (
    <div className={[styles.trace, className].filter(Boolean).join(' ')} role="img" aria-label="Agent trace">
      <div className={styles.body}>
        {lines.map((ln, i) => {
          const indent = ln.indent ? {paddingLeft: `${ln.indent * 18}px`} : undefined;
          if (ln.kind === 'expand') {
            return (
              <div key={i} className={[styles.line, styles.expand].join(' ')} style={indent}>
                <span className={styles.expandRail} aria-hidden="true">└</span>
                <span className={styles.muted}>{ln.text}</span>
              </div>
            );
          }
          if (ln.kind === 'continuation') {
            return (
              <div key={i} className={[styles.line, styles.continuation].join(' ')} style={indent}>
                <span className={styles.continuationText}>{ln.text}</span>
              </div>
            );
          }
          if (ln.kind === 'tool') {
            return (
              <div key={i} className={[styles.line, styles.tool].join(' ')} style={indent}>
                <span className={[styles.bullet, styles.bDone].join(' ')} aria-hidden="true">•</span>
                <span className={styles.toolText}>{ln.text}</span>
              </div>
            );
          }
          if (ln.kind === 'status') {
            return (
              <div key={i} className={[styles.line, styles.status].join(' ')} style={indent}>
                <span className={[styles.bullet, styles.bOrange].join(' ')} aria-hidden="true">•</span>
                <span className={styles.statusText}>{ln.text}<span className={styles.dots} aria-hidden="true">...</span></span>
              </div>
            );
          }
          /* default: 'note' */
          return (
            <div key={i} className={[styles.line, styles.note].join(' ')} style={indent}>
              <span className={[styles.bullet, bulletClass(ln.bullet)].join(' ')} aria-hidden="true">•</span>
              <span className={styles.noteText}>
                {ln.text}
                {ln.muted && <span className={styles.muted}> {ln.muted}</span>}
              </span>
            </div>
          );
        })}
        {cursor && (
          <div className={[styles.line, styles.cursorLine].join(' ')}>
            <span className={styles.prompt}>&gt;</span>
            <span className={styles.cursor} aria-hidden="true"></span>
          </div>
        )}
      </div>
      {mode && (
        <div className={styles.modeBar}>
          <span className={styles.modeArrow} aria-hidden="true">&gt;&gt;</span>
          <span className={styles.modeText}>{mode}</span>
        </div>
      )}
    </div>
  );
}
