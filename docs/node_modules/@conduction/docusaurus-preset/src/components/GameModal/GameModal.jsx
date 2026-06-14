/**
 * <GameModal />
 *
 * The end-of-game dialog for the Conduction mini-games (hex-rain, the
 * canal-footer boats, future invaders / domino / hex-tetris). Mounts
 * once per page; listens for `connext:gameend` CustomEvents (the event
 * name is a brand-internal identifier, retained for compatibility),
 * opens
 * with the matching copy, tracks total games found in localStorage so
 * the cross-site progress bar is consistent.
 *
 * Mirrors preview/components/game-modal.html. The previous JS-only
 * version (game-modal.js) gets replaced by this component on any
 * Docusaurus surface that mounts <GameModal/>.
 *
 * Game payload shape, fired by the playing component:
 *
 *   window.dispatchEvent(new CustomEvent('connext:gameend', {
 *     detail: {
 *       id: 'hexrain',
 *       won: true,                 // or false
 *       score: 12,                 // numeric
 *       summary: '12 / 12 collected'
 *     }
 *   }));
 *
 * Usage in MDX (mount once on the layout, not per page):
 *
 *   import {GameModal} from '@conduction/docusaurus-preset/components';
 *   <GameModal />
 *
 * Custom games table (default covers the five planned mini-games):
 *
 *   <GameModal games={[
 *     {id: 'hexrain',  label: 'Twelve apps'},
 *     {id: 'boats',    label: 'Sink the boats'},
 *     {id: 'invaders', label: 'Hex-vaders'},
 *     {id: 'domino',   label: 'Hex-domino'},
 *     {id: 'tetris',   label: 'Hex-tris'},
 *   ]} />
 */

import React, {useEffect, useState, useCallback, useMemo} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';
import Translate, {translate} from '@docusaurus/Translate';
import styles from './GameModal.module.css';

const STORAGE_KEY = 'conduction:minigames';

const DEFAULT_GAMES = [
  {id: 'hexrain',      label: 'Twelve apps · hex rain'},
  {id: 'boats',        label: 'Sink the boats · footer canal'},
  {id: 'invaders',     label: 'Hex-vaders · cookie CLI'},
  {id: 'logo-memory',  label: 'Logo memory · clients marquee'},
  {id: 'kade-cyclist', label: 'Kade cyclist · footer kade'},
];

function readFound() {
  if (typeof window === 'undefined') return {};
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch (e) { return {}; }
}

function writeFound(found) {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(found));
  } catch (e) {/* fail open */}
}

export default function GameModal({games = DEFAULT_GAMES, className}) {
  const isBrowser = useIsBrowser();
  const [open, setOpen] = useState(false);
  const [event, setEvent] = useState(null);
  const [found, setFound] = useState({});

  /* On mount: read found-games table from localStorage and subscribe
     to the `connext:gameend` event. Each event opens the modal with
     the supplied copy and (if won) marks the game as found. */
  useEffect(() => {
    if (!isBrowser) return;
    setFound(readFound());

    function onEnd(e) {
      const detail = e.detail || {};
      setEvent(detail);
      setOpen(true);
      /* Discovery vs. victory: any game-end counts the game as "found"
         because a few of the games (kade-cyclist, future endless
         runners) never reach a clean win state. The scoreboard pill
         still reflects the actual performance for that round. */
      if (detail.id) {
        setFound((prev) => {
          if (prev[detail.id]) return prev;
          const next = {...prev, [detail.id]: true};
          writeFound(next);
          return next;
        });
      }
    }
    window.addEventListener('connext:gameend', onEnd);
    return () => window.removeEventListener('connext:gameend', onEnd);
  }, [isBrowser]);

  /* Escape closes the modal. */
  useEffect(() => {
    if (!isBrowser || !open) return;
    function onKey(e) { if (e.key === 'Escape') setOpen(false); }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isBrowser, open]);

  const close = useCallback(() => {
    /* Notify the playing runtime so it can tear down its in-page UI
       (lifted hexes, kade stage, etc.) and restore the original
       surface. Without this the marquee or kade stays in its game-
       over visual until the next page load. */
    if (event?.id) {
      Promise.resolve().then(() => {
        window.dispatchEvent(new CustomEvent('connext:gameclose', {detail: {id: event.id}}));
      });
    }
    setOpen(false);
  }, [event]);
  const replay = useCallback(() => {
    /* Fire a `connext:gamereplay` event so the playing component (which
       listens for it) can re-init. We don't dispatch from inside an
       event handler that came from React, so use a microtask to keep
       the call stack clean. */
    if (event?.id) {
      Promise.resolve().then(() => {
        window.dispatchEvent(new CustomEvent('connext:gamereplay', {detail: {id: event.id}}));
      });
    }
    setOpen(false);
  }, [event]);

  const foundCount = useMemo(() => Object.values(found).filter(Boolean).length, [found]);
  const total = games.length;
  const percent = total > 0 ? Math.round((foundCount / total) * 100) : 0;

  if (!isBrowser || !open || !event) return null;

  const eyebrow = event.won
    ? translate({id: 'preset.gameModal.eyebrow.won', message: 'Mini-game complete', description: 'Eyebrow above the game-over heading when the player won'})
    : translate({id: 'preset.gameModal.eyebrow.lost', message: 'Game over', description: 'Eyebrow above the game-over heading when the player lost'});
  const title = event.title || (event.won
    ? translate({id: 'preset.gameModal.title.won', message: 'Nice run.', description: 'Default headline on the game-over modal when the player won'})
    : translate({id: 'preset.gameModal.title.lost', message: "That's all of them.", description: 'Default headline on the game-over modal when the player lost'}));
  const subtitle = event.subtitle ||
    (event.won
      ? translate({id: 'preset.gameModal.subtitle.won', message: "You've cleared a hidden Conduction mini-game.", description: 'Default subtitle on the game-over modal when the player won'})
      : translate({id: 'preset.gameModal.subtitle.lost', message: "Try again any time, the rain doesn't stop.", description: 'Default subtitle on the game-over modal when the player lost'}));

  return (
    <div className={[styles.modal, className].filter(Boolean).join(' ')} role="dialog" aria-modal="true" aria-labelledby="gm-title">
      <div className={styles.overlay} onClick={close} />
      <div className={styles.panel}>
        <button type="button" className={styles.close} onClick={close} aria-label={translate({id: 'preset.gameModal.close', message: 'Close', description: 'Accessible label for the close (×) button on the game-over modal'})}>×</button>
        <p className={styles.eyebrow}>{eyebrow}</p>
        <h2 className={styles.title} id="gm-title">{title}</h2>
        <p className={styles.subtitle}>{subtitle}</p>

        {typeof event.score !== 'undefined' && (
          <span className={styles.scorePill}>{event.summary || translate({id: 'preset.gameModal.scorePill', message: 'score: {score}', description: 'Default score pill text. {score} is the numeric score.'}, {score: event.score})}</span>
        )}

        <div className={styles.progress}>
          <div className={styles.progressLabel}>
            <span>
              <Translate
                id="preset.gameModal.progress.found"
                description="Progress label below the game-over copy. {found} bolded count of games discovered; {total} is the total."
                values={{
                  found: <strong>{foundCount}</strong>,
                  total: total,
                }}>
                {'{found} / {total} mini-games found'}
              </Translate>
            </span>
            <span>{percent}%</span>
          </div>
          <div className={styles.progressBar}>
            <div className={styles.progressFill} style={{width: percent + '%'}} />
          </div>
        </div>

        <ul className={styles.grid}>
          {games.map((g) => (
            <li key={g.id} className={found[g.id] ? styles.gridItemFound : styles.gridItem}>
              <span className={styles.gridHex} aria-hidden="true" />
              <span>{g.label}</span>
            </li>
          ))}
        </ul>

        <p className={styles.cta}>
          {foundCount < total
            ? translate(
                {
                  id: 'preset.gameModal.cta.remaining',
                  message: '{remaining, plural, one {# more game hidden somewhere. Keep clicking.} other {# more games hidden somewhere. Keep clicking.}}',
                  description: 'CTA text on the game-over modal when at least one game is still hidden. {remaining} is the count of games still hidden.',
                },
                {remaining: total - foundCount},
              )
            : translate({id: 'preset.gameModal.cta.allFound', message: 'All five found. You read the kit.', description: 'CTA text on the game-over modal when the player has discovered every mini-game.'})}
        </p>

        <div className={styles.actions}>
          <button type="button" className={styles.btnSecondary} onClick={close}>
            <Translate id="preset.gameModal.action.close" description="Close button label on the game-over modal">Close</Translate>
          </button>
          <button type="button" className={styles.btnPrimary} onClick={replay}>
            <Translate id="preset.gameModal.action.replay" description="Replay button label on the game-over modal">Play again</Translate>
          </button>
        </div>
      </div>
    </div>
  );
}
