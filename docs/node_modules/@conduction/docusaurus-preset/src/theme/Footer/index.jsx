/**
 * Brand Footer swizzle — canal scene + brand block + link grid.
 *
 * Replaces Docusaurus's default footer with the full Conduction canal
 * footer pattern from preview/components/footer.html: a randomised
 * Amsterdam trapgevel skyline, a gray kade with bikes and cars drifting
 * along it, and a cobalt-900 canal with orange boats, a swimmer, a
 * hovercraft, and a hidden mini-game. The brand block + link grid ride
 * inside the canal so the wave layer reads behind the type.
 *
 * The visual scaffolding is JSX with global class names exactly matching
 * the rules in canal-footer.css (loaded site-side via the connext
 * customCss). The hidden <template> elements at the bottom are cloned
 * by canal-footer.js to populate the skyline and to spawn boats during
 * the mini-game.
 *
 * The structure is intentionally close to the source HTML so updates
 * to the kit can flow through with minimal translation work.
 */

import React, {useEffect} from 'react';
import Link from '@docusaurus/Link';
import {useLocation} from '@docusaurus/router';
import {useThemeConfig} from '@docusaurus/theme-common';
import useIsBrowser from '@docusaurus/useIsBrowser';
import Translate, {translate} from '@docusaurus/Translate';
import {brandFor} from '../brand.jsx';
import {useLazyScript} from '../../utils/lazyScript';
import {useLazyStylesheet} from '../../utils/lazyStylesheet';
import GameModal from '../../components/GameModal/GameModal';

function FooterLink({label, href, to}) {
  if (href) {
    const external = href.startsWith('http');
    return (
      <a
        href={href}
        target={external ? '_blank' : undefined}
        rel={external ? 'noopener noreferrer' : undefined}
      >
        {label}
      </a>
    );
  }
  return <Link to={to}>{label}</Link>;
}

export default function Footer() {
  const themeConfig = useThemeConfig();
  const {footer, navbar} = themeConfig;
  /* `minigames` and `footerBrand` are top-level themeConfig flags
     surfaced through createConfig() opts. See createConfig() in
     ../../index.js for the option semantics. */
  const minigamesOn = themeConfig.minigames !== false;
  const footerBrand = themeConfig.footerBrand || null;
  /* legalLinks: opt-in/out of the Privacy / Terms / ISO links inside
     the legal-bar, and the two ISO 9001/27001 certification badges on
     the right. Defaults preserve current behaviour for sites that ship
     those pages (docs.conduction.nl, www.conduction.nl). Consumer
     sites that don't ship them can pass { privacy: false, terms: false,
     iso: false, isoCertifications: false } via createConfig opts to
     silence broken-link warnings. Each link slot also accepts a string
     to override the destination (e.g. an external URL pointing at the
     canonical Conduction legal page). */
  const legalLinks = themeConfig.legalLinks || {};
  const legalLink = (key, fallback) => {
    if (legalLinks[key] === false) return null;
    if (typeof legalLinks[key] === 'string' && legalLinks[key]) return legalLinks[key];
    return fallback;
  };
  const privacyTo  = legalLink('privacy', '/privacy');
  const termsTo    = legalLink('terms',   '/terms');
  const isoTo      = legalLink('iso',     '/iso');
  /* ISO certification badges (the two 9001 + 27001 pills on the right
     of the legal-bar) default to following the iso link's visibility —
     no point claiming certifications when the linked detail page
     doesn't ship. Sites can force a state with
     legalLinks.isoCertifications: true | false. */
  const showIsoCerts = legalLinks.isoCertifications !== undefined
    ? Boolean(legalLinks.isoCertifications)
    : Boolean(isoTo);

  const location = useLocation();
  /* Brand switch follows the pathname: /connext or /commonground sections
     show their styled wordmark and slot themselves into the triad row.
     Outside sub-brand sections the wordmark defaults to "Conduction"
     for the company anchor. Sites can override the default via
     `themeConfig.footerBrand = { wordmark: '...' }`, or render a dual
     brand row via `{ brands: [{wordmark, logo, href}, ...] }` for
     product pages co-branded with a partner. The legacy fallback to
     `navbar.title` was misleading on product-page footers (launchpad
     showing "LaunchPad" rather than "Conduction"); the company-anchor
     reading wins. */
  const brand = brandFor(location.pathname, navbar?.title);
  const defaultWordmark = footerBrand?.wordmark || 'Conduction';
  const wordmark = brand ? brand.wordmark : defaultWordmark;
  const brandRow = !brand && Array.isArray(footerBrand?.brands) ? footerBrand.brands : null;

  /* canal-footer.{js,css} are loaded post-hydration. The script's DOM
     mutations (filling .skyline, animating boats) don't trip React
     hydration mismatches, and the stylesheet doesn't block first paint.
     See docs in utils/lazyScript.js and utils/lazyStylesheet.js. We
     always load canal-footer.{js,css} even when minigames are off,
     because the same script populates the static skyline and the same
     stylesheet styles the canal frame. */
  useLazyScript('/lib/canal-footer.js', 'canal-footer');
  useLazyStylesheet('/lib/canal-footer.css', 'canal-footer');

  /* kade-cyclist.{js,css} are the second hidden footer minigame. Unlike
     the canal script they have no static side-effects, so when a product
     page opts out of minigames we feed both hooks a falsy value to skip
     the load. The hooks still run unconditionally — rules-of-hooks stays
     compliant. */
  useLazyScript(minigamesOn ? '/lib/kade-cyclist.js' : null, 'kade-cyclist');
  useLazyStylesheet(minigamesOn ? '/lib/kade-cyclist.css' : null, 'kade-cyclist');

  /* Re-hydrate the canal-footer + kade-cyclist runtimes on every mount,
     including SPA route changes that re-render this Footer component.
     Both runtimes ship as IIFE scripts that run once at script-load and
     bind to the first .canal-footer they find. Without a re-hydrate
     call here, internal navigation leaves the new footer with an empty
     .skyline (no trapgevel houses) and unwired boats / bikes (the
     mini-games refuse to start until a hard reload). Each runtime
     exposes window.<Name>.hydrate() once it has loaded; we poll one
     rAF at a time until both appear, then call them. */
  const isBrowser = useIsBrowser();
  useEffect(() => {
    if (!isBrowser) return;
    let cancelled = false;
    function tryHydrate() {
      if (cancelled) return;
      const canalReady = !!window.CanalFooter?.hydrate;
      /* When minigames are off, kade-cyclist.js was never loaded, so
         polling for it would loop forever. Treat it as ready in that
         case so the loop exits after canal-footer is wired. */
      const kadeReady  = !minigamesOn || !!window.KadeCyclist?.hydrate;
      if (canalReady) window.CanalFooter.hydrate();
      if (kadeReady && minigamesOn) window.KadeCyclist.hydrate();
      if (!canalReady || !kadeReady) requestAnimationFrame(tryHydrate);
    }
    tryHydrate();
    return () => { cancelled = true; };
  }, [isBrowser, location.pathname, minigamesOn]);

  if (!footer) return null;
  const {links = [], copyright} = footer;

  return (
    <>
      <footer className="canal-footer" aria-label="Site footer">
        {/* Skyline placeholder — canal-footer.js fills it with cloned house templates */}
        <div className="skyline" role="presentation" />

        {/* Kade (gray quay) with bikes + cars drifting along it */}
        <div className="kade">
          <div className="kade-items">
            <svg className="ki ki-bike-1" width="22" height="16" viewBox="0 -2 22 18" aria-hidden="true">
              <g stroke="var(--c-cobalt-900)" strokeWidth="1.4" fill="none" strokeLinecap="round">
                <circle cx="4" cy="12" r="3"/>
                <circle cx="18" cy="12" r="3"/>
                <line x1="4" y1="12" x2="11" y2="6"/>
                <line x1="11" y1="6" x2="18" y2="12"/>
                <line x1="11" y1="6" x2="14" y2="12"/>
                <line x1="11" y1="6" x2="11" y2="3"/>
                <line x1="11" y1="3" x2="14" y2="6"/>
              </g>
              <circle cx="11" cy="1.5" r="1.6" fill="var(--c-cobalt-900)"/>
            </svg>
            <svg className="ki ki-car-1" width="38" height="16" viewBox="0 0 38 16" aria-hidden="true">
              <rect x="0" y="6" width="38" height="6" rx="2" fill="#C8482F"/>
              <path d="M 5,6 L 9,2 L 29,2 L 33,6 Z" fill="#C8482F"/>
              <rect x="11" y="3" width="6" height="3" fill="rgba(255,255,255,0.5)"/>
              <rect x="20" y="3" width="6" height="3" fill="rgba(255,255,255,0.5)"/>
              <circle cx="9" cy="13" r="2" fill="var(--c-cobalt-900)"/>
              <circle cx="29" cy="13" r="2" fill="var(--c-cobalt-900)"/>
            </svg>
            <svg className="ki ki-bike-2" width="22" height="16" viewBox="0 -2 22 18" aria-hidden="true">
              <g stroke="var(--c-cobalt-900)" strokeWidth="1.4" fill="none" strokeLinecap="round">
                <circle cx="4" cy="12" r="3"/>
                <circle cx="18" cy="12" r="3"/>
                <line x1="4" y1="12" x2="11" y2="6"/>
                <line x1="11" y1="6" x2="18" y2="12"/>
                <line x1="11" y1="6" x2="14" y2="12"/>
                <line x1="11" y1="6" x2="11" y2="3"/>
                <line x1="11" y1="3" x2="14" y2="6"/>
              </g>
              <circle cx="11" cy="1.5" r="1.6" fill="var(--c-cobalt-900)"/>
            </svg>
            <svg className="ki ki-car-2" width="38" height="16" viewBox="0 0 38 16" aria-hidden="true">
              <rect x="0" y="6" width="38" height="6" rx="2" fill="var(--c-cobalt-900)"/>
              <path d="M 5,6 L 9,2 L 29,2 L 33,6 Z" fill="var(--c-cobalt-900)"/>
              <rect x="11" y="3" width="6" height="3" fill="rgba(255,255,255,0.4)"/>
              <rect x="20" y="3" width="6" height="3" fill="rgba(255,255,255,0.4)"/>
              <circle cx="9" cy="13" r="2" fill="#C8482F"/>
              <circle cx="29" cy="13" r="2" fill="#C8482F"/>
            </svg>
          </div>
        </div>

        <div className="water">
          {/* Canal items: orange boats, swimmer, periscope, whale, hovercraft */}
          <div className="canal-items">
            <svg className="ci ci-cruise" width="120" height="28" viewBox="0 -10 120 28" aria-hidden="true">
              <line x1="2" y1="14" x2="2" y2="-9" stroke="var(--c-orange-knvb)" strokeWidth="0.9"/>
              <rect x="2.5" y="-9" width="9" height="2" fill="#AE1C28"/>
              <rect x="2.5" y="-7" width="9" height="2" fill="#FFFFFF"/>
              <rect x="2.5" y="-5" width="9" height="2" fill="#21468B"/>
              <path d="M 0,16 L 6,18 L 114,18 L 120,16 L 120,11 L 0,11 Z" fill="var(--c-orange-knvb)"/>
              <rect x="8" y="5" width="104" height="6" fill="var(--c-orange-knvb)"/>
              <path d="M 8,5 Q 60,0 112,5 L 8,5 Z" fill="var(--c-orange-knvb)"/>
              <g fill="rgba(255,247,210,0.85)">
                <rect x="14" y="7" width="6" height="3"/><rect x="22" y="7" width="6" height="3"/>
                <rect x="30" y="7" width="6" height="3"/><rect x="38" y="7" width="6" height="3"/>
                <rect x="46" y="7" width="6" height="3"/><rect x="54" y="7" width="6" height="3"/>
                <rect x="62" y="7" width="6" height="3"/><rect x="70" y="7" width="6" height="3"/>
                <rect x="78" y="7" width="6" height="3"/><rect x="86" y="7" width="6" height="3"/>
                <rect x="94" y="7" width="6" height="3"/><rect x="102" y="7" width="6" height="3"/>
              </g>
            </svg>
            <svg className="ci ci-sloep" width="50" height="20" viewBox="0 -2 50 20" aria-hidden="true">
              <path d="M 0,12 L 4,16 L 46,16 L 50,12 L 50,8 L 0,8 Z" fill="var(--c-orange-knvb)"/>
              <rect x="20" y="2" width="12" height="6" rx="1" fill="var(--c-orange-knvb)"/>
              <circle cx="26" cy="0" r="1.4" fill="var(--c-orange-knvb)"/>
            </svg>
            <svg className="ci ci-row" width="44" height="16" viewBox="-2 0 48 16" aria-hidden="true">
              <path d="M 0,10 L 4,14 L 40,14 L 44,10 Z" fill="var(--c-orange-knvb)"/>
              <line x1="8" y1="10" x2="0" y2="3" stroke="var(--c-orange-knvb)" strokeWidth="1.2" strokeLinecap="round"/>
              <line x1="36" y1="10" x2="44" y2="3" stroke="var(--c-orange-knvb)" strokeWidth="1.2" strokeLinecap="round"/>
              <circle cx="22" cy="7" r="1.6" fill="var(--c-orange-knvb)"/>
            </svg>
            <svg className="ci ci-swim" width="26" height="10" viewBox="-3 0 30 10" aria-hidden="true">
              <ellipse cx="13" cy="6" rx="7" ry="1.4" fill="var(--c-orange-knvb)"/>
              <circle cx="17" cy="4" r="1.7" fill="var(--c-orange-knvb)"/>
              <line x1="13" y1="5" x2="11" y2="3" stroke="var(--c-orange-knvb)" strokeWidth="1" strokeLinecap="round"/>
              <line x1="-3" y1="6" x2="2" y2="6" stroke="var(--c-orange-knvb)" strokeWidth="0.7" opacity="0.55"/>
              <line x1="0" y1="8" x2="3" y2="8" stroke="var(--c-orange-knvb)" strokeWidth="0.7" opacity="0.4"/>
            </svg>
            <svg className="ci ci-periscope" width="14" height="26" viewBox="-3 -2 18 28" aria-hidden="true">
              <rect x="4" y="4" width="2" height="18" fill="var(--c-orange-knvb)"/>
              <rect x="0" y="0" width="9" height="3" fill="var(--c-orange-knvb)"/>
              <rect x="4" y="3" width="2" height="2" fill="var(--c-orange-knvb)"/>
              <ellipse cx="5" cy="24" rx="7" ry="0.9" fill="var(--c-orange-knvb)" opacity="0.5"/>
            </svg>
            <svg className="ci ci-whale" width="76" height="18" viewBox="-3 -4 82 22" aria-hidden="true">
              <path d="M 0,12 Q 0,4 26,3 Q 54,2 64,7 L 72,4 L 68,9 L 72,14 L 64,11 Q 54,14 26,14 Q 6,14 0,12 Z" fill="var(--c-orange-knvb)"/>
              <g opacity="0.55" stroke="var(--c-orange-knvb)" strokeWidth="1" strokeLinecap="round">
                <line x1="22" y1="2" x2="20" y2="-3"/>
                <line x1="25" y1="2" x2="25" y2="-4"/>
                <line x1="28" y1="2" x2="30" y2="-3"/>
              </g>
            </svg>
            <svg className="ci ci-hover" width="74" height="26" viewBox="0 -4 74 28" aria-hidden="true">
              <ellipse cx="37" cy="22" rx="37" ry="2.5" fill="var(--c-orange-knvb)" opacity="0.4"/>
              <path d="M 6,18 L 10,10 L 64,10 L 68,18 Z" fill="var(--c-orange-knvb)"/>
              <path d="M 14,10 Q 37,-4 60,10 L 14,10 Z" fill="var(--c-orange-knvb)"/>
              <ellipse cx="37" cy="4" rx="16" ry="3.2" fill="rgba(255,247,210,0.7)"/>
              <polygon points="37,11 42,13.5 42,16.5 37,19 32,16.5 32,13.5" fill="#FFFFFF"/>
              <text x="37" y="17.4" fontSize="4" textAnchor="middle" fill="var(--c-orange-knvb)" fontFamily="sans-serif" fontWeight="900">C</text>
              <g stroke="var(--c-orange-knvb)" strokeWidth="0.7" opacity="0.6">
                <line x1="8" y1="22" x2="12" y2="22"/>
                <line x1="16" y1="22" x2="20" y2="22"/>
                <line x1="26" y1="22" x2="30" y2="22"/>
                <line x1="36" y1="22" x2="40" y2="22"/>
                <line x1="46" y1="22" x2="50" y2="22"/>
                <line x1="56" y1="22" x2="60" y2="22"/>
              </g>
            </svg>
          </div>

          {/* Mini-game HUD + game-over dialog + restart button. canal-footer.js
              wires these via querySelector; the script's null-guards (added in
              the same change) let it skip game initialisation cleanly when
              they're absent on a product page. */}
          {minigamesOn && (
            <>
              <div className="game-hud" aria-live="polite" aria-label={translate({id: 'preset.footer.game.ariaLabel', message: 'Boat-sinking mini game', description: 'Accessible name for the footer minigame region'})}>
                <div className="hud-block hud-counter">
                  <span className="hud-num" data-counter="">100</span>
                  <span className="hud-label">
                    <Translate id="preset.footer.game.boatsLeft" description="HUD label counting boats remaining">Boats left</Translate>
                  </span>
                </div>
                <div className="hud-block hud-timer">
                  <span className="hud-num" data-timer="">60</span>
                  <span className="hud-label">
                    <Translate id="preset.footer.game.seconds" description="HUD label for the seconds-remaining timer">Seconds</Translate>
                  </span>
                </div>
              </div>

              <div className="game-over" role="dialog" aria-label={translate({id: 'preset.footer.game.overAriaLabel', message: 'Mini game over', description: 'Accessible name for the game-over dialog'})}>
                <p className="go-title" data-go-title="">
                  <Translate id="preset.footer.game.timesUp" description="Default headline shown when the minigame timer hits zero">Time's up</Translate>
                </p>
                <p className="go-stat">
                  <span data-go-sunk="">0</span>{' '}
                  <Translate id="preset.footer.game.sunk" description="Suffix after the number of boats sunk in the game-over stat">sunk</Translate>
                </p>
                <button type="button" data-restart="">
                  <Translate id="preset.footer.game.playAgain" description="Button label to restart the minigame">Play again</Translate>
                </button>
              </div>
            </>
          )}

          <svg className="canal-waves" viewBox="0 0 1400 500" preserveAspectRatio="none" aria-hidden="true">
            <path className="w1" d="M 0,96 L 140,82 L 280,96 L 420,82 L 560,96 L 700,82 L 840,96 L 980,82 L 1120,96 L 1260,82 L 1400,96"/>
            <path className="w2" d="M 0,206 L 180,182 L 360,206 L 540,182 L 720,206 L 900,182 L 1080,206 L 1260,182 L 1400,200"/>
            <path className="w3" d="M 0,362 L 220,332 L 440,362 L 660,332 L 880,362 L 1100,332 L 1320,362 L 1400,348"/>
          </svg>

          <div className="footer-grid">
            <div className="brand">
              {brandRow ? (
                /* Dual-brand row: product pages co-built with a partner
                   render both wordmarks/logos side by side. The triad
                   below grows a partner segment in the same order. */
                <div
                  className="wm-row"
                  style={{display: 'flex', alignItems: 'center', gap: '1.25rem', flexWrap: 'wrap'}}
                >
                  {brandRow.map((b, i) => {
                    const inner = b.logo ? (
                      <img
                        src={b.logo}
                        alt={b.wordmark}
                        style={{height: 32, width: 'auto', display: 'block'}}
                      />
                    ) : (
                      <span className="wm">{b.wordmark}</span>
                    );
                    return b.href ? (
                      <a
                        key={i}
                        href={b.href}
                        target={b.href.startsWith('http') ? '_blank' : undefined}
                        rel={b.href.startsWith('http') ? 'noopener noreferrer' : undefined}
                        aria-label={b.wordmark}
                        style={{textDecoration: 'none'}}
                      >
                        {inner}
                      </a>
                    ) : (
                      <React.Fragment key={i}>{inner}</React.Fragment>
                    );
                  })}
                </div>
              ) : (
                <div className="wm">{wordmark}</div>
              )}
              <p>
                <Translate
                  id="preset.footer.brandBlurb"
                  description="Footer brand-citation paragraph. {nextcloud} is the styled Nextcloud word."
                  values={{nextcloud: <span className="next-blue">Nextcloud</span>}}>
                  {'Open-source apps for {nextcloud}. Built and maintained by Conduction in Amsterdam, released under EUPL-1.2.'}
                </Translate>
              </p>
              {/*
                Brand citation. The producer chain stays dot-separated
                (Conduction · sub-brand · partner) and connects to
                Nextcloud through a vermillion-red heart — the "loves"
                relationship is between the producer stack and the
                platform it ships on. Nextcloud is a link to
                nextcloud.com so visitors can verify the platform
                upstream in one click.
              */}
              <div className="triad">
                <span>
                  <span className="h"></span>
                  Conduction
                  {brand && <> · {brand.label}</>}
                  {brandRow && brandRow
                    .filter((b) => b.wordmark && b.wordmark !== 'Conduction')
                    .map((b, i) => (
                      <React.Fragment key={i}> · {b.wordmark}</React.Fragment>
                    ))}
                  {' '}
                  <svg className="heart" viewBox="0 0 24 24" fill="currentColor" aria-label="loves" role="img">
                    <path d="M12 21s-6.7-4.35-9.2-8.4C.8 9.2 2 5.5 5.2 4.7c2-.5 3.8.4 4.8 1.9 1-1.5 2.8-2.4 4.8-1.9 3.2.8 4.4 4.5 2.4 7.9C18.7 16.65 12 21 12 21z"/>
                  </svg>
                  {' '}
                  <a
                    href="https://nextcloud.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="next-blue"
                  >Nextcloud</a>
                </span>
              </div>
              <div className="socials">
                <a href="https://codeberg.org/Conduction" aria-label="GitHub" target="_blank" rel="noopener noreferrer">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path className="filled" d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.68-.22.68-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.11-1.47-1.11-1.47-.91-.62.07-.61.07-.61 1 .07 1.53 1.04 1.53 1.04.9 1.53 2.36 1.09 2.93.83.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.09.39-1.98 1.03-2.68-.1-.25-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.02a9.6 9.6 0 0 1 5 0c1.91-1.29 2.75-1.02 2.75-1.02.55 1.38.2 2.4.1 2.65.64.7 1.03 1.59 1.03 2.68 0 3.84-2.34 4.69-4.57 4.93.36.31.68.92.68 1.86v2.76c0 .27.18.58.69.48A10 10 0 0 0 12 2z"/>
                  </svg>
                </a>
                <a href="https://www.linkedin.com/company/conduction" aria-label="LinkedIn" target="_blank" rel="noopener noreferrer">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/>
                    <rect x="2" y="9" width="4" height="12"/>
                    <circle className="filled" cx="4" cy="4" r="2"/>
                  </svg>
                </a>
                <a href="mailto:info@conduction.nl" aria-label="Email info@conduction.nl">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="M3 7l9 6 9-6"/>
                  </svg>
                </a>
                <a href="tel:+31853036840" aria-label="Phone +31 (0)85 303 6840">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/>
                  </svg>
                </a>
                <a href="https://maps.google.com/?q=Lauriergracht+14h+Amsterdam" aria-label="Lauriergracht 14h, Amsterdam, open in Google Maps" target="_blank" rel="noopener noreferrer">
                  <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                  </svg>
                </a>
              </div>
            </div>

            {links.map((column, i) => (
              <div key={i}>
                {column.title && <h4>{column.title}</h4>}
                <ul>
                  {(column.items || []).map((item, j) => (
                    <li key={j}><FooterLink {...item} /></li>
                  ))}
                </ul>
              </div>
            ))}
          </div>

          {copyright && (
            <div className="legal-bar">
              <div className="left">
                <span>{copyright}</span>
                {(privacyTo || termsTo || isoTo) && (
                  <span className="legal-links">
                    {privacyTo && <Link to={privacyTo}>Privacy</Link>}
                    {privacyTo && termsTo && <span className="sep">·</span>}
                    {termsTo && <Link to={termsTo}>Terms</Link>}
                    {(privacyTo || termsTo) && isoTo && <span className="sep">·</span>}
                    {isoTo && <Link to={isoTo}>ISO</Link>}
                  </span>
                )}
              </div>
              {showIsoCerts && (
                <div className="right">
                  <Link to={isoTo || '/iso'} className="iso-badge" aria-label="ISO 9001:2015 certified, see details">
                    <span className="iso-mark">ISO</span>
                    <span className="iso-num">9001:2015</span>
                  </Link>
                  <Link to={isoTo || '/iso'} className="iso-badge" aria-label="ISO 27001:2022 certified, see details">
                    <span className="iso-mark">ISO</span>
                    <span className="iso-num">27001:2022</span>
                  </Link>
                </div>
              )}
            </div>
          )}
        </div>
      </footer>

      {/* Hidden templates cloned by canal-footer.js to populate the
          skyline (5 trapgevel variants) and to spawn boats during the
          mini-game (sailing ship, cargo, frigate, battleship boss).
          When minigames are off the boat templates aren't reachable
          anyway (canal-footer.js's game wiring is null-guarded), but
          dropping them shaves a few KB of inline SVG from the SSR
          payload on product pages. */}
      <FooterTemplates includeBoats={minigamesOn} />

      {/* Cross-game completion dialog. Listens for connext:gameend (HexRain,
          canal mini-game) and reports the result with replay + cross-game
          progress. Position: fixed via CSS module so it overlays the page.
          Only mounted when minigames are on so a product page doesn't
          carry the dialog DOM + listeners for an interaction it can't
          trigger. */}
      {minigamesOn && <GameModal />}
    </>
  );
}

/* The five trapgevel + four boat templates are large blocks of static
   SVG markup; isolating them keeps the main JSX legible. They render
   as <template> elements which canal-footer.js queries by id and clones
   via .content. Because JSX puts children directly under the template
   instead of in its .content DocumentFragment, we inject the templates
   as raw HTML so the browser's HTML parser slots them correctly. */
function FooterTemplates({includeBoats = true}) {
  const html = HOUSE_TEMPLATES_HTML + (includeBoats ? BOAT_TEMPLATES_HTML : '');
  return (
    <div
      style={{display: 'none'}}
      aria-hidden="true"
      dangerouslySetInnerHTML={{__html: html}}
    />
  );
}

/* Skyline templates (5 trapgevel variants). canal-footer.js queries
   #tpl-h-a … #tpl-h-e to clone them across the skyline width. Always
   shipped — the static skyline is part of the brand decoration even
   on product pages that opt out of minigames. */
const HOUSE_TEMPLATES_HTML = `
      <template id="tpl-h-a">
        <svg class="house h-a" viewBox="0 -2 80 202" xmlns="http://www.w3.org/2000/svg">
          <path d="M 0,200 L 0,38 L 8,38 L 8,26 L 16,26 L 16,14 L 24,14 L 24,8 L 56,8 L 56,14 L 64,14 L 64,26 L 72,26 L 72,38 L 80,38 L 80,200 Z" fill="var(--c-orange-knvb)"/>
          <rect x="22" y="68" width="10" height="16" fill="rgba(255,247,210,0.95)"/>
          <rect x="48" y="68" width="10" height="16" fill="rgba(11,32,73,0.35)"/>
          <rect x="22" y="98" width="10" height="16" fill="rgba(11,32,73,0.35)"/>
          <rect x="48" y="98" width="10" height="16" fill="rgba(255,247,210,0.6)"/>
          <rect x="22" y="128" width="10" height="16" fill="rgba(11,32,73,0.35)"/>
          <rect x="48" y="128" width="10" height="16" fill="rgba(11,32,73,0.35)"/>
          <rect x="32" y="160" width="16" height="34" fill="rgba(11,32,73,0.55)"/>
        </svg>
      </template>
      <template id="tpl-h-b">
        <svg class="house h-b" viewBox="0 0 100 220" xmlns="http://www.w3.org/2000/svg">
          <path d="M 0,220 L 0,58 L 8,58 L 8,47 L 16,47 L 16,36 L 24,36 L 24,25 L 32,25 L 32,14 L 50,4 L 68,14 L 68,25 L 76,25 L 76,36 L 84,36 L 84,47 L 92,47 L 92,58 L 100,58 L 100,220 Z" fill="var(--c-orange-knvb)"/>
          <rect x="14" y="80" width="14" height="20" fill="rgba(255,247,210,0.85)"/>
          <rect x="43" y="80" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="72" y="80" width="14" height="20" fill="rgba(255,247,210,0.55)"/>
          <rect x="14" y="115" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="43" y="115" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="72" y="115" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="14" y="150" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="43" y="150" width="14" height="20" fill="rgba(255,247,210,0.7)"/>
          <rect x="72" y="150" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="42" y="180" width="16" height="34" fill="rgba(11,32,73,0.55)"/>
        </svg>
      </template>
      <template id="tpl-h-c">
        <svg class="house h-c" viewBox="0 0 70 240" xmlns="http://www.w3.org/2000/svg">
          <path d="M 0,240 L 0,48 L 5,48 L 5,41 L 10,41 L 10,34 L 15,34 L 15,27 L 20,27 L 20,20 L 25,20 L 25,15 L 45,15 L 45,20 L 50,20 L 50,27 L 55,27 L 55,34 L 60,34 L 60,41 L 65,41 L 65,48 L 70,48 L 70,240 Z" fill="var(--c-orange-knvb)"/>
          <rect x="14" y="70" width="12" height="18" fill="rgba(255,247,210,0.55)"/>
          <rect x="44" y="70" width="12" height="18" fill="rgba(255,247,210,0.85)"/>
          <rect x="14" y="100" width="12" height="18" fill="rgba(11,32,73,0.35)"/>
          <rect x="44" y="100" width="12" height="18" fill="rgba(11,32,73,0.35)"/>
          <rect x="14" y="130" width="12" height="18" fill="rgba(255,247,210,0.7)"/>
          <rect x="44" y="130" width="12" height="18" fill="rgba(11,32,73,0.35)"/>
          <rect x="14" y="160" width="12" height="18" fill="rgba(11,32,73,0.35)"/>
          <rect x="44" y="160" width="12" height="18" fill="rgba(11,32,73,0.35)"/>
          <rect x="27" y="200" width="16" height="34" fill="rgba(11,32,73,0.55)"/>
        </svg>
      </template>
      <template id="tpl-h-d">
        <svg class="house h-d" viewBox="0 -10 90 180" xmlns="http://www.w3.org/2000/svg">
          <path d="M 0,170 L 0,30 L 8,30 L 8,20 L 16,20 L 16,10 L 24,10 L 24,4 L 66,4 L 66,10 L 74,10 L 74,20 L 82,20 L 82,30 L 90,30 L 90,170 Z" fill="var(--c-orange-knvb)"/>
          <rect x="12" y="60" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="38" y="60" width="14" height="20" fill="rgba(255,247,210,0.85)"/>
          <rect x="64" y="60" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="12" y="100" width="14" height="20" fill="rgba(255,247,210,0.6)"/>
          <rect x="38" y="100" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="64" y="100" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="37" y="130" width="16" height="34" fill="rgba(11,32,73,0.55)"/>
        </svg>
      </template>
      <template id="tpl-h-e">
        <svg class="house h-e" viewBox="0 0 95 210" xmlns="http://www.w3.org/2000/svg">
          <path d="M 0,210 L 0,55 L 8,55 L 8,44 L 16,44 L 16,33 L 24,33 L 24,22 L 32,22 L 32,11 L 35,11 L 35,6 L 59,6 L 59,11 L 63,11 L 63,22 L 71,22 L 71,33 L 79,33 L 79,44 L 87,44 L 87,55 L 95,55 L 95,210 Z" fill="var(--c-orange-knvb)"/>
          <rect x="13" y="75" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="40" y="75" width="14" height="20" fill="rgba(255,247,210,0.65)"/>
          <rect x="68" y="75" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="13" y="110" width="14" height="20" fill="rgba(255,247,210,0.8)"/>
          <rect x="40" y="110" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="68" y="110" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="13" y="145" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="40" y="145" width="14" height="20" fill="rgba(11,32,73,0.35)"/>
          <rect x="68" y="145" width="14" height="20" fill="rgba(255,247,210,0.55)"/>
          <rect x="39.5" y="175" width="16" height="34" fill="rgba(11,32,73,0.55)"/>
        </svg>
      </template>
`;

/* Boat templates (sailing ship, cargo, frigate, battleship boss).
   canal-footer.js spawns these as the boat-sinking mini-game escalates;
   when a site opts out of minigames the script's null-guard skips
   spawning and these templates are unreachable, so they're elided
   from the SSR payload. */
const BOAT_TEMPLATES_HTML = `
      <template id="tpl-ship-sailing">
        <svg class="ci ci-sailing" width="100" height="60" viewBox="-4 -42 108 60" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M 0,12 L 6,16 L 90,16 L 96,12 L 96,8 L 0,8 Z" fill="var(--c-orange-knvb)"/>
          <line x1="0" y1="11" x2="-4" y2="6" stroke="var(--c-orange-knvb)" stroke-width="1.6" stroke-linecap="round"/>
          <line x1="48" y1="8" x2="48" y2="-40" stroke="#3a4553" stroke-width="1.4"/>
          <rect x="44.5" y="-30" width="7" height="2.5" fill="#3a4553"/>
          <line x1="22" y1="-12" x2="74" y2="-12" stroke="#3a4553" stroke-width="0.9"/>
          <path d="M 24,-12 Q 48,-7 72,-12 L 72,4 Q 48,8 24,4 Z" fill="rgba(255,247,210,0.92)"/>
          <line x1="24" y1="-26" x2="72" y2="-26" stroke="#3a4553" stroke-width="0.9"/>
          <path d="M 26,-26 Q 48,-22 70,-26 L 70,-13 Q 48,-9 26,-13 Z" fill="rgba(255,247,210,0.92)"/>
          <line x1="34" y1="-38" x2="62" y2="-38" stroke="#3a4553" stroke-width="0.9"/>
          <path d="M 35,-38 Q 48,-35 61,-38 L 61,-28 Q 48,-25 35,-28 Z" fill="rgba(255,247,210,0.92)"/>
          <path d="M 48,-40 L 58,-39 L 56,-37 L 58,-35 L 48,-36 Z" fill="#AE1C28"/>
        </svg>
      </template>
      <template id="tpl-ship-cargo">
        <svg class="ci ci-cargo" width="180" height="44" viewBox="0 -28 180 44" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M 0,14 L 6,16 L 174,16 L 180,14 L 180,10 L 0,10 Z" fill="var(--c-orange-knvb)"/>
          <g>
            <rect x="14" y="0" width="14" height="9" fill="#C8482F"/>
            <rect x="30" y="0" width="14" height="9" fill="var(--c-cobalt-900)"/>
            <rect x="46" y="0" width="14" height="9" fill="rgba(255,247,210,0.9)"/>
            <rect x="62" y="0" width="14" height="9" fill="#C8482F"/>
            <rect x="78" y="0" width="14" height="9" fill="var(--c-cobalt-900)"/>
            <rect x="94" y="0" width="14" height="9" fill="rgba(255,247,210,0.9)"/>
            <rect x="110" y="0" width="14" height="9" fill="#C8482F"/>
            <rect x="14" y="-9" width="14" height="9" fill="var(--c-cobalt-900)"/>
            <rect x="30" y="-9" width="14" height="9" fill="rgba(255,247,210,0.9)"/>
            <rect x="62" y="-9" width="14" height="9" fill="var(--c-cobalt-900)"/>
            <rect x="94" y="-9" width="14" height="9" fill="#C8482F"/>
            <rect x="14" y="-18" width="14" height="9" fill="rgba(255,247,210,0.9)"/>
            <rect x="62" y="-18" width="14" height="9" fill="#C8482F"/>
          </g>
          <rect x="138" y="-18" width="22" height="27" fill="#5a6573"/>
          <rect x="142" y="-14" width="14" height="3" fill="rgba(255,247,210,0.85)"/>
          <rect x="142" y="-9" width="14" height="3" fill="rgba(255,247,210,0.6)"/>
          <rect x="142" y="-4" width="14" height="3" fill="rgba(255,247,210,0.6)"/>
          <rect x="146" y="-26" width="8" height="8" fill="#3a4553"/>
          <rect x="146" y="-22" width="8" height="2" fill="var(--c-orange-knvb)"/>
        </svg>
      </template>
      <template id="tpl-ship-frigate">
        <svg class="ci ci-frigate" width="130" height="36" viewBox="0 -20 130 36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M 0,14 L 6,16 L 124,16 L 130,14 L 130,10 L 0,10 Z" fill="#5a6573"/>
          <rect x="0" y="9" width="130" height="1.4" fill="var(--c-orange-knvb)"/>
          <rect x="14" y="6" width="14" height="4" rx="1.5" fill="#6a7583"/>
          <line x1="21" y1="8" x2="40" y2="6" stroke="#3a4553" stroke-width="1.6" stroke-linecap="round"/>
          <rect x="42" y="-2" width="48" height="12" fill="#6a7583"/>
          <rect x="58" y="-12" width="16" height="10" fill="#7a8593"/>
          <rect x="60" y="-9" width="12" height="2.5" fill="rgba(255,247,210,0.85)"/>
          <line x1="66" y1="-12" x2="66" y2="-18" stroke="#3a4553" stroke-width="1"/>
          <rect x="82" y="-7" width="6" height="5" fill="#3a4553"/>
          <rect x="82" y="-5" width="6" height="1.5" fill="var(--c-orange-knvb)"/>
          <rect x="100" y="6" width="14" height="4" rx="1.5" fill="#6a7583"/>
        </svg>
      </template>
      <template id="tpl-battleship">
        <svg class="ci ci-battleship boss" width="220" height="56" viewBox="0 -22 220 56" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M 0,18 L 8,28 L 212,28 L 220,18 L 220,12 L 0,12 Z" fill="#4a5663"/>
          <rect x="0" y="11" width="220" height="2" fill="var(--c-orange-knvb)"/>
          <rect x="50" y="0" width="120" height="12" fill="#5a6573"/>
          <rect x="92" y="-12" width="36" height="12" fill="#6a7583"/>
          <rect x="96" y="-9" width="28" height="3" fill="rgba(255,247,210,0.85)"/>
          <rect x="138" y="-7" width="10" height="7" fill="#3a4553"/>
          <rect x="138" y="-4" width="10" height="2" fill="var(--c-orange-knvb)"/>
          <line x1="110" y1="-12" x2="110" y2="-20" stroke="#3a4553" stroke-width="1.4"/>
          <rect x="22" y="6" width="22" height="6" rx="2" fill="#6a7583"/>
          <line x1="32" y1="9" x2="56" y2="6" stroke="#3a4553" stroke-width="2.2" stroke-linecap="round"/>
          <line x1="32" y1="9" x2="8" y2="6" stroke="#3a4553" stroke-width="2.2" stroke-linecap="round"/>
          <rect x="176" y="6" width="22" height="6" rx="2" fill="#6a7583"/>
          <line x1="186" y1="9" x2="210" y2="6" stroke="#3a4553" stroke-width="2.2" stroke-linecap="round"/>
          <line x1="186" y1="9" x2="162" y2="6" stroke="#3a4553" stroke-width="2.2" stroke-linecap="round"/>
          <g class="hp-pips" transform="translate(58, -18)">
            <circle class="pip" cx="0" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="11" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="22" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="33" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="44" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="55" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="66" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="77" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="88" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
            <circle class="pip" cx="99" cy="0" r="2.4" fill="var(--c-orange-knvb)"/>
          </g>
        </svg>
      </template>
`;
