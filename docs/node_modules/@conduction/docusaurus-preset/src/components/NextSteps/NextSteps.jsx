/**
 * <NextSteps /> + <NextStep />
 *
 * Action-card row for the end of an academy tutorial. Replaces the
 * ad-hoc "Volgende stap" h2 + bullet list pattern that was
 * duplicated across tutorials.
 *
 * Each <NextStep/> is a clickable card with a short title, a
 * one-line description, and a target href. The whole card is the
 * link target so the entire tile is clickable. Use the optional
 * `cta` prop to override the default "Read more" label.
 *
 * Usage:
 *
 *   <NextSteps title="Volgende stap" lede="Met deze vier modes kun je elk type document koppelen.">
 *     <NextStep
 *       href="/apps/opencatalogi"
 *       title="Toon op een portaal"
 *       cta="Bekijk OpenCatalogi"
 *     >
 *       Combineer publicaties met andere apps op een publiek portaal.
 *     </NextStep>
 *     <NextStep
 *       href="/apps/docudesk"
 *       title="Documenten lakken"
 *       cta="Bekijk DocuDesk"
 *     >
 *       Anonimiseer privacygevoelige passages voor publicatie.
 *     </NextStep>
 *   </NextSteps>
 *
 * Mirrors the .next-steps section in
 * preview/components/next-steps.html.
 */

import React from 'react';
import {translate} from '@docusaurus/Translate';
import styles from './NextSteps.module.css';

export function NextStep({href, title, cta, children, className}) {
  const composed = [styles.card, className].filter(Boolean).join(' ');
  const Tag = href ? 'a' : 'div';
  const props = href ? {href} : {};
  const resolvedCta = cta ?? translate({
    id: 'preset.nextSteps.step.cta',
    message: 'Read more',
    description: 'Default CTA label on a NextStep card when the consuming MDX does not pass cta',
  });
  return (
    <Tag className={composed} {...props}>
      {title && <div className={styles.title}>{title}</div>}
      {children && <div className={styles.body}>{children}</div>}
      {href && (
        <div className={styles.cta}>
          {resolvedCta}
          <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"
               fill="none" stroke="currentColor" strokeWidth="2"
               strokeLinecap="round" strokeLinejoin="round">
            <path d="M5 12h14M12 5l7 7-7 7"/>
          </svg>
        </div>
      )}
    </Tag>
  );
}

export default function NextSteps({title, lede, children, columns = 3, className}) {
  const resolvedTitle = title ?? translate({
    id: 'preset.nextSteps.title',
    message: 'Next steps',
    description: 'Default section heading on NextSteps when the consuming MDX does not pass title',
  });
  const composed = [styles.section, className].filter(Boolean).join(' ');
  const gridClass = [styles.grid, styles['cols-' + columns]].join(' ');
  return (
    <section className={composed}>
      {resolvedTitle && <h2 className={styles.title}>{resolvedTitle}</h2>}
      {lede && <p className={styles.lede}>{lede}</p>}
      <div className={gridClass}>{children}</div>
    </section>
  );
}
