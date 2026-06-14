/**
 * <DownloadPanel />
 *
 * Split cobalt-800 panel with a pitch on the left and a cut-corner
 * white card on the right. Modelled on the lead-capture specimen in
 * preview/components.html (.download-panel) and re-homed in
 * preview/components/download-panel.html.
 *
 * The card is form-less by default and renders a single primary
 * CTA, KNVB-orange via Button tone="orange". The form variant from
 * the kit can be reintroduced later by passing `card.children` and
 * dropping the cta.
 *
 * Usage in MDX:
 *
 *   <DownloadPanel
 *     title={<>This is not an app shelf.<br/>It is <span className="next-blue">Nextcloud</span> as a platform.</>}
 *     lede="The sovereign-workplace bundles are the start, not the finish."
 *     meta="Thinkpiece · 7 min · By Ruben van der Linde"
 *     card={{
 *       title: 'Want the full essay?',
 *       cta: {label: 'Read the thinkpiece', href: '/academy/the-platform-moment'},
 *     }}
 *   />
 */

import React from 'react';
import ConductionBg from '../ConductionBg/ConductionBg';
import Button from '../primitives/Button';
import styles from './DownloadPanel.module.css';

export default function DownloadPanel({
  title,
  lede,
  meta,
  card,
}) {
  return (
    <section className={styles.section}>
      <div className={styles.inner}>
        <div className={styles.panel}>
          <ConductionBg />
          <div className={styles.pitch}>
            {title && <h3 className={styles.title}>{title}</h3>}
            {lede && <p className={styles.lede}>{lede}</p>}
            {meta && <p className={styles.meta}>{meta}</p>}
          </div>
          {card && (
            <div className={styles.card}>
              {card.title && <h4 className={styles.cardTitle}>{card.title}</h4>}
              {card.body && <p className={styles.cardBody}>{card.body}</p>}
              {card.children}
              {card.cta && (
                <Button
                  variant="primary"
                  tone="orange"
                  href={card.cta.href}
                  className={styles.cardCta}
                >
                  {card.cta.label}
                </Button>
              )}
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
