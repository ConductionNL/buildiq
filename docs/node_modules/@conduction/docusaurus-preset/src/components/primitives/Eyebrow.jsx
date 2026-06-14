/**
 * <Eyebrow />
 *
 * Uppercase Plex Mono caption sitting above section headings.
 *   - 12px, letter-spacing 0.1em, --c-cobalt-400
 *   - Optional leading <HexBullet/> in KNVB orange
 *
 * Mirrors the .eyebrow rule duplicated across hero.html, platform-
 * overview.html, pipeline-flow.html, feature-grid.html, apps-grid.css,
 * employee-cards.css. The 6+ duplications pay for the extraction.
 *
 * Usage in MDX:
 *
 *   <Eyebrow>The platform</Eyebrow>
 *   <Eyebrow bullet={false}>No bullet</Eyebrow>
 *   <Eyebrow as="span">Inline variant</Eyebrow>
 */

import React from 'react';
import HexBullet from './HexBullet';
import styles from './Eyebrow.module.css';

export default function Eyebrow({
  bullet = true,
  bulletColor,
  as: Tag = 'div',
  className,
  children,
  ...rest
}) {
  return (
    <Tag className={[styles.eyebrow, className].filter(Boolean).join(' ')} {...rest}>
      {bullet && <HexBullet size="md" color={bulletColor} />}
      <span className={styles.label}>{children}</span>
    </Tag>
  );
}
