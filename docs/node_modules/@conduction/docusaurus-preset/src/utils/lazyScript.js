/**
 * useLazyScript: load a runtime script after React hydration.
 *
 * The pattern used in this preset's heavier components (canal-footer,
 * hex-rain, platform-diagram) was to inject the runtime via
 * `<Head><script defer /></Head>`. Defer scripts run between HTML
 * parse and DOMContentLoaded, which is *before* React hydration in
 * production builds. The scripts mutate the DOM (filling .skyline,
 * upgrading custom elements, etc.), and the mutated DOM no longer
 * matches what React tries to hydrate, producing minified errors
 * #418 (hydration mismatch) + #423 (recovered by client re-render).
 *
 * Loading via this hook injects the <script> tag into <body> from a
 * useEffect, which fires *after* hydration completes. The script's
 * DOM mutations are then post-hydration and React doesn't re-validate.
 *
 * The injected tag has data-lazy-script="<key>" so SPA route changes
 * don't double-load the same runtime, and so consumers can listen
 * for `load` events on it (e.g. hex-rain calls window.HexRain.hydrate
 * once the runtime registers itself).
 */

import {useEffect} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';

/**
 * Pass a falsy `src` to skip the load entirely. The hook still runs
 * unconditionally so call sites stay rules-of-hooks-compliant: the
 * Footer swizzle uses this to gate /lib/kade-cyclist.js behind the
 * `minigames` themeConfig flag without splitting into conditional
 * hook calls.
 */
export function useLazyScript(src, key) {
  const isBrowser = useIsBrowser();
  useEffect(() => {
    if (!isBrowser) return;
    if (!src) return;
    if (document.querySelector(`script[data-lazy-script="${key}"]`)) return;
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.dataset.lazyScript = key;
    document.body.appendChild(script);
  }, [isBrowser, src, key]);
}
