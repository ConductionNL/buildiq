/**
 * <FeatureList /> + <FeatureItem />
 *
 * Long, single-column feature list from preview/components/feature-list.html.
 * Used in the broad column on app and solution detail pages, scales
 * cleanly to 6-12 items.
 *
 * Each feature pairs a cobalt-50 hex glyph with a heading and a short
 * body paragraph.
 *
 * Usage in MDX:
 *
 *   <FeatureList items={[
 *     {icon: <svg>...</svg>, title: 'Schemas you write once.', body: '...'},
 *     {icon: <svg>...</svg>, title: 'One backbone, every app.', body: '...'},
 *   ]} />
 *
 * Or with children for full MDX flexibility:
 *
 *   <FeatureList>
 *     <FeatureItem icon={<svg/>} title="...">
 *       Body paragraph...
 *     </FeatureItem>
 *   </FeatureList>
 */

import React from 'react';
import styles from './FeatureList.module.css';

export function FeatureItem({icon, title, body, children}) {
  return (
    <div className={styles.feature}>
      <div className={styles.glyph}>{icon}</div>
      <div>
        {title && <h3 className={styles.title}>{title}</h3>}
        {/* `body` prop is a string or inline JSX, wrap in <p> for typography.
            `children` come from MDX which already wraps loose text in <p>;
            using <div> here avoids the nested <p> DOM-validation warning. */}
        {body && <p className={styles.body}>{body}</p>}
        {!body && children && <div className={styles.body}>{children}</div>}
      </div>
    </div>
  );
}

export default function FeatureList({items, children, className}) {
  return (
    <div className={[styles.list, className].filter(Boolean).join(' ')}>
      {items
        ? items.map((it, i) => <FeatureItem key={i} {...it} />)
        : children}
    </div>
  );
}
