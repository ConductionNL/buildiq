/**
 * <Prerequisites /> + <PrerequisiteItem />
 *
 * Tinted card with a checklist of things the reader needs before
 * starting an academy tutorial. Replaces the ad-hoc "What you need"
 * h2 + bullet list pattern that was duplicated across tutorials.
 *
 * Visual: shared <HexCard> shell with a clipboard icon in the
 * top-left badge. Each item renders with a small orange hex-bullet
 * on the left so the list reads as a checklist instead of generic
 * prose.
 *
 * Usage:
 *
 *   <Prerequisites title="Wat je nodig hebt">
 *     <PrerequisiteItem>
 *       Een werkend Woo-register. Volg eerst <a href="...">Een Woo-register opzetten</a> als je dat nog niet hebt.
 *     </PrerequisiteItem>
 *     <PrerequisiteItem>Een publicatie-object met bekende UUID</PrerequisiteItem>
 *     <PrerequisiteItem><code>curl</code>, basisauth, en een paar testbestanden</PrerequisiteItem>
 *   </Prerequisites>
 *
 * Mirrors the .prerequisites section in
 * preview/components/prerequisites.html.
 */

import React from 'react';
import HexCard from '../HexCard/HexCard';
import styles from './Prerequisites.module.css';

export function PrerequisiteItem({children, className}) {
  const composed = [styles.item, className].filter(Boolean).join(' ');
  return (
    <li className={composed}>
      <span className={styles.bullet} aria-hidden="true" />
      <span className={styles.body}>{children}</span>
    </li>
  );
}

export default function Prerequisites({title = 'What you need', children, className}) {
  return (
    <HexCard title={title} icon="clipboard" className={className}>
      <ul>{children}</ul>
    </HexCard>
  );
}
