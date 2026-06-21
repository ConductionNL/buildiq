import React from 'react';
import GLYPHS from '../../data/app-glyphs.json';

/**
 * AppGlyph — the single source of truth for Conduction app logos.
 *
 * Renders the canonical app glyph keyed by slug, extracted verbatim
 * from the identity brand kit (identity.conduction.nl/apps, the
 * `#g-<slug>` symbols in preview/apps.html). Every surface that shows
 * an app mark (the /apps detail heroes, the ConNext platform diagram,
 * the apps catalogue grid) consumes this component so the logo is the
 * same everywhere instead of a hand-drawn placeholder per page.
 *
 * Usage:
 *   import {AppGlyph} from '@conduction/docusaurus-preset/components';
 *   <AppGlyph app="opencatalogi" />
 *
 * Unknown slugs render nothing (returns null) so a consumer never
 * breaks over a missing glyph; add the slug to the brand kit and
 * regenerate src/data/app-glyphs.json to fill it in.
 *
 * The glyph inherits color via `fill: currentColor`, so wrap it in a
 * element with the desired `color` (the DetailHero hex, a catalogue
 * tile) to tint it.
 */
export const APP_GLYPH_SLUGS = Object.keys(GLYPHS);

export function hasAppGlyph(app) {
  return Boolean(app && GLYPHS[app]);
}

export default function AppGlyph({app, className, title, ...rest}) {
  const glyph = app && GLYPHS[app];
  if (!glyph) {
    return null;
  }
  return (
    <svg
      viewBox={glyph.viewBox}
      className={className}
      fill="currentColor"
      role={title ? 'img' : undefined}
      aria-hidden={title ? undefined : 'true'}
      aria-label={title}
      focusable="false"
      xmlns="http://www.w3.org/2000/svg"
      // Inner markup is verbatim brand-kit SVG (paths, circles, rects)
      // — render it raw rather than transcribing each path into JSX.
      dangerouslySetInnerHTML={{__html: glyph.inner}}
      {...rest}
    />
  );
}
