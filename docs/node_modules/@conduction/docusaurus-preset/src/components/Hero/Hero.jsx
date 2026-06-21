/**
 * <Hero />
 *
 * Two-column landing hero, mirrors preview/pages/landing.html exactly.
 * Left column: optional eyebrow, headline, lede paragraph, action
 * cluster (primary + secondary + ghost + optional meta). Right column:
 * children (typically <div className="hex-rain"/>, a cn-platform
 * diagram, or any visual that fills the row).
 *
 * Each CTA has the shape { label, href }. The ghost CTA renders as a
 * plain link with an arrow; the meta block is a vertical "headline +
 * subhead" caption that sits inline with the actions.
 *
 * Composes from primitives:
 *   <Eyebrow/>  for the orange-bullet caption
 *   <Button/>   for the three action variants (primary/secondary/ghost)
 *
 * Usage in MDX:
 *
 *   <Hero
 *     eyebrow={<>Twelve open-source apps · one <span className="next-blue">Nextcloud</span></>}
 *     title={<>Install your stack.<br/>In two minutes.</>}
 *     lede={<>Twelve open-source apps that plug into <span className="next-blue">Nextcloud</span>...</>}
 *     primaryCta={{label: "Install from Nextcloud app store", href: "/apps"}}
 *     secondaryCta={{label: "Get a demo via a partner", href: "/partners"}}
 *     tertiaryCta={{label: "View on GitHub", href: "https://github.com/..."}}
 *   >
 *     <div className="hex-rain" aria-label="..."></div>
 *   </Hero>
 */

import React, {useEffect} from 'react';
import useIsBrowser from '@docusaurus/useIsBrowser';
import Eyebrow from '../primitives/Eyebrow';
import Button from '../primitives/Button';
import styles from './Hero.module.css';

export default function Hero({
  eyebrow,
  title,
  lede,
  primaryCta,
  secondaryCta,
  tertiaryCta,
  meta,
  children,
}) {
  const isBrowser = useIsBrowser();

  /* Lazy-register the diagrams web components on the client so any
     <cn-*> elements passed as children render. The import is no-op
     once registered (customElements.define guards). */
  useEffect(() => {
    if (isBrowser) {
      import('../../diagrams/index.js').catch(() => {
        /* Defensive: dynamic import should always succeed since the
           diagrams runtime ships in the same package. */
      });
    }
  }, [isBrowser]);

  return (
    <section className={styles.hero}>
      <div className={styles.copy}>
        {eyebrow && <Eyebrow>{eyebrow}</Eyebrow>}
        {title && <h1 className={styles.title}>{title}</h1>}
        {lede && <p className={styles.lede}>{lede}</p>}

        {(primaryCta || secondaryCta || tertiaryCta || meta) && (
          <div className={styles.actions}>
            {primaryCta && (
              <Button variant="primary" href={primaryCta.href || '#'}>
                {primaryCta.label}
              </Button>
            )}
            {secondaryCta && (
              <Button variant="secondary" href={secondaryCta.href || '#'}>
                {secondaryCta.label}
              </Button>
            )}
            {tertiaryCta && (
              <Button variant="ghost" href={tertiaryCta.href || '#'}>
                {tertiaryCta.label} →
              </Button>
            )}
            {meta && (
              <div className={styles.meta}>
                {meta.primary && <strong>{meta.primary}</strong>}
                {meta.secondary && <span>{meta.secondary}</span>}
              </div>
            )}
          </div>
        )}
      </div>

      <div className={styles.visual}>
        {children}
      </div>
    </section>
  );
}
