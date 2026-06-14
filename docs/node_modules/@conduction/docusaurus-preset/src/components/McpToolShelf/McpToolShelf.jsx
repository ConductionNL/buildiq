/**
 * <McpToolShelf />
 *
 * Section that lists the MCP tools a Conduction app contributes to
 * the workspace AI chat companion (hydra ADR-034). Each Conduction
 * app implements OpenRegister's IMcpToolProvider and registers a
 * handful of tools; this section makes that promise concrete by
 * listing the tools the app actually exposes, alongside a sample
 * <AgentTrace> showing one of those tools being called.
 *
 * The component is presentational only. The tool list is curated in
 * MDX per app, not pulled from the live provider. Status hex follows
 * the same legend as <FeatureGrid> (stable / beta / soon).
 *
 * The `showIds` prop hides the namespaced tool id by default. On
 * conduction.nl product pages, the audience is MKB-beslisser and the
 * id is noise. On per-app docs sites (docs.decidesk.app, etc.), the
 * audience is developers and the id should be shown — pass
 * `showIds={true}`.
 *
 * Usage in MDX:
 *
 *   import {McpToolShelf, AgentTrace} from '@conduction/docusaurus-preset/components';
 *
 *   <McpToolShelf
 *     eyebrow="AI chat tools"
 *     title="What this app adds to the workspace AI."
 *     lede="Install OpenRegister and the chat gets five new tools..."
 *     tools={[
 *       {
 *         icon: <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.5-4.5"/></svg>,
 *         name: 'Search registers',
 *         id: 'openregister.search',
 *         description: 'Plain-language search across every register.',
 *         status: 'stable',
 *       },
 *       // ...
 *     ]}
 *     showIds={false}
 *     trace={<AgentTrace lines={[...]} cursor mode="audit log on" />}
 *   />
 *
 * Props:
 *   - eyebrow:  Plex Mono caption above the title (optional)
 *   - title:    headline (string or ReactNode)
 *   - lede:     body copy under the title (optional)
 *   - tools:    array of {icon, name, id?, description, status}
 *               status ∈ 'stable' (default) | 'beta' | 'soon'
 *   - showIds:  render the namespaced tool id as a monospace
 *               sub-label (default false)
 *   - trace:    a rendered <AgentTrace> instance to show on the
 *               right. Omit and the component renders a generic
 *               default trace built from the first tool in the list.
 *   - mode:     bottom-strip caption for the default trace, if
 *               `trace` is not provided
 */

import React from 'react';
import AgentTrace from '../AgentTrace/AgentTrace.jsx';
import styles from './McpToolShelf.module.css';

const STATUS_CLASSES = {stable: '', beta: styles.beta, soon: styles.soon};

function buildDefaultTrace(tools, mode) {
  const first = tools && tools[0];
  if (!first) return null;
  const appId = (first.id && first.id.split('.')[0]) || 'app';
  const toolName = first.id ? first.id.split('.').slice(1).join('.') : (first.name || 'tool');
  return (
    <AgentTrace
      lines={[
        {kind: 'tool', text: `${appId} - ${toolName} (MCP)()`},
        {kind: 'note', bullet: 'done', text: 'Returned a typed result'},
        {kind: 'status', text: 'Synthesising answer'},
      ]}
      cursor
      mode={mode || 'audit log on (every call recorded)'}
    />
  );
}

export default function McpToolShelf({
  eyebrow = 'AI chat tools',
  title,
  lede,
  tools = [],
  showIds = false,
  trace,
  mode,
  className,
}) {
  const resolvedTrace = trace || buildDefaultTrace(tools, mode);
  return (
    <section className={[styles.shelf, className].filter(Boolean).join(' ')}>
      {(eyebrow || title || lede) && (
        <header className={styles.head}>
          {eyebrow && (
            <div className={styles.eyebrow}>
              <span className={styles.eyebrowHex} aria-hidden="true" />
              {eyebrow}
            </div>
          )}
          {title && <h2 className={styles.title}>{title}</h2>}
          {lede && <p className={styles.lede}>{lede}</p>}
        </header>
      )}
      <div className={styles.layout}>
        <div className={styles.grid}>
          {tools.map((t, i) => {
            const statusClass = [styles.statusHex, STATUS_CLASSES[t.status || 'stable']]
              .filter(Boolean)
              .join(' ');
            return (
              <article key={i} className={styles.card}>
                <div className={styles.cardHead}>
                  {t.icon && (
                    <div className={styles.cardIcon} aria-hidden="true">
                      {t.icon}
                    </div>
                  )}
                  <div className={styles.cardTitleWrap}>
                    <h3 className={styles.cardName}>{t.name}</h3>
                    {showIds && t.id && <code className={styles.cardId}>{t.id}</code>}
                  </div>
                  <span className={statusClass} aria-hidden="true" />
                </div>
                {t.description && <p className={styles.cardDesc}>{t.description}</p>}
              </article>
            );
          })}
        </div>
        {resolvedTrace && (
          <aside className={styles.traceCol}>{resolvedTrace}</aside>
        )}
      </div>
      <div className={styles.legend}>
        <span className={styles.legendItem}>
          <span className={styles.statusHex} aria-hidden="true" />
          Stable
        </span>
        <span className={styles.legendItem}>
          <span className={[styles.statusHex, styles.beta].join(' ')} aria-hidden="true" />
          Beta
        </span>
        <span className={styles.legendItem}>
          <span className={[styles.statusHex, styles.soon].join(' ')} aria-hidden="true" />
          Coming soon
        </span>
      </div>
    </section>
  );
}
