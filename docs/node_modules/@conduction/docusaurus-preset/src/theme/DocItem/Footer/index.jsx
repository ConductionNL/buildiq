/**
 * Brand DocItem/Footer swizzle.
 *
 * Wraps Docusaurus's default doc-item footer (tags row + edit-meta
 * row) and appends a Conduction <AppCrossLinks/> block so every
 * documentation page on docs.conduction.nl/<app> finishes with a
 * cross-link to the product page and the academy filter.
 *
 * Activation is per-site, opt-in: a docs site enables the block by
 * adding to its docusaurus.config.js:
 *
 *   themeConfig: {
 *     conduction: {
 *       appId: 'opencatalogi'
 *     },
 *     ...
 *   }
 *
 * Apps that don't set `themeConfig.conduction.appId` keep the default
 * Docusaurus footer behaviour, untouched.
 *
 * Per-page opt-out: a doc page can suppress the block via frontmatter
 *
 *   ---
 *   hide_app_cross_links: true
 *   ---
 *
 * which is useful for changelogs, license pages, or other docs that
 * shouldn't end with a cross-link.
 */

import React from 'react';
import clsx from 'clsx';
import {ThemeClassNames} from '@docusaurus/theme-common';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import {useDoc} from '@docusaurus/plugin-content-docs/client';
import TagsListInline from '@theme/TagsListInline';
import EditMetaRow from '@theme/EditMetaRow';
import {AppCrossLinks} from '@conduction/docusaurus-preset/components';
import {APPS_REGISTRY} from '@conduction/docusaurus-preset/data/apps-registry';

export default function DocItemFooter() {
  const {siteConfig} = useDocusaurusContext();
  const {metadata, frontMatter} = useDoc();
  const {editUrl, lastUpdatedAt, lastUpdatedBy, tags} = metadata;

  /* Per-page opt-out and per-page override (`apps:` in frontmatter
     wins over the site-wide appId). Lets a single doc page point at
     a different app without touching siteConfig. */
  const hide = !!frontMatter?.hide_app_cross_links;
  const fmApps = Array.isArray(frontMatter?.apps) ? frontMatter.apps : null;
  const siteAppId = siteConfig?.themeConfig?.conduction?.appId;
  const apps = (fmApps && fmApps.length > 0)
    ? fmApps
    : (siteAppId ? [siteAppId] : []);
  const knownApps = apps.filter((slug) => APPS_REGISTRY[slug]);

  const canDisplayTagsRow = tags.length > 0;
  const canDisplayEditMetaRow = !!(editUrl || lastUpdatedAt || lastUpdatedBy);
  const canDisplayCrossLinks = !hide && knownApps.length > 0;

  const canDisplayFooter = canDisplayTagsRow || canDisplayEditMetaRow || canDisplayCrossLinks;
  if (!canDisplayFooter) return null;

  return (
    <footer
      className={clsx(ThemeClassNames.docs.docFooter, 'docusaurus-mt-lg')}>
      {canDisplayTagsRow && (
        <div
          className={clsx(
            'row margin-top--sm',
            ThemeClassNames.docs.docFooterTagsRow,
          )}>
          <div className="col">
            <TagsListInline tags={tags} />
          </div>
        </div>
      )}
      {canDisplayEditMetaRow && (
        <EditMetaRow
          className={clsx(
            'margin-top--sm',
            ThemeClassNames.docs.docFooterEditMetaRow,
          )}
          editUrl={editUrl}
          lastUpdatedAt={lastUpdatedAt}
          lastUpdatedBy={lastUpdatedBy}
        />
      )}
      {canDisplayCrossLinks && (
        <div className="margin-top--lg">
          <AppCrossLinks
            variant="inline"
            apps={knownApps}
            surface="docs"
            heading={knownApps.length === 1
              ? 'About this app'
              : 'About these apps'}
          />
        </div>
      )}
    </footer>
  );
}
