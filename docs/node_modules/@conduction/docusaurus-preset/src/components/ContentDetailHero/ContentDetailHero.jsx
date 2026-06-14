/**
 * <ContentDetailHero />
 *
 * Header for individual academy posts. Crumb, content-type chip plus
 * topical tags, h1 title, optional summary, author byline, optional
 * duration label, then a 16:9 cover region with the honeycomb
 * watermark and either a hex-thumb icon or a hero image inside.
 *
 * Distinct from <DetailHero /> (which is for app/solution/partner
 * pages and has the 360px cobalt hex on the right). Academy detail
 * pages want a wider headline and a full-width cover image; this
 * component is built for that.
 *
 * Mirrors the .content-detail-hero section in
 * preview/components/academy.html.
 *
 * Usage:
 *
 *   <ContentDetailHero
 *     crumb={[{label: 'Academy', href: '/'}, {label: 'Guides', href: '/?type=guide'}, 'Install OpenCatalogi']}
 *     contentType="guide"
 *     tags={['OpenCatalogi', 'Install']}
 *     title="Install OpenCatalogi in two minutes."
 *     summary="From app store to first register, no terminal required."
 *     author={{ name: 'Ruben van der Linde' }}
 *     date="2026-05-05"
 *     duration="12 min read"
 *     cover={{ src: '/img/posts/install-opencatalogi.jpg', alt: 'Install screen' }}
 *   />
 *
 * If `cover` is omitted, the cover region renders a single large
 * pointy-top hex on the cobalt-900 ground. Pass `cover.icon` for a
 * custom SVG instead of the default placeholder.
 */

import React from 'react';
import HexThumbnail from '../primitives/HexThumbnail';
import AuthorByline from '../primitives/AuthorByline';
import Pill from '../primitives/Pill';
import {
  CONTENT_TYPE_LABELS,
  CONTENT_TYPE_BULLET_COLOR,
} from '../ContentTypeFilter/contentTypes';
import styles from './ContentDetailHero.module.css';

export default function ContentDetailHero({
  crumb,
  contentType,
  contentTypeLabel,
  tags = [],
  title,
  summary,
  author,
  date,
  dateLabel,
  duration,
  locale,
  cover,
  className,
}) {
  const composed = [styles.hero, className].filter(Boolean).join(' ');

  const typeLabel = contentTypeLabel
    || (contentType && CONTENT_TYPE_LABELS[contentType])
    || null;
  const typeBullet = contentType
    ? CONTENT_TYPE_BULLET_COLOR[contentType]
    : undefined;

  return (
    <section className={composed}>
      {Array.isArray(crumb) && crumb.length > 0 && (
        <div className={styles.crumb}>
          {crumb.map((c, i) => {
            const last = i === crumb.length - 1;
            const sep = !last
              ? <span className={styles.sep} aria-hidden="true">/</span>
              : null;
            if (typeof c === 'string') {
              return <React.Fragment key={i}>{c}{sep}</React.Fragment>;
            }
            return (
              <React.Fragment key={i}>
                {c.href
                  ? <a href={c.href}>{c.label}</a>
                  : <span>{c.label}</span>}
                {sep}
              </React.Fragment>
            );
          })}
        </div>
      )}

      {title && <h1 className={styles.title}>{title}</h1>}

      <div className={styles.cover}>
        <span className={styles.watermark} aria-hidden="true" />
        {cover && cover.src
          ? <img src={cover.src} alt={cover.alt || ''} className={styles.coverImg} />
          : (
            <HexThumbnail size="xl" tone={cover && cover.tone || 'cobalt'}>
              {cover && cover.icon}
            </HexThumbnail>
          )}
      </div>

      {summary && <p className={styles.summary}>{summary}</p>}

      {(typeLabel || tags.length > 0) && (
        <div className={styles.tags}>
          {typeLabel && (
            <Pill bullet bulletColor={typeBullet}>{typeLabel}</Pill>
          )}
          {tags.map((t, i) => (
            <Pill key={i} bullet bulletColor="var(--c-cobalt-300)">{t}</Pill>
          ))}
        </div>
      )}

      {(author || date || duration) && (
        <div className={styles.meta}>
          {(author || date) && (
            <AuthorByline
              name={author && author.name}
              avatarSrc={author && author.avatarSrc}
              initials={author && author.initials}
              date={date}
              dateLabel={dateLabel}
              locale={locale}
            />
          )}
          {duration && <span className={styles.duration}>{duration}</span>}
        </div>
      )}
    </section>
  );
}
