/**
 * <LeafCard /> + <LeafGrid />
 *
 * Metadata header for an Open Register leaf integration. Mirrors the
 * registry descriptor (id, label, icon, group, requiredApp, storage)
 * so every per-leaf docs page leads with the same compact summary —
 * one card, six fields, no prose to skim. Used at the top of every
 * docs/Integrations/{leaf}.md page in the openregister docs site.
 *
 * Used on:
 *   - /docs/Integrations/{leaf}              (one <LeafCard />)
 *   - /docs/Integrations/leaf-system        (a <LeafGrid /> of all 18+1)
 *
 * Brand:
 *   - Pointy-top hex carries the leaf's MDI icon (cobalt by default,
 *     orange for the one accent-leaf when grouped). Falls back to the
 *     `id` initial when no icon is given.
 *   - Status pill colours: green = backend-ready, amber = stub,
 *     cobalt = external (OpenConnector-routed), grey = built-in.
 *
 * Usage in MDX:
 *
 *   import {LeafCard} from '@conduction/docusaurus-preset/components';
 *
 *   <LeafCard
 *     id="calendar"
 *     label="Meetings"
 *     icon="Calendar"
 *     group="comms"
 *     requiredApp="calendar"
 *     storage="link-table"
 *     status="backend-ready" />
 *
 * <LeafGrid /> renders a responsive grid of LeafCard children for
 * the overview page:
 *
 *   <LeafGrid>
 *     <LeafCard id="calendar" ... />
 *     <LeafCard id="contacts" ... />
 *   </LeafGrid>
 */

import React from 'react';
import styles from './LeafCard.module.css';

const STATUS_LABELS = {
  'backend-ready': 'Backend ready',
  'stub': 'Provider stub',
  'external': 'External (OpenConnector)',
  'built-in': 'Built-in',
};

const GROUP_LABELS = {
  'core': 'Core',
  'comms': 'Communication',
  'docs': 'Documents',
  'workflow': 'Workflow',
  'external': 'External',
};

const STORAGE_LABELS = {
  'magic-column': 'Magic column',
  'link-table': 'Link table',
  'external': 'External (no local store)',
  'query-time': 'Query-time (live)',
};

/**
 * Render a single MDI icon glyph by name, with a graceful fallback
 * to the first letter of the leaf id when the icon name is unknown.
 * The docs site doesn't ship the full MDI set, so unknown icons fall
 * back to a text initial rather than 404'ing on a missing asset.
 */
function LeafGlyph({icon, id}) {
  if (icon) {
    // Defer the actual icon rendering to the consuming docs site
    // (which can swizzle this if it wires up vue-material-design-icons
    // or any equivalent React icon pack). Default: render the icon
    // name as a small uppercase tag so the page still reads.
    return <span className={styles.iconText} aria-hidden="true">{icon.charAt(0)}</span>;
  }
  return <span className={styles.iconText} aria-hidden="true">{(id || '?').charAt(0).toUpperCase()}</span>;
}

export function LeafCard({
  id,
  label,
  icon,
  group,
  requiredApp,
  storage,
  status,
  href,
  description,
  className,
}) {
  const statusLabel = STATUS_LABELS[status] || status;
  const groupLabel = GROUP_LABELS[group] || group;
  const storageLabel = STORAGE_LABELS[storage] || storage;
  const composed = [styles.card, styles['status-' + (status || 'unknown')], className].filter(Boolean).join(' ');

  const inner = (
    <>
      <div className={styles.head}>
        <div className={styles.hex} aria-hidden="true">
          <LeafGlyph icon={icon} id={id} />
        </div>
        <div className={styles.heading}>
          <div className={styles.label}>{label}</div>
          <code className={styles.id}>{id}</code>
        </div>
        {status && <span className={styles.statusPill}>{statusLabel}</span>}
      </div>
      {description && <p className={styles.description}>{description}</p>}
      <dl className={styles.meta}>
        {group && (
          <>
            <dt>Group</dt>
            <dd>{groupLabel}</dd>
          </>
        )}
        {requiredApp !== undefined && (
          <>
            <dt>Required app</dt>
            <dd>{requiredApp ? <code>{requiredApp}</code> : <span className={styles.muted}>None (always available)</span>}</dd>
          </>
        )}
        {storage && (
          <>
            <dt>Storage</dt>
            <dd>{storageLabel}</dd>
          </>
        )}
        {icon && (
          <>
            <dt>Icon</dt>
            <dd><code>{icon}</code></dd>
          </>
        )}
      </dl>
    </>
  );

  // Render as link when href is set so leaves on the overview grid
  // navigate to their dedicated page on click.
  if (href) {
    return <a href={href} className={composed}>{inner}</a>;
  }
  return <div className={composed}>{inner}</div>;
}

export function LeafGrid({columns = 3, children, className}) {
  const composed = [styles.grid, styles['grid-' + columns], className].filter(Boolean).join(' ');
  return <div className={composed}>{children}</div>;
}

export default LeafCard;
