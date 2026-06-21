/**
 * Conduction-flavoured error page.
 *
 * A row of orange Amsterdam canal houses, bottoms half-sunken in a
 * cobalt-900 canal, with a tall orange trapezoidal dyke on the right
 * holding back the rising sea. The dyke has just sprung a leak — water
 * gushes out of the breach into the canal — and the village pier is
 * the "duct tape inbound" message dispatching the fix.
 *
 * Houses + canal waves are reused from the canal-footer pattern
 * (design-system/docusaurus-preset/src/theme/Footer/index.jsx), so the
 * error page lives in the same visual language as the rest of the
 * site's footer. Pure-CSS animation, prefers-reduced-motion safe.
 */

import React from 'react';
import {
  ErrorBoundaryError,
  ErrorBoundaryTryAgainButton,
} from '@docusaurus/theme-common';
import styles from './styles.module.css';

function DykeBreak() {
  return (
    <div className={styles.scene} aria-hidden="true">
      <svg
        viewBox="0 0 800 360"
        preserveAspectRatio="xMidYMid slice"
        className={styles.svg}
      >
        {/* sky */}
        <rect x="0" y="0" width="800" height="200" fill="var(--c-cobalt-50)" />

        {/* row of Dutch canal trapgevel houses, orange, simplified
            silhouettes lifted from the canal-footer templates. House
            bottoms sit at y=240 so they extend 40px below the canal
            waterline (y=200) once the water rect covers them. */}
        <g className={styles.houses} fill="var(--c-orange-knvb)">
          {/* stepped-gable, narrow */}
          <path d="M20,240 L20,80 L24,80 L24,72 L32,72 L32,64 L40,64 L40,60 L56,60 L56,64 L64,64 L64,72 L72,72 L72,80 L76,80 L76,240 Z" />
          {/* bell-gable, medium */}
          <path d="M86,240 L86,98 Q86,72 108,72 Q130,72 130,98 L130,240 Z" />
          {/* stepped, tall */}
          <path d="M140,240 L140,60 L146,60 L146,50 L154,50 L154,40 L162,40 L162,28 L178,28 L178,40 L186,40 L186,50 L194,50 L194,60 L200,60 L200,240 Z" />
          {/* steep-gable house */}
          <path d="M210,240 L210,100 L234,72 L258,100 L258,240 Z" />
          {/* stepped, mid-height */}
          <path d="M268,240 L268,96 L274,96 L274,86 L282,86 L282,76 L290,76 L290,68 L308,68 L308,76 L316,76 L316,86 L324,86 L324,96 L330,96 L330,240 Z" />
          {/* church-style tower */}
          <path d="M340,240 L340,80 L356,80 L356,68 L360,68 L360,46 L364,32 L368,46 L368,68 L372,68 L372,80 L388,80 L388,240 Z" />
        </g>

        {/* lit / dark windows so the silhouettes read as canal houses.
            Light squares are warm cream, dark squares are see-through
            inky panes. */}
        <g className={styles.windows}>
          <rect x="32"  y="106" width="8" height="12" fill="rgba(255,247,210,0.92)" />
          <rect x="56"  y="106" width="8" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="32"  y="138" width="8" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="56"  y="138" width="8" height="12" fill="rgba(255,247,210,0.6)" />
          <rect x="32"  y="170" width="8" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="56"  y="170" width="8" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="92"  y="118" width="10" height="14" fill="rgba(255,247,210,0.85)" />
          <rect x="114" y="118" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="92"  y="146" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="114" y="146" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="92"  y="174" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="114" y="174" width="10" height="14" fill="rgba(255,247,210,0.6)" />
          <rect x="150" y="80"  width="10" height="14" fill="rgba(255,247,210,0.85)" />
          <rect x="178" y="80"  width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="150" y="108" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="178" y="108" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="150" y="138" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="178" y="138" width="10" height="14" fill="rgba(255,247,210,0.7)" />
          <rect x="150" y="166" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="178" y="166" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="220" y="118" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="240" y="118" width="10" height="14" fill="rgba(255,247,210,0.85)" />
          <rect x="220" y="148" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="240" y="148" width="10" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="278" y="116" width="10" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="296" y="116" width="10" height="12" fill="rgba(255,247,210,0.65)" />
          <rect x="316" y="116" width="10" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="278" y="146" width="10" height="12" fill="rgba(255,247,210,0.7)" />
          <rect x="296" y="146" width="10" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="316" y="146" width="10" height="12" fill="rgba(11,32,73,0.35)" />
          <rect x="354" y="106" width="8" height="14" fill="rgba(255,247,210,0.85)" />
          <rect x="354" y="138" width="8" height="14" fill="rgba(11,32,73,0.35)" />
          <rect x="354" y="172" width="8" height="14" fill="rgba(255,247,210,0.55)" />
        </g>

        {/* THE ORANGE DYKE — proper trapezoidal cross-section on the
            right side of the canvas, holding back the sea beyond the
            right edge. Same orange as the houses (per the brief). */}
        <path
          d="M440,240 L560,40 L660,40 L800,240 L800,360 L440,360 Z"
          fill="var(--c-orange-knvb)"
        />
        {/* darker inner band so the trapezoid reads as packed earth,
            not a flat orange block */}
        <path
          d="M452,240 L566,54 L654,54 L788,240 Z"
          fill="var(--c-cobalt-900)"
          opacity="0.12"
        />

        {/* breach — punched through the LAND-facing (left) slope,
            high enough above the canal that the falling stream is
            visible. Drawn as a dark cobalt-900 hole. */}
        <path
          d="M482,162 Q496,140 516,138 Q536,140 542,160 Q540,178 524,184 L500,185 Q478,180 482,162 Z"
          fill="var(--c-cobalt-900)"
        />

        {/* the cascade — water falling from the breach into the canal
            below. A loose teardrop shape that animates with a subtle
            shimmer. */}
        <g className={styles.gushGroup}>
          <path
            d="M500,184 Q495,200 502,200 Q510,200 522,184 Q514,182 500,184 Z"
            fill="var(--c-cobalt-400)"
          />
          <path
            d="M502,200 Q495,212 487,225 L495,232 Q514,222 519,200 Q510,196 502,200 Z"
            fill="var(--c-cobalt-400)"
            opacity="0.85"
          />
        </g>

        {/* splash droplets where the cascade meets the canal */}
        <g className={styles.droplets} fill="var(--c-cobalt-400)">
          <circle cx="476" cy="222" r="2.5" className={styles.d1} />
          <circle cx="464" cy="216" r="2"   className={styles.d2} />
          <circle cx="510" cy="226" r="2.5" className={styles.d3} />
          <circle cx="528" cy="218" r="2"   className={styles.d4} />
          <circle cx="498" cy="228" r="3"   className={styles.d5} />
        </g>

        {/* CANAL — drawn on top of the lower portions of houses + dyke
            so the bottoms submerge below the waterline. Fills from
            y=200 down to the bottom of the canvas. */}
        <rect x="0" y="200" width="800" height="160" fill="var(--c-cobalt-900)" />

        {/* dock / kade — a narrow gray strip on the canal surface,
            barely above water, just visible enough to anchor the
            village in the scene. */}
        <rect x="0" y="200" width="430" height="4" fill="var(--c-cobalt-400)" opacity="0.6" />

        {/* CANAL WAVES — three sawtooth lines lifted from the canal
            footer (canal-footer.css .canal-waves), rescaled for the
            error scene viewport. They sit on top of the canal water. */}
        <g className={styles.waves} fill="none" stroke="rgba(67,118,252,0.30)" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round">
          <path d="M0,224 L80,212 L160,224 L240,212 L320,224 L400,212 L480,224 L560,212 L640,224 L720,212 L800,224" />
          <path d="M0,278 L100,260 L200,278 L300,260 L400,278 L500,260 L600,278 L700,260 L800,278" />
          <path d="M0,332 L120,310 L240,332 L360,310 L480,332 L600,310 L720,332 L800,322" />
        </g>

        {/* worker hex on the dock in front of the dyke, leaning toward
            the breach. The little orange life-vest stays orange — same
            family as the houses + dyke. */}
        <g transform="translate(410,196)">
          <polygon
            points="0,-12 10,-6 10,6 0,12 -10,6 -10,-6"
            fill="var(--c-cobalt-900)"
          />
          <rect x="-9" y="8" width="18" height="14" rx="3" fill="var(--c-orange-knvb)" />
        </g>

        {/* "duct tape inbound" tag — widened so the text fits inside
            the rounded rect with breathing room. The previous 88px-wide
            pill was clipping the letters at both ends. */}
        <g className={styles.tag} transform="translate(420,150)">
          <rect x="-72" y="-13" width="144" height="26" rx="13" fill="white" stroke="var(--c-cobalt-200)" />
          <text
            x="0"
            y="4"
            textAnchor="middle"
            fontFamily="var(--conduction-typography-font-family-code)"
            fontSize="11"
            fill="var(--c-cobalt-700)"
          >duct tape inbound</text>
        </g>
      </svg>
    </div>
  );
}

export default function ErrorPageContent({error, tryAgain}) {
  return (
    <main className={styles.page}>
      <DykeBreak />

      <div className={styles.copy}>
        <p className={styles.eyebrow}>Finger in the dyke</p>
        <h1 className={styles.title}>Oh, no. Something broke.</h1>
        <p className={styles.lede}>
          We are dispatching a team with duct tape right now. Please try again
          in a moment, or take a different route.
        </p>

        <div className={styles.actions}>
          <ErrorBoundaryTryAgainButton
            onClick={tryAgain}
            className={styles.tryButton}
          />
          <a href="/" className={styles.homeLink}>Or back to Conduction.nl →</a>
        </div>

        <details className={styles.details}>
          <summary>What technically went wrong</summary>
          <div className={styles.detailsBody}>
            <ErrorBoundaryError error={error} />
          </div>
        </details>
      </div>
    </main>
  );
}
