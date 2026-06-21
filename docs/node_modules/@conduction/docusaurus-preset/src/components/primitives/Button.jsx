/**
 * <Button />
 *
 * Brand variants used across hero, cta-banner, top-navbar, game-modal,
 * and per-page CTA rows.
 *
 * Renders as <a> when href is provided, <button> otherwise. Inherits
 * the brand transition (140ms ease) and KNVB-orange focus ring from
 * brand.css.
 *
 * Variants:
 *   - primary:             cobalt fill, white text
 *   - secondary:           white bg, cobalt-200 border, cobalt text
 *   - ghost:               plain text + arrow, for on-white surfaces
 *   - on-dark-primary:     primary inverted for use on cobalt CTA panels
 *   - on-dark-secondary:   ghost-style with white-translucent border, on cobalt
 *   - on-dark-tertiary:    transparent fill, solid white border + white text,
 *                          for the "View on GitHub" CTA on cobalt-bg heroes
 *
 * Tone (optional): override the primary/secondary fill colour. The
 * brand default is cobalt; product pages with an orange-accent identity
 * (e.g. launchpad) pass `tone="orange"` to flip the primary CTA to
 * KNVB-orange while keeping the rest of the brand chrome intact.
 *
 * Icon (optional): pass a key from icons.jsx (e.g. `"github"`) or a
 * React node directly. The icon sits inline before the label and
 * inherits the button's font-size + colour.
 *
 * Usage:
 *
 *   <Button href="/apps">Install</Button>
 *   <Button variant="secondary" href="/partners">Get a demo</Button>
 *   <Button variant="ghost" href="https://github.com/...">View on GitHub →</Button>
 *   <Button
 *     variant="on-dark-tertiary"
 *     icon="github"
 *     href="https://codeberg.org/Conduction/shillinq"
 *   >View on GitHub</Button>
 */

import React from 'react';
import {ICONS} from './icons';
import styles from './Button.module.css';

export default function Button({
  variant = 'primary',
  tone,
  href,
  size = 'md',
  icon,
  className,
  children,
  ...rest
}) {
  const composed = [
    styles.btn,
    styles['v-' + variant],
    styles['s-' + size],
    tone && styles['t-' + tone],
    className,
  ].filter(Boolean).join(' ');

  /* Icon: accept a string key (looked up in ICONS) or a React node
     directly. The wrapping span lets us apply consistent inline metrics
     without forcing every caller to size their SVG. */
  const iconNode = typeof icon === 'string' ? ICONS[icon] : icon;
  const iconEl = iconNode ? (
    <span className={styles.icon} aria-hidden="true">{iconNode}</span>
  ) : null;

  const body = <>{iconEl}{children}</>;

  if (href) {
    return <a href={href} className={composed} {...rest}>{body}</a>;
  }
  return <button type="button" className={composed} {...rest}>{body}</button>;
}
