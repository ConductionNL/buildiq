/**
 * <RotatingCards />
 *
 * Scroll-pinned stacked cards, after honeycomb.io home "What
 * Honeycomb helps you do". Each card sticks to the top of the
 * viewport as you scroll past, the next card slides up from below to
 * cover it, so the surface advances by scrolling instead of by click
 * or auto-rotation. No timer, no carousel, no pagination.
 *
 * Per-card layout:
 *   ┌────────────────────────────┬────────────────────────┐
 *   │                            │                        │
 *   │  [coloured panel]          │   [white text panel]   │
 *   │                            │                        │
 *   │  abstract product mock     │   eyebrow              │
 *   │  on a hex backdrop         │   title                │
 *   │                            │   summary              │
 *   │                            │   cta                  │
 *   └────────────────────────────┴────────────────────────┘
 *
 * Each card carries a tone (mint, lavender, terracotta, forest,
 * coral, workspace) that drives the coloured-panel side. The text
 * side stays white throughout for legibility.
 *
 * Usage in MDX:
 *
 *   <RotatingCards
 *     eyebrow="What Conduction helps you do"
 *     title="Six things, in two minutes."
 *     cards={[
 *       {
 *         tone: 'forest',
 *         eyebrow: 'See everything',
 *         title: 'Publish a public catalogue.',
 *         summary: '...',
 *         cta: {label: 'See OpenCatalogi', href: '/apps/opencatalogi'},
 *         panel: <AppMock app="opencatalogi" />,
 *       },
 *       ...
 *     ]}
 *   />
 *
 * Implementation note: each card is `position: sticky; top: <stagger>`,
 * staggered so card N+1 ends up slightly above card N when both are
 * pinned. The total scroll distance is sized by the wrapper's height
 * (one viewport per card minus the stagger).
 */

import React from 'react';
import HexBackground from '../HexBackground/HexBackground.jsx';
import styles from './RotatingCards.module.css';

export default function RotatingCards({
  eyebrow,
  title,
  lede,
  cards = [],
  className,
}) {
  if (cards.length === 0) return null;
  return (
    <section className={[styles.root, className].filter(Boolean).join(' ')}>
      {(eyebrow || title || lede) && (
        <header className={styles.head}>
          {eyebrow && <div className={styles.eyebrow}><span className={styles.h}></span>{eyebrow}</div>}
          {title && <h2 className={styles.title}>{title}</h2>}
          {lede && <p className={styles.lede}>{lede}</p>}
        </header>
      )}
      <div className={styles.stack} style={{'--stack-count': cards.length}}>
        {cards.map((card, i) => (
          <article
            key={i}
            className={[styles.card, styles[`tone-${card.tone || 'cobalt'}`]].join(' ')}
            style={{'--i': i, top: `calc(var(--card-top, 80px) + ${i * 24}px)`}}
          >
            <div className={styles.cardImage}>
              <HexBackground tone={`${card.tone || 'cobalt'}-100`} position="top" size="lg" density={2} />
              <div className={styles.cardImageInner}>
                {card.panel}
              </div>
            </div>
            <div className={styles.cardText}>
              {card.eyebrow && <div className={styles.cardEyebrow}>{card.eyebrow}</div>}
              {card.title && <h3 className={styles.cardTitle}>{card.title}</h3>}
              {card.summary && <p className={styles.cardSummary}>{card.summary}</p>}
              {card.cta && (
                <a href={card.cta.href} className={styles.cardCta}>
                  {card.cta.label}
                </a>
              )}
            </div>
          </article>
        ))}
      </div>
    </section>
  );
}
