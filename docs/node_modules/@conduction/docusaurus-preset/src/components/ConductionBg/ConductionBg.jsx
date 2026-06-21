/**
 * <ConductionBg />
 *
 * Parallax honeycomb background. Drops depth-tiered hex spans inside a
 * position:absolute layer that fills its closest position:relative
 * ancestor. Hexes drift on scroll (smaller hexes = smaller depth = read
 * as further away) and breathe in opacity.
 *
 * Mirrors preview/conduction-bg.html in the kit. The runtime-script
 * version still ships under sites/www/static/lib/ for plain-HTML
 * surfaces (the preview pages); this React component is the canonical
 * way to use the pattern from MDX or any Docusaurus swizzle.
 *
 * Per huisstijl: pointy-top only, never rotated.
 *
 * Usage in MDX:
 *
 *   <Section background="inverse" spacing="default">
 *     <ConductionBg />        {/* white hexes for cobalt surfaces *\/}
 *     <h2 style={{position: 'relative', zIndex: 1}}>Pick an app.</h2>
 *   </Section>
 *
 *   <ConductionBg theme="on-light" count={12} />  {/* cobalt hexes for white surfaces *\/}
 *
 * Note: the parent must be position:relative + overflow:hidden for the
 * hexes to clip and the scroll-tied parallax to feel right.
 */

import React, {useEffect, useRef} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';
import styles from './ConductionBg.module.css';

/* Depth tiers picked to match the kit runtime. Smaller hexes get smaller
   depth so they translate less per scroll-pixel — that's what reads
   as "further away". Keep the weights in sync with conduction-bg.js. */
const TIERS = [
  {d: 0.10, sMin: 32,  sMax: 56,  oMin: 0.03, oMax: 0.10, w: 5},
  {d: 0.25, sMin: 56,  sMax: 96,  oMin: 0.04, oMax: 0.13, w: 4},
  {d: 0.45, sMin: 96,  sMax: 150, oMin: 0.05, oMax: 0.16, w: 3},
  {d: 0.65, sMin: 150, sMax: 220, oMin: 0.07, oMax: 0.18, w: 2},
  {d: 0.85, sMin: 220, sMax: 300, oMin: 0.08, oMax: 0.20, w: 1},
];
const PARALLAX_FACTOR = 0.4;
const TIER_TOTAL = TIERS.reduce((s, t) => s + t.w, 0);

const rand = (a, b) => Math.random() * (b - a) + a;
function pickTier() {
  let r = Math.random() * TIER_TOTAL;
  for (const t of TIERS) { if ((r -= t.w) <= 0) return t; }
  return TIERS[0];
}

export default function ConductionBg({theme = 'default', count = 18, className}) {
  const isBrowser = useIsBrowser();
  const ref = useRef(null);

  useEffect(() => {
    if (!isBrowser || !ref.current) return;
    const bg = ref.current;
    const host = bg.parentElement;
    if (!host) return;

    /* Idempotency guard: re-mounting the same wrapper (e.g. SPA route
       change that re-runs the effect) shouldn't double up the hexes. */
    if (bg.dataset.cbgHydrated === '1') return;
    bg.dataset.cbgHydrated = '1';

    const onLight = theme === 'on-light';
    /* Cobalt-on-white reads stronger per opacity-unit than white-on-cobalt;
       scale back the on-light range so the field stays a soft veil. */
    const opaScale = onLight ? 0.55 : 1.0;
    const reduceMotion = typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const accentIndex = Math.floor(count / 2);
    const hexes = [];
    for (let i = 0; i < count; i++) {
      const tier = pickTier();
      const size = rand(tier.sMin, tier.sMax);
      const isAccent = (i === accentIndex);
      const hex = document.createElement('span');
      hex.className = isAccent ? styles.hex + ' ' + styles.accent : styles.hex;
      hex.style.width = size + 'px';
      hex.style.height = (size * 1.1547) + 'px';
      hex.style.left = rand(-5, 105) + '%';
      hex.style.top = rand(-25, 125) + '%';
      hex.style.setProperty('--cbg-opa-min', (tier.oMin * opaScale).toFixed(3));
      hex.style.setProperty(
        '--cbg-opa-max',
        ((isAccent ? Math.min(tier.oMax + 0.05, 0.28) : tier.oMax) * opaScale).toFixed(3)
      );
      hex.style.setProperty('--cbg-dur', rand(11, 18).toFixed(1) + 's');
      hex.style.setProperty('--cbg-delay', rand(-18, 0).toFixed(1) + 's');
      hex.dataset.depth = tier.d;
      bg.appendChild(hex);
      hexes.push(hex);
    }

    if (reduceMotion) return undefined;

    /* Scroll-tied parallax. Only run while the host is in viewport
       (intersection observer flips `visible`). */
    let visible = false;
    let ticking = false;

    function update() {
      const rect = host.getBoundingClientRect();
      const offset = (rect.top + rect.height / 2) - window.innerHeight / 2;
      for (const h of hexes) {
        const depth = parseFloat(h.dataset.depth);
        const ty = -offset * depth * PARALLAX_FACTOR;
        h.style.transform = `translate(-50%, -50%) translateY(${ty.toFixed(1)}px)`;
      }
      ticking = false;
    }

    function onScroll() {
      if (!visible || ticking) return;
      ticking = true;
      requestAnimationFrame(update);
    }

    const io = new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting;
      if (visible) update();
    }, {rootMargin: '300px 0px'});
    io.observe(host);

    window.addEventListener('scroll', onScroll, {passive: true});
    window.addEventListener('resize', onScroll, {passive: true});
    update();

    return () => {
      io.disconnect();
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
      /* Clean up the injected nodes so a remount starts fresh. */
      for (const h of hexes) h.remove();
      delete bg.dataset.cbgHydrated;
    };
  }, [isBrowser, theme, count]);

  const composed = [
    styles.bg,
    theme === 'on-light' ? styles.onLight : null,
    className,
  ].filter(Boolean).join(' ');

  return <div ref={ref} className={composed} aria-hidden="true" />;
}
