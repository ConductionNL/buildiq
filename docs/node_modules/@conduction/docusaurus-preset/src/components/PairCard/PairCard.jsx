/**
 * <PairCard /> + <PairRow />
 *
 * Compact "related item" card from preview/components/pair-cards.html.
 * Used as the "Pairs well with" / "See also" row on app and solution
 * detail pages. Smaller and denser than <AppCard/>.
 *
 * Visual: cobalt icon-hex on the left, name + why on the right.
 *
 * Brand icons (Nextcloud, Common Ground+) ship with their own hex
 * fill and ink colour per the design-system rule. Pass iconBg +
 * iconColor to override the default cobalt + white pair, and the
 * card switches to the "brand" icon-wrap variant: the SVG renders
 * as a filled mark instead of a stroke-only glyph.
 *
 * Usage:
 *
 *   <PairRow>
 *     <PairCard
 *       href="/apps/opencatalogi"
 *       icon={<svg>...</svg>}
 *       name="OpenCatalogi"
 *       why="Surfaces every register as a public, searchable catalog entry."
 *     />
 *     <PairCard
 *       href="/connext"
 *       icon={<svg>{nextcloud logo}</svg>}
 *       iconBg="var(--c-nextcloud-blue)"
 *       iconColor="white"
 *       name={<>Con<NextBlue>Next</NextBlue></>}
 *       why="..."
 *     />
 *   </PairRow>
 */

import React from 'react';
import styles from './PairCard.module.css';

export function PairRow({columns = 3, children, className}) {
  const composed = [styles.row, styles['cols-' + columns], className].filter(Boolean).join(' ');
  return <div className={composed}>{children}</div>;
}

export default function PairCard({href, icon, iconBg, iconColor, name, why, className}) {
  const Tag = href ? 'a' : 'div';
  const isBrand = Boolean(iconBg || iconColor);
  const wrapClass = [styles.iconWrap, isBrand && styles.iconWrapBrand].filter(Boolean).join(' ');
  const wrapStyle = isBrand ? {background: iconBg, color: iconColor} : undefined;
  return (
    <Tag href={href} className={[styles.card, className].filter(Boolean).join(' ')}>
      <div className={wrapClass} style={wrapStyle}>{icon}</div>
      <div>
        {name && <div className={styles.name}>{name}</div>}
        {why && <div className={styles.why}>{why}</div>}
      </div>
    </Tag>
  );
}
