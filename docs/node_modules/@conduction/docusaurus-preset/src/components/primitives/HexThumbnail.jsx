/**
 * <HexThumbnail />
 *
 * Single pointy-top hex tile that holds an icon, a photo (clip-path
 * masked), or an illustration. Used as the visual on academy
 * <ContentCard/>, <FeaturedCard/>, <ContentDetailHero/> and anywhere
 * else a hex-shaped media tile is needed.
 *
 * Per huisstijl: pointy-top, never rotated, solid colour ground.
 *
 * Sizes:
 *   - sm = 56  × 64   (small content cards, dense rows)
 *   - md = 88  × 100  (default content card)
 *   - lg = 160 × 184  (featured card, secondary visual)
 *   - xl = 240 × 280  (featured-card primary, detail-hero cover)
 *
 * Tones: cobalt (default), cobalt-dark, cobalt-deep, mint, orange,
 * cobalt-50 (light surface, cobalt content).
 *
 * Pass an SVG icon as children for the icon variant; pass `src` for
 * the photo variant. The component keeps both options in one API so
 * cards don't need two thumbnail components.
 *
 * Usage:
 *
 *   <HexThumbnail tone="cobalt-dark" size="md">
 *     <svg viewBox="0 0 24 24"><path d="..."/></svg>
 *   </HexThumbnail>
 *
 *   <HexThumbnail size="lg" src="/img/posts/install.jpg" alt="Install screen" />
 */

import React from 'react';
import styles from './HexThumbnail.module.css';

const SIZES = {sm: 'sm', md: 'md', lg: 'lg', xl: 'xl'};
const TONES = {
  cobalt: 'cobalt',
  'cobalt-dark': 'cobalt-dark',
  'cobalt-deep': 'cobalt-deep',
  'cobalt-50': 'cobalt-50',
  mint: 'mint',
  orange: 'orange',
};

export default function HexThumbnail({
  size = 'md',
  tone = 'cobalt',
  src,
  alt = '',
  className,
  children,
  style,
  ...rest
}) {
  const sizeKey = SIZES[size] || 'md';
  const toneKey = TONES[tone] || 'cobalt';
  const composed = [
    styles.thumb,
    styles['size-' + sizeKey],
    styles['tone-' + toneKey],
    className,
  ].filter(Boolean).join(' ');

  return (
    <span className={composed} style={style} {...rest}>
      {src ? <img src={src} alt={alt} /> : children}
    </span>
  );
}
