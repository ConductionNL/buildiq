/**
 * <Pipeline /> + <PipelineStep /> + <IconList />
 *
 * Horizontal pipeline of flat hex tiles, family-coloured per step,
 * mirroring preview/components/pipeline-flow.html. Each step has a
 * stage number + uppercase kicker above the hex, an explanatory
 * name + caption below, and (optionally) a small pill inside the
 * hex (e.g. "files · users · auth" on a workspace step). A single
 * animated dotted flow line runs full-width behind the hex row,
 * threading through every hex equator.
 *
 * Family palette follows the locked PRISM-FAMILY POLICY in tokens.css:
 *   mint        integrate / connect    (OpenConnector)
 *   forest      data / registers       (OpenRegister)
 *   terracotta  documents / search     (OpenCatalogi)
 *   lavender    process / views        (LaunchPad)
 *   workspace   Nextcloud workspace    (centre platform hex)
 *
 * Sources / consumers end-boxes use <IconList />, also exported on its
 * own so the same kit-styled "label + bordered list of icon-tagged
 * items" pattern can be dropped anywhere outside the pipeline.
 *
 * Each item can be a plain string or an object:
 *   - string                  → default doc icon + label
 *   - {label, icon: 'doc'}    → built-in glyph by name (see GLYPHS)
 *   - {label, icon: <svg/>}   → custom React node (rendered as-is,
 *                                without the bordered tile wrapper)
 *
 * Usage in MDX:
 *
 *   <Pipeline
 *     start={{label: 'Sources', items: [
 *       {label: 'Spreadsheets', icon: 'doc'},
 *       {label: 'Databases', icon: 'db'},
 *       {label: 'REST / SOAP', icon: 'api'},
 *       {label: 'Legacy', icon: 'legacy'},
 *     ]}}
 *     steps={[
 *       {number: '01', kicker: 'Ingest', name: 'OpenConnector',
 *         caption: 'Pulls schemas, REST, SOAP, files.'},
 *       {number: 'PLATFORM', name: 'Nextcloud', family: 'workspace',
 *         pill: 'files · users · auth'},
 *       {number: '02', kicker: 'Present', name: 'OpenCatalogi',
 *         caption: 'Indexes every register.'},
 *     ]}
 *     end={{label: 'Consumers', items: [
 *       {label: 'Citizens', icon: 'person'},
 *       {label: 'APIs', icon: 'api'},
 *       {label: 'Open data', icon: 'download'},
 *     ]}}
 *   />
 */

import React from 'react';
import styles from './Pipeline.module.css';

/* ===== Family palette =====
   Locked PRISM-FAMILY POLICY (tokens.css). fill = -300, ink = -700. */
const FAMILY_COLORS = {
  mint:       { fill: '#87CFA8', ink: '#155234' },
  forest:     { fill: '#7DAA7C', ink: '#1E461C' },
  terracotta: { fill: '#DA9D8A', ink: '#6E2A1C' },
  lavender:   { fill: '#B7A7E3', ink: '#483982' },
  workspace:  { fill: '#67BEEA', ink: '#014C77' },
};

const FAMILY_LABEL = {
  mint:       'INTEGRATE',
  forest:     'DATA',
  terracotta: 'SEARCH',
  lavender:   'VIEWS',
  workspace:  'WORKSPACE',
};

function inferFamily(name) {
  const n = (name || '').toLowerCase();
  if (n.includes('connector')) return 'mint';
  if (n.includes('register')) return 'forest';
  if (n.includes('catalog')) return 'terracotta';
  if (n.includes('launchpad') || n.includes('dashboard')) return 'lavender';
  if (n.includes('nextcloud') || n.includes('workspace') || n.includes('platform')) return 'workspace';
  return 'forest';
}

/* ===== Hex tile ===== */
/* Flat pointy-top hex (no extruded sides). Geometry mirrors the kit's
   top-face polygon so the silhouette matches the rest of the brand.
   When `pill` is supplied the family sub-label is replaced with a
   small white pill — kit-identical to the workspace hex's
   "files · users · auth" tag. */
function HexTile({name, family, pill}) {
  const c = FAMILY_COLORS[family] || FAMILY_COLORS.forest;
  const sub = FAMILY_LABEL[family];
  return (
    <svg viewBox="0 0 120 86" className={styles.prism} aria-hidden="true">
      <polygon points="60,4 96,24 96,62 60,82 24,62 24,24" fill={c.fill} />
      <text x="60" y="40" textAnchor="middle" fontSize="10" fontWeight="700" fill={c.ink} letterSpacing="-0.02em">{name}</text>
      {pill ? (
        <>
          <rect x="22" y="48" width="76" height="14" rx="7" fill="white" fillOpacity="0.94" />
          <text x="60" y="58" textAnchor="middle" fontSize="7" fontWeight="600" fill={c.ink}>{pill}</text>
        </>
      ) : sub ? (
        <text x="60" y="58" textAnchor="middle" fontFamily="ui-monospace, Menlo, monospace" fontSize="7" letterSpacing="1.4" fill={c.ink}>{sub}</text>
      ) : null}
    </svg>
  );
}

export function PipelineStep({number, kicker, name, caption, family, pill, className}) {
  const fam = family || inferFamily(name);
  return (
    <div className={[styles.step, className].filter(Boolean).join(' ')}>
      {number && <div className={styles.num}>{number}</div>}
      {kicker && <div className={styles.kicker}>{kicker}</div>}
      <HexTile name={name} family={fam} pill={pill} />
      {name && <div className={styles.name}>{name}</div>}
      {caption && <div className={styles.caption}>{caption}</div>}
    </div>
  );
}

/* ===== Item icons =====
   Built-in glyph presets with kit-identical colours: blue-cobalt
   stroke (#21468B) on a 22x22 white tile with a cobalt-200 hairline
   border. Match the four-icon source / four-icon consumer set used
   by preview/components/pipeline-flow.html. */
const STROKE = 'var(--c-blue-cobalt)';
const GLYPHS = {
  /* Sources */
  doc:      <path d="M6 8h10 M6 11h10 M6 14h6" stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round" />,
  db:       <g stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round"><rect x="6" y="7" width="10" height="9"/><path d="M8 11h6 M8 13h6"/></g>,
  api:      <path d="M11 8a3 3 0 0 1 3 3h-6a3 3 0 0 1 3-3z M7 14h8" stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round" />,
  legacy:   <path d="M7 16V9l4-2 4 2v7z" stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round" />,
  /* Consumers */
  person:   <g stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round"><circle cx="11" cy="9" r="2.5"/><path d="M7 17a4 4 0 0 1 8 0"/></g>,
  download: <path d="M11 6v8 M7 11l4 4 4-4 M7 17h8" stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round" />,
  embed:    <g stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round"><rect x="5" y="6" width="12" height="10" rx="1"/><path d="M5 9h12"/></g>,
  link:     <g stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round"><path d="M9 13a4 4 0 0 1 0-6l2-2"/><path d="M13 9a4 4 0 0 1 0 6l-2 2"/></g>,
  globe:    <g stroke={STROKE} strokeWidth="1.4" fill="none" strokeLinecap="round"><circle cx="11" cy="11" r="5"/><path d="M6 11h10 M11 6a8 8 0 0 1 0 10 M11 6a8 8 0 0 0 0 10"/></g>,
};

/* Bordered icon tile (white fill, cobalt-200 stroke) wrapping a glyph. */
function IconTile({glyph}) {
  return (
    <svg viewBox="0 0 22 22" aria-hidden="true">
      <rect x="0.5" y="0.5" width="21" height="21" rx="3" fill="white" stroke="var(--c-cobalt-200)" strokeWidth="1" />
      {glyph}
    </svg>
  );
}

function resolveIcon(spec) {
  if (!spec) return <IconTile glyph={GLYPHS.doc} />;
  if (typeof spec === 'string') {
    const g = GLYPHS[spec];
    return <IconTile glyph={g || GLYPHS.doc} />;
  }
  if (React.isValidElement(spec)) return spec;
  return <IconTile glyph={GLYPHS.doc} />;
}

/* ===== IconList =====
   Reusable bordered list with a label header and icon-tagged items.
   Used by Pipeline as its source / consumer end-boxes; exported so
   the same pattern can be dropped in standalone elsewhere. */
export function IconList({label, items = [], className}) {
  return (
    <div className={[styles.end, className].filter(Boolean).join(' ')}>
      {label && <div className={styles.endLabel}>{label}</div>}
      <ul className={styles.endList}>
        {items.map((it, i) => {
          const item = typeof it === 'string' ? {label: it} : (it || {});
          return (
            <li key={i}>
              <span className={styles.endIcon}>{resolveIcon(item.icon)}</span>
              <span>{item.label}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export default function Pipeline({start, end, steps, children, className}) {
  const stepNodes = steps
    ? steps.map((s, i) => <PipelineStep key={i} {...s} />)
    : children;

  return (
    <div className={[styles.pipeline, className].filter(Boolean).join(' ')}>
      {start && <IconList {...start} />}
      <div className={styles.steps}>{stepNodes}</div>
      {end && <IconList {...end} />}
    </div>
  );
}
