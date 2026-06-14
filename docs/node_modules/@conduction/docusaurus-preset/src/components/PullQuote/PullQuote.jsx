/**
 * <PullQuote />
 *
 * Editorial emphasis for long-form prose. Lifts one or two load-
 * bearing sentences out of an opinion piece, product page, or
 * academy post into an orange-bordered cobalt panel. Optional
 * attribution slot turns the pull-quote into a citable position.
 *
 * Use sparingly. One to three per page; on the sentences that
 * carry the argument. The hero variant is for a single citable
 * thesis near the top of the post.
 *
 * Variants:
 *   - "default" — orange-knvb left border, cobalt-50 fill,
 *     italic cobalt body. Drop into MDX between paragraphs.
 *   - "hero"    — larger (28px), no italics, eyebrow hex bullet.
 *     Use once per page for the thesis statement.
 *   - "compact" — smaller (16px), tighter padding. For sidebars
 *     or dense layouts.
 *
 * Usage:
 *
 *   <PullQuote>
 *     You buy a suite. You use a workspace. You inhabit a platform.
 *   </PullQuote>
 *
 *   <PullQuote
 *     attribution="Ruben van der Linde"
 *     role="CTO Conduction"
 *   >
 *     Nextcloud-as-platform is true today in every structural sense
 *     that matters.
 *   </PullQuote>
 *
 *   <PullQuote
 *     variant="hero"
 *     eyebrow="The thesis"
 *     attribution="Ruben van der Linde"
 *     role="CTO Conduction"
 *   >
 *     Tomorrow's functionality will not be built by vendors. It will
 *     be built by users with AI. In that world, scale wins.
 *   </PullQuote>
 *
 * Mirrors preview/components/pull-quote.html.
 */

import React from 'react';
import styles from './PullQuote.module.css';

export default function PullQuote({
  children,
  attribution,
  role,
  variant = 'default',
  eyebrow,
  className,
}) {
  const composed = [
    styles.quote,
    variant === 'hero' && styles.hero,
    variant === 'compact' && styles.compact,
    className,
  ].filter(Boolean).join(' ');

  return (
    <blockquote className={composed}>
      {variant === 'hero' && eyebrow && (
        <span className={styles.eyebrow}>
          <span className={styles.eyebrowHex} aria-hidden="true" />
          {eyebrow}
        </span>
      )}
      {typeof children === 'string' ? <p>{children}</p> : children}
      {(attribution || role) && (
        <cite className={styles.cite}>
          {attribution && <span className={styles.citeName}>{attribution}</span>}
          {attribution && role && ', '}
          {role}
        </cite>
      )}
    </blockquote>
  );
}
