/**
 * <Showcase />
 *
 * Two-pane explainer: a stack of expandable items on the left, a
 * panel on the right that reflects whichever item is open. Reference:
 * honeycomb.io/use-cases/ai-llm-observability ("Observability with AI
 * Agents" accordion + product-mock pattern), translated to Conduction
 * tokens.
 *
 * The right panel can be anything — an <AppMock>, a custom JSX
 * illustration, an image. The component owns nothing about the panel
 * shape; it just swaps which one is mounted when the user expands a
 * different item.
 *
 * Used for two surfaces today:
 *   - "The Conduction Difference" (5 rules, one panel per rule)
 *   - "Solutions for every team" (1 use case per audience tier)
 *
 * Same shape, different labels. Set `layout="stack"` to render the
 * items as horizontal tabs above the panel instead of as a vertical
 * accordion left of it (the "Solutions for every team" variant).
 *
 * Usage in MDX:
 *
 *   <Showcase
 *     eyebrow="The Conduction Difference"
 *     title="Five rules, one workspace."
 *     items={[
 *       {
 *         id: 'open',
 *         title: 'Open by default.',
 *         summary: 'Every line is EUPL-1.2 on GitHub.',
 *         cta: {label: 'Browse on GitHub', href: 'https://codeberg.org/Conduction'},
 *         panel: <AppMock app="opencatalogi" />,
 *       },
 *       ...
 *     ]}
 *     defaultOpen="open"
 *   />
 */

import React, {useState} from 'react';
import styles from './Showcase.module.css';

export default function Showcase({
  eyebrow,
  title,
  lede,
  items = [],
  defaultOpen,
  layout = 'side',
  className,
}) {
  const initialId = defaultOpen || items[0]?.id;
  const [openId, setOpenId] = useState(initialId);
  /* The right-side panel always shows *something*. When the user toggles
     the open item closed, fall back to the most recently opened panel
     so the showcase doesn't go blank. lastPanelId tracks that. */
  const [lastPanelId, setLastPanelId] = useState(initialId);
  const open = items.find(it => it.id === openId) || null;
  const panelItem = items.find(it => it.id === (openId || lastPanelId)) || items[0];

  const toggle = (id) => {
    if (openId === id) {
      setOpenId(null);
    } else {
      setOpenId(id);
      setLastPanelId(id);
    }
  };

  return (
    <section className={[styles.showcase, styles[`layout-${layout}`], className].filter(Boolean).join(' ')}>
      {(eyebrow || title || lede) && (
        <header className={styles.head}>
          {eyebrow && <div className={styles.eyebrow}><span className={styles.h}></span>{eyebrow}</div>}
          {title && <h2 className={styles.title}>{title}</h2>}
          {lede && <p className={styles.lede}>{lede}</p>}
        </header>
      )}
      <div className={styles.body}>
        <div className={styles.list} role="tablist" aria-orientation={layout === 'side' ? 'vertical' : 'horizontal'}>
          {items.map((it) => {
            const isOpen = it.id === open?.id;
            return (
              <div key={it.id} className={[styles.item, isOpen && styles.itemOpen].filter(Boolean).join(' ')}>
                <button
                  role="tab"
                  aria-selected={isOpen}
                  aria-expanded={isOpen}
                  aria-controls={`showcase-panel-${it.id}`}
                  className={styles.itemHead}
                  onClick={() => toggle(it.id)}
                  type="button"
                >
                  <span className={styles.titleGroup}>
                    {it.icon && (
                      <span className={styles.itemIcon} aria-hidden="true">
                        {typeof it.icon === 'string'
                          ? <img src={it.icon} alt="" className={styles.itemIconImg} />
                          : it.icon}
                      </span>
                    )}
                    <h3 className={styles.itemTitle}>{it.title}</h3>
                  </span>
                  <span className={styles.chevron} aria-hidden="true">{isOpen ? '−' : '+'}</span>
                </button>
                {isOpen && (
                  <div className={styles.itemBody}>
                    {it.summary && <p className={styles.itemSummary}>{it.summary}</p>}
                    {it.cta && (
                      <a href={it.cta.href} className={styles.cta}>
                        {it.cta.label}
                      </a>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
        <div className={styles.panel} role="tabpanel" id={`showcase-panel-${panelItem?.id}`} aria-label={panelItem?.title}>
          <div className={styles.panelHexBg} aria-hidden="true"></div>
          <div className={styles.panelInner}>{panelItem?.panel}</div>
        </div>
      </div>
    </section>
  );
}
