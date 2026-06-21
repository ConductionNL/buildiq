/**
 * <Section />
 *
 * The 1280px max-width 64px-padded section wrapper that every page in
 * preview/pages/* uses. Eight components currently re-declare these
 * exact values in their own CSS module; centralising prevents drift.
 *
 * Background variants:
 *   - default:  white
 *   - tinted:   --c-cobalt-50 (used for stats strip, platform overview)
 *   - inverse:  --c-blue-cobalt with white type (used for CTA panels)
 *
 * Spacing variants:
 *   - default: 96px vertical padding
 *   - tight:   48px (stats-strip rhythm)
 *   - flush:   0    (consumer controls)
 *
 * Usage in MDX:
 *
 *   <Section background="tinted">
 *     <SectionHead eyebrow="The platform" title="..." lede="..." />
 *     <FeatureList items={[...]} />
 *   </Section>
 */

import React from 'react';
import styles from './Section.module.css';

export default function Section({
  background = 'default',
  spacing = 'default',
  as: Tag = 'section',
  className,
  innerClassName,
  children,
  ...rest
}) {
  const wrap = [
    styles.section,
    styles['bg-' + background],
    styles['sp-' + spacing],
    className,
  ].filter(Boolean).join(' ');
  return (
    <Tag className={wrap} {...rest}>
      <div className={[styles.inner, innerClassName].filter(Boolean).join(' ')}>
        {children}
      </div>
    </Tag>
  );
}
