/**
 * Brand icon set.
 *
 * Single source of truth for the SVG glyphs used in the brand chrome
 * (Button, Navbar, hero CTAs). Each icon renders at 1em × 1em with
 * `currentColor`, so it inherits the surrounding font size and colour
 * automatically — pass it inside a Button or link and it lines up
 * without extra styling.
 *
 *   <Icon name="github" />
 *   <Button icon="github" variant="on-dark-tertiary" href={...}>View on GitHub</Button>
 *
 * Adding a new icon: define the React node in ICONS below, keep the
 * viewBox at 0 0 24 24, and use stroke or fill — never both with
 * different colours, so currentColor stays the single ink.
 */

import React from 'react';

export const ICONS = {
  /* Official GitHub mark, simplified to a single filled path so it
     reads cleanly at 14–20px. Source: github.com/logos. */
  github: (
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.57.1.78-.25.78-.55v-2.16c-3.2.69-3.87-1.36-3.87-1.36-.52-1.33-1.27-1.69-1.27-1.69-1.04-.71.08-.7.08-.7 1.15.08 1.76 1.18 1.76 1.18 1.02 1.75 2.68 1.25 3.34.95.1-.74.4-1.25.73-1.54-2.55-.29-5.23-1.28-5.23-5.69 0-1.26.45-2.28 1.18-3.09-.12-.29-.51-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 015.8 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.81 1.18 1.83 1.18 3.09 0 4.42-2.69 5.4-5.25 5.68.41.36.78 1.06.78 2.13v3.16c0 .3.21.66.79.55C20.21 21.39 23.5 17.08 23.5 12 23.5 5.65 18.35.5 12 .5z"/>
    </svg>
  ),

  /* API / OpenAPI reference. A stylised open book with a small
     keyhole, matches the Redocusaurus reference mock at
     preview/product-pages/api-reference.html. */
  apiDocs: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M4 4h6a2 2 0 012 2v14a2 2 0 00-2-2H4z"/>
      <path d="M20 4h-6a2 2 0 00-2 2v14a2 2 0 012-2h6z"/>
      <path d="M8 9h2M8 13h2M16 9h-2M16 13h-2"/>
    </svg>
  ),

  /* Generic right arrow used by ghost CTAs. Wrapped in this set so the
     hero CTA can compose `View on GitHub` + `→` with consistent inline
     metrics on any font-size. */
  arrowRight: (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <line x1="5" y1="12" x2="19" y2="12"/>
      <polyline points="13 6 19 12 13 18"/>
    </svg>
  ),
};

/**
 * Render a brand icon by name (string key from ICONS). Sized 1em × 1em
 * via inline style; pass extra CSS via `className`. If the caller wants
 * a custom icon, they can render any React node directly — the helper
 * is just a convenience.
 */
export default function Icon({name, className, style}) {
  const node = ICONS[name];
  if (!node) return null;
  return React.cloneElement(node, {
    className,
    style: {width: '1em', height: '1em', display: 'inline-block', flexShrink: 0, ...style},
  });
}
