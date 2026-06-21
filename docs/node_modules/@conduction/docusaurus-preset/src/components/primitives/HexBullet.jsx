/**
 * <HexBullet />
 *
 * The 12×14 (or 8×9) pointy-top clip-path hexagon used as the bullet
 * inside eyebrows, status badges, footer triad, and pill chips. The
 * shape recurs 15+ times across the kit; centralising it removes the
 * temptation to duplicate `width: 12px; height: 14px; clip-path:
 * var(--hex-pointy-top)` in every component CSS module.
 *
 * Per huisstijl: pointy-top, never rotated, solid colour fill.
 *
 * Sizes:
 *   - sm  =  8 × 9   (inline pill bullet)
 *   - md  = 12 × 14  (default eyebrow bullet)
 *   - lg  = 44 × 50  (icon-hex container, see <IconHex/> wrapper)
 *
 * Color: any CSS color or token. Defaults to KNVB orange so the bullet
 * is the brand's only orange-on-cobalt accent.
 */

import React from 'react';
import styles from './HexBullet.module.css';

const SIZES = {sm: 'sm', md: 'md', lg: 'lg'};

export default function HexBullet({size = 'md', color, className, style, ...rest}) {
  const sizeKey = SIZES[size] || 'md';
  const composed = [styles.hex, styles[sizeKey], className].filter(Boolean).join(' ');
  return (
    <span
      aria-hidden="true"
      className={composed}
      style={color ? {background: color, ...style} : style}
      {...rest}
    />
  );
}
