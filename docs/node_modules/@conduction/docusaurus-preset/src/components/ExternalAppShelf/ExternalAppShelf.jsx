/**
 * <ExternalAppShelf />
 *
 * Card grid for external open-source apps that Conduction integrates
 * into the Nextcloud workspace as ExApps. Each card carries the
 * project's brand-coloured logo tile + name + a one-sentence
 * description + an outbound link. Used on /connext to show the
 * "we don't own these projects, we provide the interface" story.
 *
 * Usage in MDX:
 *
 *   <ExternalAppShelf
 *     apps={[
 *       {
 *         name: 'OpenTalk',
 *         brandColor: '#00485C',
 *         desc: 'Open-source video conferencing.',
 *         href: 'https://opentalk.eu/',
 *         icon: <svg viewBox="0 0 24 24">...</svg>,
 *       },
 *       ...
 *     ]}
 *   />
 *
 * The grid is responsive (auto-fill, min 240px). Each card lifts on
 * hover, the brand-colour wash on the icon tile bleeds slightly into
 * the card on hover so the brand reads as the primary identifier.
 */

import React from 'react';
import styles from './ExternalAppShelf.module.css';

export default function ExternalAppShelf({apps = [], className}) {
  return (
    <div className={[styles.shelf, className].filter(Boolean).join(' ')}>
      {apps.map((app, i) => {
        const Tag = app.href ? 'a' : 'div';
        const linkProps = app.href
          ? {href: app.href, target: '_blank', rel: 'noopener noreferrer'}
          : {};
        return (
          <Tag
            key={i}
            className={[styles.card, app.href && styles.linked].filter(Boolean).join(' ')}
            {...linkProps}
          >
            <div className={styles.iconTile} style={{background: app.brandColor || 'var(--c-cobalt-700)'}}>
              <span className={styles.icon} aria-hidden="true">{app.icon}</span>
            </div>
            <div className={styles.body}>
              <h3 className={styles.name}>{app.name}</h3>
              {app.meta && <span className={styles.meta}>{app.meta}</span>}
              {app.desc && <p className={styles.desc}>{app.desc}</p>}
            </div>
            {app.href && <span className={styles.arrow} aria-hidden="true">↗</span>}
          </Tag>
        );
      })}
    </div>
  );
}
