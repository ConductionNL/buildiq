/**
 * useLazyStylesheet: load a runtime stylesheet after React hydration.
 *
 * Mirror of useLazyScript for CSS. The preset's heavier decorative
 * stylesheets (canal-footer, kade-cyclist, hex-rain, logo-memory,
 * platform-diagram) were injected via `<Head><link rel="stylesheet" />`,
 * which Docusaurus SSGs into the rendered `<head>` and the browser
 * treats as render-blocking. With five of these on a site that loads
 * any landing page, LCP and TBT both regress past the "good" CWV
 * threshold (LCP < 2.5s, INP < 200ms).
 *
 * Injecting via useEffect moves the load past hydration, so the
 * stylesheet downloads in parallel with first paint instead of
 * blocking it. Trade-off: a brief style-less flash before the CSS
 * applies. Mitigated for above-fold callers by inlining the small
 * structural rules into brand.css and only lazy-loading the
 * decorative parts.
 *
 * The injected link has data-lazy-stylesheet="<key>" so SPA route
 * changes don't double-inject, mirroring useLazyScript's idempotency.
 */

import {useEffect} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';

/**
 * Pass a falsy `href` to skip the load entirely. The hook still runs
 * unconditionally so call sites stay rules-of-hooks-compliant.
 */
export function useLazyStylesheet(href, key) {
  const isBrowser = useIsBrowser();
  useEffect(() => {
    if (!isBrowser) return;
    if (!href) return;
    if (document.querySelector(`link[data-lazy-stylesheet="${key}"]`)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.lazyStylesheet = key;
    document.head.appendChild(link);
  }, [isBrowser, href, key]);
}
