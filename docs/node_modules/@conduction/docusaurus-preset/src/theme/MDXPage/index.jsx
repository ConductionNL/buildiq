/**
 * Brand MDXPage swizzle.
 *
 * Docusaurus's default MDXPage wraps every MDX page in
 * `<main class="container">.row > .col col--8`, which constrains content
 * to 67% width with a margin column reserved for the TOC. That works
 * for documentation pages but kills the design of marketing pages: the
 * cobalt-50 stats band, the canal-scene footer, and every cobalt CTA
 * panel render with visible white margins instead of bleeding to the
 * edges.
 *
 * Brand rule: pages with `hide_table_of_contents: true` (every page in
 * sites/www/src/pages/) render full-width. Section components own
 * their own `max-width: 1280px; margin: 0 auto` rules, so the marketing
 * surfaces look right. Pages that DO show a TOC keep the standard
 * Docusaurus container behaviour so the docs side stays consistent.
 *
 * Mirrors the source upstream MDXPage signature so the rest of the
 * Docusaurus renderer (PageMetadata, HtmlClassNameProvider, etc.) is
 * untouched.
 */

import React from 'react';
import clsx from 'clsx';
import {
  PageMetadata,
  HtmlClassNameProvider,
  ThemeClassNames,
} from '@docusaurus/theme-common';
import Layout from '@theme/Layout';
import MDXContent from '@theme/MDXContent';
import TOC from '@theme/TOC';
import ContentVisibility from '@theme/ContentVisibility';
import EditMetaRow from '@theme/EditMetaRow';

export default function MDXPage(props) {
  const {content: MDXPageContent} = props;
  const {metadata, assets} = MDXPageContent;
  const {
    title,
    editUrl,
    description,
    frontMatter,
    lastUpdatedBy,
    lastUpdatedAt,
  } = metadata;
  const {
    keywords,
    wrapperClassName,
    hide_table_of_contents: hideTableOfContents,
  } = frontMatter;
  const image = assets.image ?? frontMatter.image;
  const canDisplayEditMetaRow = !!(editUrl || lastUpdatedAt || lastUpdatedBy);

  /* Marketing pages that opt out of the TOC also opt out of the col-8
     container; the section components inside the page handle their own
     widths and bleed to the edges where the design calls for it. */
  const isMarketing = !!hideTableOfContents;

  /* Marketing pages get article-margin: 0 so the first section of the
     page (Hero, Section, etc.) sits flush against the navbar. The
     default Infima styling on <article> would otherwise leave a small
     top margin between the navbar's border and the page's first row,
     which the user-flagged screenshot showed on the connext landing.
     Docs pages keep the default margin so the heading rhythm reads
     correctly. */
  const articleStyle = isMarketing ? {margin: 0, padding: 0} : undefined;

  const inner = (
    <>
      <ContentVisibility metadata={metadata} />
      <article style={articleStyle}>
        <MDXContent>
          <MDXPageContent />
        </MDXContent>
      </article>
      {canDisplayEditMetaRow && (
        <EditMetaRow
          className={clsx(
            'margin-top--sm',
            ThemeClassNames.pages.pageFooterEditMetaRow,
          )}
          editUrl={editUrl}
          lastUpdatedAt={lastUpdatedAt}
          lastUpdatedBy={lastUpdatedBy}
        />
      )}
    </>
  );

  return (
    <HtmlClassNameProvider
      className={clsx(
        wrapperClassName ?? ThemeClassNames.wrapper.mdxPages,
        ThemeClassNames.page.mdxPage,
      )}>
      <Layout>
        <PageMetadata
          title={title}
          description={description}
          keywords={keywords}
          image={image}
        />
        {isMarketing ? (
          /* Marketing surface: no container, no col-8. The page's
             section components own their own widths. The
             `marketing-page` class lets brand.css zero-out any stray
             top margin/padding so the first section sits flush
             against the navbar (no gap between navbar and hex-rain
             hero). */
          <main className="marketing-page">{inner}</main>
        ) : (
          /* Docs / TOC surface: standard Docusaurus container. */
          <main className="container container--fluid margin-vert--lg">
            <div className="row">
              <div className={clsx('col', MDXPageContent.toc.length > 0 && 'col--8')}>
                {inner}
              </div>
              {MDXPageContent.toc.length > 0 && (
                <div className="col col--2">
                  <TOC
                    toc={MDXPageContent.toc}
                    minHeadingLevel={frontMatter.toc_min_heading_level}
                    maxHeadingLevel={frontMatter.toc_max_heading_level}
                  />
                </div>
              )}
            </div>
          </main>
        )}
      </Layout>
    </HtmlClassNameProvider>
  );
}
