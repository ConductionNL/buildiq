/**
 * <HowSteps /> + <HowStep />
 *
 * Numbered process-step row, surfaced from preview/pages/support.html
 * (.how-row, .how-step). Three or more cards in a row with a tinted
 * hex number indicator and a heading + body. Used on:
 *   - /support  ("How to get help, in three steps")
 *   - /install  ("How install works, three steps")
 *   - /partners/become  ("Partner programme, three tiers")
 *
 * Each step's number-hex tints alternately by tier: cobalt, KNVB
 * orange, cobalt-700. Pass `tier="1|2|3"` to override.
 *
 * Usage:
 *
 *   <HowSteps>
 *     <HowStep number="1" title="Read the docs first.">
 *       The /docs section covers most of what we'd answer in support.
 *     </HowStep>
 *     <HowStep number="2" title="Open a GitHub issue.">
 *       For bugs and feature requests. We triage daily.
 *     </HowStep>
 *     <HowStep number="3" title="Talk to a partner.">
 *       For implementation help, hosting, and rollout.
 *     </HowStep>
 *   </HowSteps>
 */

import React, {Children, cloneElement, isValidElement} from 'react';
import styles from './HowSteps.module.css';

export function HowStep({number, tier, title, children, className}) {
  const tierKey = tier || (number ? String(((parseInt(number, 10) - 1) % 3) + 1) : '1');
  const composed = [styles.step, styles['tier-' + tierKey], className].filter(Boolean).join(' ');
  return (
    <div className={composed}>
      {number && <div className={styles.num}>{number}</div>}
      {title && <h3 className={styles.title}>{title}</h3>}
      {/* div not p: MDX may wrap inline children in its own <p>, which
          would be invalid nested inside another <p>. */}
      {children && <div className={styles.body}>{children}</div>}
    </div>
  );
}

export default function HowSteps({columns = 3, children, className}) {
  /* Auto-number children that don't pass an explicit `number`. Lets
     callers write `<HowStep title="...">body</HowStep>` without
     book-keeping. */
  const numbered = Children.map(children, (child, i) => {
    if (!isValidElement(child)) return child;
    if (child.props.number != null) return child;
    return cloneElement(child, {number: i + 1});
  });
  const composed = [styles.row, styles['cols-' + columns], className].filter(Boolean).join(' ');
  return <div className={composed}>{numbered}</div>;
}
