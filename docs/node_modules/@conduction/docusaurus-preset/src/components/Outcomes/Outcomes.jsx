/**
 * <Outcomes /> + <Outcome />
 *
 * Sibling to <Prerequisites />, intended for the *top* of academy
 * tutorials. Lists the concrete things the reader will have built,
 * learned, or achieved by the end of the tutorial. Goes right after
 * the lede paragraph and before <Prerequisites />, so the reader
 * scans:
 *   - "What am I going to walk away with?" (Outcomes)
 *   - "What do I need before I start?"     (Prerequisites)
 *   - the actual tutorial body
 *
 * Visual: shared <HexCard> shell with a lightbulb icon in the
 * top-left badge. Each item uses an orange checkmark glyph
 * (achievement) instead of the orange hex bullet (need) used in
 * <Prerequisites />. Same surface, distinct semantics.
 *
 * Usage:
 *
 *   <Outcomes title="Wat je leert">
 *     <Outcome>Hoe je het canonieke Woo-register importeert</Outcome>
 *     <Outcome>Hoe je een publicatie aanmaakt en koppelt aan een TOOI-categorie</Outcome>
 *     <Outcome>Welke API-aanroepen hoort bij elk van de stappen</Outcome>
 *   </Outcomes>
 *
 * Mirrors the .outcomes section in
 * preview/components/outcomes.html.
 */

import React from 'react';
import HexCard from '../HexCard/HexCard';
import styles from './Outcomes.module.css';

export function Outcome({children, className}) {
  const composed = [styles.item, className].filter(Boolean).join(' ');
  return (
    <li className={composed}>
      <svg className={styles.check} viewBox="0 0 24 24" aria-hidden="true"
           fill="none" stroke="currentColor" strokeWidth="3"
           strokeLinecap="round" strokeLinejoin="round">
        <path d="M5 13l4 4L19 7"/>
      </svg>
      <span className={styles.body}>{children}</span>
    </li>
  );
}

export default function Outcomes({title = "What you'll learn", children, className}) {
  return (
    <HexCard title={title} icon="lightbulb" className={className}>
      <ul>{children}</ul>
    </HexCard>
  );
}
