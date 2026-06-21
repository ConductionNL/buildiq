/**
 * <RelatedPosts />
 *
 * Section block used at the bottom of every academy detail page (and
 * sometimes on the landing) to surface a few more posts. A "Keep
 * learning…" heading on the left, a "View all" pill link on the right,
 * a content-card grid below.
 *
 * Mirrors the .related-posts section in preview/components/academy.html.
 *
 * Usage in MDX:
 *
 *   <RelatedPosts
 *     title="Keep learning…"
 *     viewAllHref="/"
 *     viewAllLabel="View all"
 *     columns={2}
 *   >
 *     <ContentCard ... />
 *     <ContentCard ... />
 *   </RelatedPosts>
 *
 * Or, instead of children, pass `items` as an array of ContentCard
 * props for a fully data-driven render.
 */

import React from 'react';
import ContentCard, {ContentCardGrid} from '../ContentCard/ContentCard';
import styles from './RelatedPosts.module.css';

export default function RelatedPosts({
  title = 'Keep learning…',
  viewAllHref,
  viewAllLabel = 'View all',
  columns = 2,
  items,
  children,
  className,
}) {
  return (
    <section className={[styles.section, className].filter(Boolean).join(' ')}>
      <div className={styles.head}>
        <h2 className={styles.title}>{title}</h2>
        {viewAllHref && (
          <a href={viewAllHref} className={styles.link}>
            {viewAllLabel}
          </a>
        )}
      </div>

      <ContentCardGrid columns={columns}>
        {items
          ? items.map((it, i) => <ContentCard key={it.href || i} {...it} />)
          : children}
      </ContentCardGrid>
    </section>
  );
}
