/**
 * <Pill />
 *
 * Inline-flex pill chip with optional leading <HexBullet/>. Used for
 * status badges (STABLE / BETA), built-on app tags, sector labels,
 * version tags. Recurs in reference-cards.css, solution-cards.css,
 * partner-cards.css, apps-grid.css, app-card meta line.
 *
 * Tones:
 *   - default:  cobalt-50 bg, cobalt-700 text, mono caps
 *   - status:   colored hex bullet (green=stable, orange=beta, blue=soon)
 *   - solid:    colored bg fill (used for sector tags)
 *
 * Usage:
 *
 *   <Pill bullet>STABLE</Pill>
 *   <Pill bullet bulletColor="var(--c-orange-knvb)">BETA</Pill>
 *   <Pill tone="solid" color="var(--c-mkb)">MKB</Pill>
 */

import React from 'react';
import HexBullet from './HexBullet';
import styles from './Pill.module.css';

export default function Pill({
  bullet = false,
  bulletColor,
  tone = 'default',
  color,
  className,
  children,
  ...rest
}) {
  const composed = [styles.pill, styles['tone-' + tone], className].filter(Boolean).join(' ');
  const style = tone === 'solid' && color ? {background: color, color: 'white'} : undefined;
  return (
    <span className={composed} style={style} {...rest}>
      {bullet && <HexBullet size="sm" color={bulletColor} />}
      <span className={styles.label}>{children}</span>
    </span>
  );
}
