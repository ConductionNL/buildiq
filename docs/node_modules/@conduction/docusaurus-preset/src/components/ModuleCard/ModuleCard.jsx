/**
 * <ModuleCard />
 *
 * Composite card for an academy module (an ordered group of academy
 * entries that share a `module:` slug). Mirrors <ContentCard/> so the
 * two sit in the same academy grid without visually diverging. Left
 * column carries thumbnail + four-line meta stack (curator, latest
 * date · total min, MODULE · N PARTS, composite type summary). Right
 * column is title + lede + audience line.
 *
 * Usage:
 *
 *   <ModuleCard
 *     href="/academy/modules/deskdesk-tutorial"
 *     title="Build a Nextcloud app on the Conduction stack"
 *     lede="Four parts from blank Nextcloud to published app."
 *     parts={4}
 *     totalMinutes={95}
 *     latestDate="2026-05-17"
 *     curator={{ name: 'Ruben van der Linde', imageURL: '...' }}
 *     audience={['developer']}
 *     contentTypes={['tutorial']}
 *   />
 */

import React from 'react';
import {translate} from '@docusaurus/Translate';
import HexThumbnail from '../primitives/HexThumbnail';
import {AUDIENCE_SHORT_LABELS} from '../../data/audience';
import {CONTENT_TYPE_PLURAL_LABELS} from '../ContentTypeFilter/contentTypes';
import styles from './ModuleCard.module.css';

function StackedHexIcon() {
  /* Three pointy-top hexes layered to read as a stack/series. Uses
     currentColor so the surrounding tone variant drives the stroke
     colour (white on cobalt-deep, cobalt-700 on cobalt-50, etc.). */
  const stroke = {fill: 'none', stroke: 'currentColor', strokeWidth: 1.4, strokeLinejoin: 'round'};
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
      <path d="M6 4l5 2.5v5L6 14l-5-2.5v-5L6 4z" opacity="0.5" />
      <path d="M12 7l5 2.5v5L12 17l-5-2.5v-5L12 7z" opacity="0.75" />
      <path d="M18 10l5 2.5v5L18 20l-5-2.5v-5L18 10z" />
    </svg>
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

function initialsFrom(name) {
  if (!name) return '';
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0))
    .join('')
    .toUpperCase();
}

export default function ModuleCard({
  href,
  title,
  lede,
  parts,
  totalMinutes,
  latestDate,
  curator,
  locale,
  audience = [],
  contentTypes = [],
  className,
  ...rest
}) {
  const Tag = href ? 'a' : 'div';
  const composed = [styles.card, className].filter(Boolean).join(' ');

  /* No verb after the duration — the type line (MODULE · N PARTS) and
     the composite type summary below already signal whether this is
     something the visitor reads or watches. Keeps the date line on a
     single row in narrow columns. */
  const totalLabel = typeof totalMinutes === 'number'
    ? translate(
        {
          id: 'preset.moduleCard.minLabel',
          message: '{minutes} min',
          description: 'Total duration label under a module card (just minutes, no verb).',
        },
        {minutes: totalMinutes},
      )
    : null;
  const dateLabel  = formatDate(latestDate, locale);
  const dateReadLine = [dateLabel, totalLabel].filter(Boolean).join(' · ');

  const moduleEyebrow = translate({
    id: 'preset.moduleCard.moduleLabel',
    message: 'MODULE',
    description: 'All-caps eyebrow word in the module card type line.',
  });
  const partsLabel = typeof parts === 'number'
    ? (parts === 1
        ? translate({
            id: 'preset.moduleCard.singlePart',
            message: '1 PART',
            description: 'Module-parts count when the module has exactly one part.',
          })
        : translate(
            {
              id: 'preset.moduleCard.multipleParts',
              message: '{count} PARTS',
              description: 'Module-parts count when the module has 2+ parts.',
            },
            {count: parts},
          ))
    : null;
  const typeLine = [moduleEyebrow, partsLabel].filter(Boolean).join(' · ');

  /* Composite content-type summary on line 4. For a single-type
     module ("Tutorials") this is one word; mixed modules read
     "Tutorials + Guides". Drops entirely when the module has no
     content types resolved yet. */
  const typeSummary = contentTypes.length > 0
    ? contentTypes.map((ct) => CONTENT_TYPE_PLURAL_LABELS[ct] || ct).join(' + ')
    : null;

  const curatorName     = curator && curator.name;
  const curatorAvatar   = curator && curator.imageURL;
  const curatorInitials = (curator && initialsFrom(curator.name)) || '';

  return (
    <Tag href={href} className={composed} {...rest}>
      <div className={styles.leftCol}>
        <div className={styles.thumbPanel}>
          <HexThumbnail size="md" tone="cobalt">
            <StackedHexIcon />
          </HexThumbnail>
        </div>

        {(curatorName || dateReadLine || typeLine || typeSummary) && (
          <div className={styles.metaStack}>
            {(curatorAvatar || curatorInitials) && (
              <span className={styles.avatar} aria-hidden="true">
                {curatorAvatar
                  ? <img src={curatorAvatar} alt="" />
                  : <span className={styles.avatarInitials}>{curatorInitials}</span>}
              </span>
            )}
            <div className={styles.metaLines}>
              {curatorName  && <div className={styles.metaAuthor}>{curatorName}</div>}
              {dateReadLine && <div className={styles.metaSub}>{dateReadLine}</div>}
              {typeLine     && <div className={styles.metaType}>{typeLine}</div>}
              {typeSummary  && <div className={styles.metaModule}>{typeSummary}</div>}
            </div>
          </div>
        )}
      </div>

      <div className={styles.body}>
        {title && <h3 className={styles.title}>{title}</h3>}
        {lede && <p className={styles.lede}>{lede}</p>}

        {audience.length > 0 && (
          <div className={styles.audienceLine}>
            {translate(
              {
                id: 'preset.moduleCard.audienceFor',
                message: 'For: {list}',
                description: 'Audience line under a module card. {list} is a comma-separated audience list.',
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
      </div>
    </Tag>
  );
}
