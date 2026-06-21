/**
 * <HexBackground />
 *
 * Brand-recognisable abstract backdrop. A large pointy-top hex (or two
 * stacked) sitting behind the content, sized so a portion of the hex
 * crests the visible bounds — the upper two shoulder corners poke into
 * the layout, the rest fills the panel as a colour wash. The component
 * is just chrome; it expects to be a position:absolute child inside a
 * relatively-positioned parent that owns the actual content above it.
 *
 * Used as the wash behind:
 *   - <Showcase> right panel (cobalt-100 hex behind the AppMock)
 *   - <RotatingCards> coloured card image-side
 *   - any future "abstract product mock on a panel" layout
 *
 * Usage:
 *
 *   <div style={{position: 'relative'}}>
 *     <HexBackground tone="cobalt-100" position="top" />
 *     <div style={{position: 'relative', zIndex: 1}}>... content ...</div>
 *   </div>
 *
 * Props:
 *   - tone:     'cobalt-100' (default) | 'cobalt-50' | 'mint-100' |
 *               'lavender-100' | 'forest-100' | 'terracotta-100' |
 *               'coral-100' | 'cobalt-700' | 'workspace-100'
 *   - position: 'top' (default; hex crests the top edge) |
 *               'centre' | 'bottom-right' | 'left'
 *   - size:     'md' (default) | 'lg' (oversized) | 'sm'
 *   - density:  1 (default) | 2 (offset twin hex behind, +depth)
 */

import React from 'react';
import styles from './HexBackground.module.css';

export default function HexBackground({
  tone = 'cobalt-100',
  position = 'top',
  size = 'md',
  density = 1,
  className,
}) {
  const composed = [
    styles.bg,
    styles[`tone-${tone}`],
    styles[`pos-${position}`],
    styles[`size-${size}`],
    className,
  ].filter(Boolean).join(' ');
  return (
    <span className={composed} aria-hidden="true">
      <span className={styles.hexA}></span>
      {density >= 2 && <span className={styles.hexB}></span>}
    </span>
  );
}
