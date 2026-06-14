/**
 * <CookieCli />
 *
 * Terminal-styled cookie consent banner. Mirrors the kit's
 * preview/components/cookie-cli.html: black terminal panel with green
 * prompt, IBM Plex Mono throughout, four cookie categories shown as
 * [1] [2] [3] [4] options, three action buttons.
 *
 * Persists choice in localStorage under a configurable key. Won't
 * re-render after a decision unless the page calls window
 * .ConductionCookieCli.reset().
 *
 * Per huisstijl: terminal dark IS the accent here. The Plex Mono
 * caption and the [1] [2] keys read as a Conduction tell.
 *
 * Usage in MDX (or anywhere in the app):
 *
 *   <CookieCli
 *     siteHost="conduction.nl"
 *     categories={[
 *       {key: 'essential',   label: 'essential',   tag: 'required', required: true, defaultOn: true},
 *       {key: 'analytics',   label: 'analytics',   tag: 'opt-in',   defaultOn: true},
 *       {key: 'preferences', label: 'preferences', tag: 'opt-in'},
 *       {key: 'marketing',   label: 'marketing',   tag: 'opt-in'},
 *     ]}
 *     onAccept={(selected) => { /* track or fire telemetry *\/ }}
 *   />
 *
 * The component reads + writes the selection from localStorage
 * automatically; <onAccept/> is called with the resolved selection
 * on every save.
 */

import React, {useState, useEffect, useMemo, useCallback} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';
import styles from './CookieCli.module.css';

const STORAGE_KEY = 'conduction:cookie-cli';

const DEFAULT_CATEGORIES = [
  {key: 'essential',   label: 'essential',   tag: 'required', required: true, defaultOn: true},
  {key: 'analytics',   label: 'analytics',   tag: 'opt-in',   defaultOn: false},
  {key: 'preferences', label: 'preferences', tag: 'opt-in',   defaultOn: false},
  {key: 'marketing',   label: 'marketing',   tag: 'opt-in',   defaultOn: false},
];

function readStored() {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (e) {
    return null;
  }
}

function writeStored(selection) {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
      ...selection,
      _ts: Date.now(),
    }));
  } catch (e) {
    /* localStorage may be disabled (private mode, quota); fail open */
  }
}

export default function CookieCli({
  siteHost = 'conduction.nl',
  categories = DEFAULT_CATEGORIES,
  onAccept,
  className,
}) {
  const isBrowser = useIsBrowser();

  const initial = useMemo(() => {
    const fromStore = readStored();
    if (fromStore) return fromStore;
    const fresh = {};
    for (const c of categories) fresh[c.key] = !!c.defaultOn;
    return fresh;
  }, [categories]);

  const [selected, setSelected] = useState(initial);
  const [decided, setDecided] = useState(() => readStored() != null);

  /* Sync local state to localStorage if the dataset arrived from
     storage on a fresh mount. Skipped on SSR to keep hydration stable. */
  useEffect(() => {
    if (!isBrowser) return;
    const stored = readStored();
    if (stored) {
      setSelected(stored);
      setDecided(true);
    }
  }, [isBrowser]);

  /* Expose a window.ConductionCookieCli.reset() so the privacy page
     can re-show the prompt when a user wants to change their choice. */
  useEffect(() => {
    if (!isBrowser) return;
    window.ConductionCookieCli = {
      reset: () => {
        try { window.localStorage.removeItem(STORAGE_KEY); } catch (e) {/* */}
        setDecided(false);
      },
      get: () => readStored(),
    };
    return () => { delete window.ConductionCookieCli; };
  }, [isBrowser]);

  const toggle = useCallback((cat) => {
    if (cat.required) return;
    setSelected((prev) => ({...prev, [cat.key]: !prev[cat.key]}));
  }, []);

  const save = useCallback((finalSelection) => {
    writeStored(finalSelection);
    setSelected(finalSelection);
    setDecided(true);
    if (typeof onAccept === 'function') onAccept(finalSelection);
  }, [onAccept]);

  const acceptAll = useCallback(() => {
    const all = {};
    for (const c of categories) all[c.key] = true;
    save(all);
  }, [categories, save]);

  const saveCurrent = useCallback(() => save(selected), [save, selected]);

  const rejectNonEssential = useCallback(() => {
    const minimal = {};
    for (const c of categories) minimal[c.key] = !!c.required;
    save(minimal);
  }, [categories, save]);

  /* Bind keyboard shortcuts: A = accept all, S = save, R = reject,
     1-9 = toggle category n. */
  useEffect(() => {
    if (!isBrowser || decided) return;
    function onKey(e) {
      if (e.metaKey || e.ctrlKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'a') { e.preventDefault(); acceptAll(); return; }
      if (k === 's') { e.preventDefault(); saveCurrent(); return; }
      if (k === 'r') { e.preventDefault(); rejectNonEssential(); return; }
      const idx = parseInt(e.key, 10);
      if (!Number.isNaN(idx) && idx >= 1 && idx <= categories.length) {
        e.preventDefault();
        toggle(categories[idx - 1]);
      }
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isBrowser, decided, acceptAll, saveCurrent, rejectNonEssential, toggle, categories]);

  /* Hide the banner once the user has made a decision; the prompt
     reappears via window.ConductionCookieCli.reset(). */
  if (!isBrowser || decided) return null;

  return (
    <div className={[styles.term, className].filter(Boolean).join(' ')} role="dialog" aria-label="Cookie consent">
      <div className={styles.bar}>
        <div className={styles.dots}>
          <span className={[styles.dot, styles.dotR].join(' ')} />
          <span className={[styles.dot, styles.dotY].join(' ')} />
          <span className={[styles.dot, styles.dotG].join(' ')} />
        </div>
        <div className={styles.title}>
          {'— '}<b>{siteHost}</b>{' · cookie-consent — bash —'}
        </div>
      </div>
      <div className={styles.body}>
        <div className={styles.prompt}>
          <span className={styles.user}>you</span>
          <span className={styles.sigil}>@</span>
          <span className={styles.host}>{siteHost}</span>
          <span className={styles.sigil}>:</span>
          <span className={styles.path}>~</span>
          <span className={styles.sigil}>$</span>{' '}
          <span className={styles.cmd}>cat ./cookies.toml</span>
        </div>
        <p className={styles.comment}># Pick what you're OK with. Essential cookies are required.</p>

        <ul className={styles.opts}>
          {categories.map((c, i) => {
            const on = !!selected[c.key] || !!c.required;
            return (
              <li
                key={c.key}
                className={on ? styles.optOn : null}
                onClick={() => toggle(c)}
                role="button"
                tabIndex={c.required ? -1 : 0}
                aria-pressed={on}
                style={c.required ? {cursor: 'default'} : undefined}
              >
                <span className={styles.optKey}>[{i + 1}]</span>
                <span className={styles.optCheck}>{on ? '✓' : '☐'}</span>
                <span className={styles.optLabel}>{c.label}</span>
                {c.tag && <span className={styles.optTag}>{c.tag}</span>}
              </li>
            );
          })}
        </ul>

        <div className={styles.actions}>
          <button type="button" className={[styles.btn, styles.btnPrimary].join(' ')} onClick={acceptAll}>
            <span className={styles.btnKey}>A</span>Accept all
          </button>
          <button type="button" className={styles.btn} onClick={saveCurrent}>
            <span className={styles.btnKey}>S</span>Save selection
          </button>
          <button type="button" className={styles.btn} onClick={rejectNonEssential}>
            <span className={styles.btnKey}>R</span>Reject non-essential
          </button>
        </div>
      </div>
    </div>
  );
}
