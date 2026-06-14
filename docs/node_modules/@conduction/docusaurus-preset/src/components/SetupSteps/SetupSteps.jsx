/**
 * <SetupSteps /> + <SetupStep />
 *
 * Vertical numbered-step list for docs setup/install/walkthrough
 * sections and editorial action prompts. A small orange hex with a
 * white number sits on the left of each row; bold title + one short
 * body paragraph sit on the right.
 *
 * Distinct from <HowSteps />, which renders a 3-up card row for
 * marketing surfaces (How install works, How to get help, …).
 * SetupSteps lives in body prose where steps are sequential and
 * detailed, and the list reads top to bottom.
 *
 * Numbers are auto-assigned in document order. Pass `start` to
 * resume after a break (e.g. a continued procedure split across
 * sections).
 *
 * Usage:
 *
 *   <SetupSteps title="Setup" lede="Numbered steps you read top to bottom.">
 *     <SetupStep title="Add an xWiki source">
 *       Open *Integrations → Sources → New source*, paste the base URL.
 *     </SetupStep>
 *     <SetupStep title="Pick the spaces to index">
 *       Per source, choose one or more xWiki spaces.
 *     </SetupStep>
 *     <SetupStep title="Verify">
 *       Open the app's chat sidebar and ask a matching question.
 *     </SetupStep>
 *   </SetupSteps>
 *
 *   // Editorial / action-prompt use, no h2 above the list:
 *   <SetupSteps title={null}>
 *     <SetupStep title="Call Nextcloud by its proper name.">
 *       In tenders, in policy documents, in architecture diagrams.
 *     </SetupStep>
 *     ...
 *   </SetupSteps>
 *
 * Mirrors preview/components/setup-steps.html.
 */

import React, {Children, cloneElement, isValidElement} from 'react';
import styles from './SetupSteps.module.css';

export function SetupStep({number, title, children, className}) {
  const composed = [styles.row, className].filter(Boolean).join(' ');
  return (
    <div className={composed}>
      {number != null && <div className={styles.num}>{number}</div>}
      {/* div not p: MDX may wrap inline children in its own <p>,
          which would be invalid nested inside another <p>. */}
      <div className={styles.body}>
        {title && <span className={styles.bodyTitle}>{title}</span>}
        {children}
      </div>
    </div>
  );
}

export default function SetupSteps({
  title,
  lede,
  start = 1,
  children,
  className,
}) {
  const numbered = Children.map(children, (child, i) => {
    if (!isValidElement(child)) return child;
    if (child.props.number != null) return child;
    return cloneElement(child, {number: start + i});
  });
  const composed = [styles.wrap, className].filter(Boolean).join(' ');
  return (
    <section className={composed}>
      {title && <h2 className={styles.title}>{title}</h2>}
      {lede && <p className={styles.lede}>{lede}</p>}
      {numbered}
    </section>
  );
}
