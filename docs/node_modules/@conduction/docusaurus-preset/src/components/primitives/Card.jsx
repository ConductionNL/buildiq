/**
 * <Card />
 *
 * The white container with cobalt-100 border, radius-lg corners, and
 * 20-28px internal padding that recurs in pair-cards.html, solution-
 * cards.html, reference-cards.html, partner-cards.html, employee-
 * cards.html, apps-grid.html.
 *
 * Variants:
 *   - tone="surface"  (default) white bg, cobalt-100 border
 *   - tone="ghost"    transparent bg, cobalt-100 dashed border
 *   - tone="inverse"  cobalt-900 bg, white type
 *
 * Renders as <a> when href is provided (so cards are clickable
 * affordances without nested-link issues).
 */

import React from 'react';
import styles from './Card.module.css';

export default function Card({
  tone = 'surface',
  href,
  to,
  padding = 'md',
  className,
  children,
  ...rest
}) {
  const composed = [
    styles.card,
    styles['tone-' + tone],
    styles['pad-' + padding],
    href || to ? styles.link : null,
    className,
  ].filter(Boolean).join(' ');

  if (href) {
    return <a href={href} className={composed} {...rest}>{children}</a>;
  }
  return <div className={composed} {...rest}>{children}</div>;
}
