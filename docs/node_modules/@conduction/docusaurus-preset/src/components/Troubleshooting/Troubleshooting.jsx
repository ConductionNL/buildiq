/**
 * <Troubleshooting /> + <TroubleshootingItem />
 *
 * Structured error-recovery list for academy tutorials. Replaces
 * the ad-hoc "Probleemoplossing" h2 + bold-text pattern that was
 * duplicated across tutorials.
 *
 * Each item pairs a `symptom` (the user-visible error or behaviour)
 * with the body content (what to do about it). The symptom renders
 * in a subtle warning-tinted slot on the left; the body fills the
 * rest. Keep symptoms short and specific (literal error strings
 * work best). Keep bodies under three sentences.
 *
 * Usage:
 *
 *   <Troubleshooting title="Probleemoplossing">
 *     <TroubleshootingItem symptom="The uploaded file exceeds upload_max_filesize">
 *       Verhoog <code>upload_max_filesize</code> en <code>post_max_size</code> in
 *       <code>php.ini</code>, of stap over op Mode 4.
 *     </TroubleshootingItem>
 *     <TroubleshootingItem symptom="Bestand verschijnt niet in /files-lijst na MOVE">
 *       Controleer dat de <code>Destination</code>-header naar <code>Open Registers/...</code> wijst.
 *     </TroubleshootingItem>
 *   </Troubleshooting>
 *
 * Mirrors the .troubleshooting section in
 * preview/components/troubleshooting.html.
 */

import React from 'react';
import styles from './Troubleshooting.module.css';

export function TroubleshootingItem({symptom, children, className}) {
  const composed = [styles.item, className].filter(Boolean).join(' ');
  return (
    <div className={composed}>
      {symptom && (
        <div className={styles.symptom}>
          <span className={styles.icon} aria-hidden="true">!</span>
          <code className={styles.symptomText}>{symptom}</code>
        </div>
      )}
      <div className={styles.body}>{children}</div>
    </div>
  );
}

export default function Troubleshooting({title = 'Troubleshooting', lede, children, className}) {
  const composed = [styles.section, className].filter(Boolean).join(' ');
  return (
    <section className={composed}>
      {title && <h2 className={styles.title}>{title}</h2>}
      {lede && <p className={styles.lede}>{lede}</p>}
      <div className={styles.list}>{children}</div>
    </section>
  );
}
