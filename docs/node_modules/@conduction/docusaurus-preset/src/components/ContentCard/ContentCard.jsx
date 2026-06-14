/**
 * <ContentCard /> + <ContentCardGrid />
 *
 * Card for an academy entry: blog post, guide, case study, webinar,
 * tutorial. Two-column card with a hex thumbnail panel on the left
 * and meta + title + tags on the right.
 *
 * Mirrors the "Keep learning…" cards in preview/components/academy.html
 * and the academy.conduction.nl landing.
 *
 * Visual structure:
 *
 *   ┌────────────────────────────────────────────┐
 *   │  ┌────────┐                                │
 *   │  │  hex   │   Author · Date                │
 *   │  │  thumb │   Title goes here.             │
 *   │  │        │   Optional summary line.       │
 *   │  └────────┘   ⬢ Type  ⬢ Tag  ⬢ Tag         │
 *   └────────────────────────────────────────────┘
 *
 * Card becomes an <a> when href is provided.
 *
 * Usage in MDX:
 *
 *   <ContentCardGrid columns={2}>
 *     <ContentCard
 *       href="/posts/install-opencatalogi"
 *       contentType="guide"
 *       title="Install OpenCatalogi in two minutes."
 *       summary="From app store to first register, no terminal required."
 *       author={{ name: "Ruben van der Linde" }}
 *       date="2026-05-05"
 *       tags={["OpenCatalogi", "Install"]}
 *       thumbnail={{ icon: <svg>...</svg>, tone: 'cobalt-deep' }}
 *     />
 *   </ContentCardGrid>
 */

import React from 'react';
import {translate} from '@docusaurus/Translate';
import HexThumbnail from '../primitives/HexThumbnail';
import Pill from '../primitives/Pill';
import {CONTENT_TYPE_LABELS} from '../ContentTypeFilter/contentTypes';
import {AUDIENCE_SHORT_LABELS} from '../../data/audience';
import styles from './ContentCard.module.css';

function readOrWatch(_contentType, minutes) {
  if (!minutes) return null;
  /* No verb appended — the type line below the date (BLOG / TUTORIAL /
     WEBINAR) already signals read vs. watch, and the extra word kept
     wrapping to a second line on narrow card columns. */
  return translate(
    {
      id: 'preset.contentCard.minLabel',
      message: '{minutes} min',
      description: 'Duration label under a content card (just minutes, no verb).',
    },
    {minutes},
  );
}

function formatDate(date, locale = 'nl') {
  if (!date) return null;
  try {
    const d = typeof date === 'string' ? new Date(date) : date;
    if (Number.isNaN(d.getTime())) return null;
    return d.toLocaleDateString(locale, {day: 'numeric', month: 'short', year: 'numeric'});
  } catch (_) {
    return null;
  }
}

function authorInitials(name) {
  if (!name) return '';
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join('')
    .toUpperCase();
}

export function ContentCardGrid({columns = 2, children, className}) {
  const composed = [
    styles.grid,
    styles['cols-' + columns],
    className,
  ].filter(Boolean).join(' ');
  return <div className={composed}>{children}</div>;
}

export default function ContentCard({
  href,
  contentType,
  contentTypeLabel,
  title,
  summary,
  author,
  date,
  dateLabel,
  locale,
  tags = [],
  thumbnail,
  durationMinutes,
  audience = [],
  module: moduleSlug,
  modulePosition,
  moduleTotalParts,
  moduleTitle,
  className,
  ...rest
}) {
  const Tag = href ? 'a' : 'div';
  const composed = [styles.card, className].filter(Boolean).join(' ');

  const thumbProps = thumbnail || {};
  const panelToneClass = thumbProps.panelTone
    ? styles['panel-' + thumbProps.panelTone]
    : styles['panel-cobalt-deep'];

  const typeLabel = contentTypeLabel
    || (contentType && CONTENT_TYPE_LABELS[contentType])
    || null;

  /* Four-line meta block under the thumbnail:
       1. Author name
       2. Date · Read time
       3. Type (TUTORIAL / BLOG / GUIDE)
       4. Module title  N/M    (only when part of a module)
     Each line is independently optional. */
  const readWatch     = readOrWatch(contentType, durationMinutes);
  const formattedDate = formatDate(date, locale);
  const dateReadLine  = [formattedDate, readWatch].filter(Boolean).join(' · ');
  const moduleLine    = moduleSlug
    ? (modulePosition && moduleTotalParts
        ? `${moduleTitle || moduleSlug} ${modulePosition}/${moduleTotalParts}`
        : (moduleTitle || moduleSlug))
    : null;

  const authorName    = author && author.name;
  const authorAvatar  = author && author.avatarSrc;
  const initials      = (author && author.initials) || authorInitials(authorName);

  return (
    <Tag href={href} className={composed} {...rest}>
      <div className={styles.leftCol}>
        <div className={[styles.thumbPanel, panelToneClass].join(' ')}>
          {thumbProps.src
            ? <HexThumbnail size={thumbProps.size || 'md'} tone={thumbProps.tone || 'cobalt'} src={thumbProps.src} alt={thumbProps.alt} />
            : <HexThumbnail size={thumbProps.size || 'md'} tone={thumbProps.tone || 'cobalt'}>{thumbProps.icon}</HexThumbnail>}
        </div>

        {(authorName || dateReadLine || typeLabel || moduleLine) && (
          <div className={styles.metaStack}>
            {(authorAvatar || initials) && (
              <span className={styles.avatar} aria-hidden="true">
                {authorAvatar
                  ? <img src={authorAvatar} alt="" />
                  : <span className={styles.avatarInitials}>{initials}</span>}
              </span>
            )}
            <div className={styles.metaLines}>
              {authorName    && <div className={styles.metaAuthor}>{authorName}</div>}
              {dateReadLine  && <div className={styles.metaSub}>{dateReadLine}</div>}
              {typeLabel     && <div className={styles.metaType}>{typeLabel}</div>}
              {moduleLine    && <div className={styles.metaModule}>{moduleLine}</div>}
            </div>
          </div>
        )}
      </div>

      <div className={styles.body}>
        {title && <h3 className={styles.title}>{title}</h3>}
        {summary && <p className={styles.summary}>{summary}</p>}

        {audience.length > 0 && (
          <div className={styles.audienceLine}>
            {translate(
              {
                id: 'preset.contentCard.audienceFor',
                message: 'For: {list}',
                description: 'Audience line under a content card. {list} is a comma-separated audience list.',
              },
              {
                list: audience
                  .map((a) => translate(
                    {
                      id: `preset.audience.short.${a}`,
                      message: AUDIENCE_SHORT_LABELS[a] || a,
                      description: `Short audience label used in the "For: ..." line. Slug: ${a}.`,
                    },
                  ))
                  .join(', '),
              },
            )}
          </div>
        )}

        {tags.length > 0 && (
          <div className={styles.tags}>
            {tags.map((tag, i) => (
              <Pill key={i} bullet bulletColor="var(--c-cobalt-300)">{tag}</Pill>
            ))}
          </div>
        )}
      </div>
    </Tag>
  );
}
