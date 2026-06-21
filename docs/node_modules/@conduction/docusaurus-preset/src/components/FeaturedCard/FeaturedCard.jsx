/**
 * <FeaturedCard />
 *
 * Hero spot at the top of the academy landing. Cobalt-900 ground, body
 * copy on the left, a large pointy-top hex with two satellite hexes on
 * the right. Used to surface one editorial pick per page.
 *
 * Mirrors the .featured-card section in preview/components/academy.html.
 *
 * Per huisstijl, the satellite-3 hex is the page's single KNVB orange
 * accent; the rest is solid cobalt + white. Pass `accent="cobalt"` to
 * suppress the orange accent on screens that already use orange
 * elsewhere.
 *
 * Usage in MDX:
 *
 *   <FeaturedCard
 *     href="/posts/install-opencatalogi"
 *     eyebrow="Featured guide"
 *     title="Install OpenCatalogi in two minutes."
 *     lede="From app store to first register, no terminal required."
 *     ctaLabel="Read the guide"
 *     author={{ name: "Ruben van der Linde" }}
 *     date="2026-05-05"
 *     thumbnail={{ icon: <svg>...</svg> }}
 *   />
 */

import React from 'react';
import {translate} from '@docusaurus/Translate';
import HexThumbnail from '../primitives/HexThumbnail';
import HexBullet from '../primitives/HexBullet';
import AuthorByline from '../primitives/AuthorByline';
import {AUDIENCE_SHORT_LABELS} from '../../data/audience';
import styles from './FeaturedCard.module.css';

function readOrWatch(contentType, minutes) {
  if (!minutes) return null;
  return contentType === 'webinar'
    ? translate({id: 'preset.featuredCard.minWatch', message: '{minutes} min watch', description: 'Duration label for video content'}, {minutes})
    : translate({id: 'preset.featuredCard.minRead', message: '{minutes} min read', description: 'Duration label for text content'}, {minutes});
}

export default function FeaturedCard({
  href,
  eyebrow,
  title,
  lede,
  ctaLabel,
  author,
  date,
  dateLabel,
  locale,
  thumbnail,
  accent = 'orange',
  contentType,
  durationMinutes,
  audience = [],
  module: moduleSlug,
  modulePosition,
  moduleTotalParts,
  moduleTitle,
  className,
}) {
  const readWatch = readOrWatch(contentType, durationMinutes);
  const resolvedCtaLabel = ctaLabel ?? translate({
    id: 'preset.featuredCard.cta',
    message: 'Read more',
    description: 'Default CTA label on FeaturedCard when the consuming MDX does not pass ctaLabel',
  });
  const moduleLabel = moduleSlug
    ? (modulePosition && moduleTotalParts
        ? translate({id: 'preset.featuredCard.modulePartOf', message: 'Part {position} of {total}', description: 'Module-position label, e.g. "Part 2 of 4"'}, {position: modulePosition, total: moduleTotalParts})
        : (modulePosition ? translate({id: 'preset.featuredCard.modulePart', message: 'Part {position}', description: 'Module-position label when total parts is unknown'}, {position: modulePosition}) : null))
    : null;
  /* Audience renders on its own "For: A, B, C" line below the meta bits,
     matching ContentCard and ModuleCard. Each short label gets its own
     translate() so consuming sites can localise the audience names
     without forking the data file. */
  const audienceLabels = audience
    .map((a) => translate(
      {
        id: `preset.audience.short.${a}`,
        message: AUDIENCE_SHORT_LABELS[a] || a,
        description: `Short audience label used in the "For: ..." line. Slug: ${a}.`,
      },
    ));
  const audienceLine = audienceLabels.length > 0
    ? translate(
        {
          id: 'preset.featuredCard.audienceFor',
          message: 'For: {list}',
          description: 'Audience line under the FeaturedCard meta row. {list} is a comma-separated audience list.',
        },
        {list: audienceLabels.join(', ')},
      )
    : null;
  const metaBits = [readWatch, moduleLabel, moduleSlug ? (moduleTitle || moduleSlug) : null]
    .filter(Boolean);
  const Tag = href ? 'a' : 'div';
  const composed = [styles.card, className].filter(Boolean).join(' ');
  const thumbProps = thumbnail || {};

  return (
    <Tag href={href} className={composed}>
      <div className={styles.body}>
        {eyebrow && (
          <span className={styles.eyebrow}>
            <HexBullet size="md" color="var(--c-orange-knvb)" />
            <span>{eyebrow}</span>
          </span>
        )}
        {title && <h2 className={styles.title}>{title}</h2>}
        {lede && <p className={styles.lede}>{lede}</p>}

        {metaBits.length > 0 && (
          <div className={styles.metaBits}>
            {metaBits.map((bit, i) => (
              <React.Fragment key={i}>
                {i > 0 && <span className={styles.metaSep} aria-hidden="true">·</span>}
                <span className={styles.metaBit}>{bit}</span>
              </React.Fragment>
            ))}
          </div>
        )}

        {audienceLine && (
          <div className={styles.audienceLine}>{audienceLine}</div>
        )}

        {(author || date) && (
          <div className={styles.meta}>
            <AuthorByline
              name={author && author.name}
              avatarSrc={author && author.avatarSrc}
              initials={author && author.initials}
              date={date}
              dateLabel={dateLabel}
              locale={locale}
              tone="on-dark"
            />
          </div>
        )}

        {resolvedCtaLabel && (
          <span className={styles.cta}>
            <span>{resolvedCtaLabel}</span>
            <span aria-hidden="true">→</span>
          </span>
        )}
      </div>

      <div className={styles.visual} aria-hidden="true">
        {thumbProps.src
          ? <HexThumbnail size="xl" tone="cobalt" src={thumbProps.src} alt={thumbProps.alt} />
          : <HexThumbnail size="xl" tone={thumbProps.tone || 'cobalt'}>{thumbProps.icon}</HexThumbnail>}
        <span className={[styles.satellite, styles.s1].join(' ')} />
        <span className={[styles.satellite, styles.s2].join(' ')} />
        <span className={[
          styles.satellite,
          styles.s3,
          accent === 'orange' ? styles.s3Orange : styles.s3Cobalt,
        ].join(' ')} />
      </div>
    </Tag>
  );
}
