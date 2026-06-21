/**
 * Brand DocItem/Content swizzle.
 *
 * Wraps Docusaurus's default DocItem/Content (which renders the
 * markdown body) and prepends a `TechArticle` JSON-LD block built
 * from the page's metadata + frontmatter. Every documentation page
 * across the Conduction fleet ships this schema automatically, which
 * is the single biggest remaining SEO-rich-result gap (the audit
 * found no Article/TechArticle anywhere on the fleet docs sites).
 *
 * What we emit:
 *   - headline:       page title
 *   - description:    frontmatter description (if any)
 *   - datePublished:  frontmatter date OR metadata.lastUpdatedAt
 *   - dateModified:   metadata.lastUpdatedAt (git mtime by default)
 *   - author:         frontmatter author (string or object) OR
 *                     "Conduction" as fallback
 *   - publisher:      reference to the shared Conduction Organization
 *   - mainEntityOfPage: canonical doc URL
 *
 * Why TechArticle (not plain Article): docs are technical content,
 * and TechArticle is the schema.org subtype Google + Bing reward for
 * developer documentation. Article would also work but TechArticle
 * is more specific.
 *
 * Sites that don't want the schema on a particular page set
 * `frontMatter.techArticle: false` in the doc's frontmatter.
 */

import React from 'react';
import Head from '@docusaurus/Head';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import {useDoc} from '@docusaurus/plugin-content-docs/client';
import DocItemContent from '@theme-init/DocItem/Content';

function buildTechArticleJsonLd(siteUrl, metadata, frontMatter) {
  const url = siteUrl
    ? `${siteUrl.replace(/\/$/, '')}${metadata.permalink}`
    : metadata.permalink;
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'TechArticle',
    '@id': `${url}#article`,
    mainEntityOfPage: url,
    headline: frontMatter.title || metadata.title,
    inLanguage: 'en',
    publisher: {'@id': 'https://www.conduction.nl/#org'},
  };
  if (frontMatter.description || metadata.description) {
    schema.description = frontMatter.description || metadata.description;
  }
  const datePublished = frontMatter.date || metadata.lastUpdatedAt;
  if (datePublished) {
    schema.datePublished = typeof datePublished === 'number'
      ? new Date(datePublished * 1000).toISOString()
      : new Date(datePublished).toISOString();
  }
  if (metadata.lastUpdatedAt) {
    schema.dateModified = new Date(metadata.lastUpdatedAt * 1000).toISOString();
  }
  /* Author: accept frontmatter string ("Ruben"), object ({name, url}),
     or list of authors. Default to Conduction as the team author. */
  const fmAuthor = frontMatter.author || frontMatter.authors;
  if (fmAuthor) {
    if (typeof fmAuthor === 'string') {
      schema.author = {'@type': 'Person', name: fmAuthor};
    } else if (Array.isArray(fmAuthor)) {
      schema.author = fmAuthor.map(a =>
        typeof a === 'string'
          ? {'@type': 'Person', name: a}
          : {'@type': 'Person', name: a.name, url: a.url});
    } else if (typeof fmAuthor === 'object') {
      schema.author = {'@type': 'Person', name: fmAuthor.name, url: fmAuthor.url};
    }
  } else {
    schema.author = {
      '@type': 'Organization',
      name: 'Conduction',
      '@id': 'https://www.conduction.nl/#org',
    };
  }
  return schema;
}

export default function DocItemContentWithSchema(props) {
  const {siteConfig} = useDocusaurusContext();
  const {metadata, frontMatter} = useDoc();
  const emitSchema = frontMatter.techArticle !== false;
  const schema = emitSchema
    ? buildTechArticleJsonLd(siteConfig.url, metadata, frontMatter)
    : null;
  return (
    <>
      {schema && (
        <Head>
          <script type="application/ld+json">
            {JSON.stringify(schema)}
          </script>
        </Head>
      )}
      <DocItemContent {...props} />
    </>
  );
}
