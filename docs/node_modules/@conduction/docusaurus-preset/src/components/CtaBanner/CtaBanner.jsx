/**
 * <CtaBanner />
 *
 * Cobalt-900 marketing panel with a centred title, lede, and a
 * primary + secondary CTA pair. Used at the bottom of marketing
 * pages ("Ready to install?", "Pick an app. Install it. Done.").
 *
 * Mirrors the cta-banner section in preview/pages/landing.html and
 * the .cta-banner mock in preview/components.html.
 *
 * Composition:
 *   - <Button variant="on-dark-primary" />  for the white pill
 *   - <Button variant="on-dark-secondary" /> for the bordered ghost
 *   - <ConductionBg />                       for the parallax hex layer
 *
 * Usage in MDX:
 *
 *   <CtaBanner
 *     title="Pick an app. Install it. Done."
 *     lede={<>Drop OpenCatalogi into your <span className="next-blue">Nextcloud</span> in two minutes.</>}
 *     primaryCta={{ label: "Install from app store", href: "/apps" }}
 *     secondaryCta={{ label: "Get a demo via a partner", href: "/partners" }}
 *   />
 */

import React from 'react';
import ConductionBg from '../ConductionBg/ConductionBg';
import Button from '../primitives/Button';
import styles from './CtaBanner.module.css';

export default function CtaBanner({
  title,
  lede,
  primaryCta,
  secondaryCta,
}) {
  return (
    <section className={styles.section}>
      <div className={styles.banner}>
        <ConductionBg />
        {title && <h2 className={styles.title}>{title}</h2>}
        {lede && <p className={styles.lede}>{lede}</p>}

        {(primaryCta || secondaryCta) && (
          <div className={styles.actions}>
            {primaryCta && (
              <Button variant="on-dark-primary" href={primaryCta.href || '#'}>
                {primaryCta.label}
              </Button>
            )}
            {secondaryCta && (
              <Button variant="on-dark-secondary" href={secondaryCta.href || '#'}>
                {secondaryCta.label}
              </Button>
            )}
          </div>
        )}
      </div>
    </section>
  );
}
