/**
 * <ModulePage />
 *
 * Wrapper for per-module MDX index pages at /academy/modules/{slug}.
 * Each module gets its own MDX file at
 * `src/pages/academy/modules/{slug}.mdx` that imports this component,
 * passes the module slug, and provides the hero + body copy as
 * children. ModulePage handles the rest:
 *
 *   - Reads the module's parts from the `academy-modules` plugin's
 *     globalData (slug, title, lede, ordered parts, audience, apps,
 *     totals).
 *   - Renders the hero (title + lede + meta line: parts count, total
 *     duration, audience tier).
 *   - Renders the editor-supplied MDX children below the hero (room
 *     for "What you build", "Who this is for", "Prerequisites", etc.).
 *   - Renders the ordered parts list at the bottom with one
 *     <ContentCard/> per part.
 *
 * The site must register the `academy-modules` plugin in
 * docusaurus.config.js for usePluginData('academy-modules') to
 * return data. When the plugin is missing, ModulePage falls back to
 * a "module not found" message rather than crashing.
 *
 * Usage (MDX):
 *
 *   import {ModulePage} from '@conduction/docusaurus-preset/components';
 *
 *   <ModulePage module="deskdesk-tutorial">
 *     Hier hoort de marketing-lede van de module, korte zinnen,
 *     niet langer dan een alinea of vier.
 *   </ModulePage>
 */

import React from 'react';
import {usePluginData} from '@docusaurus/useGlobalData';
import ContentCard, {ContentCardGrid} from '../ContentCard/ContentCard.jsx';
import {AUDIENCE_LABELS} from '../../data/audience';
import {CONTENT_TYPE_LABELS} from '../ContentTypeFilter/contentTypes';
import styles from './ModulePage.module.css';

function defaultIconFor(contentType) {
  const stroke = {strokeWidth: 1.6, fill: 'none', stroke: 'currentColor'};
  switch (contentType) {
    case 'tutorial':
      return (
        <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
          <path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h10" />
          <path d="M19 14v6" /><path d="M16 17l3 3 3-3" />
        </svg>
      );
    case 'guide':
      return (
        <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
          <path d="M4 4h12a4 4 0 0 1 4 4v12H8a4 4 0 0 1-4-4V4z" />
          <path d="M4 16a4 4 0 0 1 4-4h12" />
        </svg>
      );
    case 'webinar':
      return (
        <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
          <circle cx="12" cy="12" r="9" />
          <path d="M10 9l5 3-5 3z" fill="currentColor" stroke="none" />
        </svg>
      );
    case 'case-study':
      return (
        <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
          <rect x="3" y="7" width="18" height="13" rx="1" />
          <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
          <path d="M3 13h18" />
        </svg>
      );
    case 'blog':
    default:
      return (
        <svg viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
          <path d="M3 11l9-8 9 8" />
          <path d="M5 10v10h14V10" />
          <path d="M9 20v-6h6v6" />
        </svg>
      );
  }
}

function partToCardProps(part) {
  return {
    href: part.permalink,
    contentType: part.contentType,
    title: part.title,
    summary: part.summary,
    date: part.date,
    tags: [`Part ${part.modulePosition}`],
    thumbnail: {icon: defaultIconFor(part.contentType), panelTone: 'cobalt-dark'},
  };
}

export default function ModulePage({module: moduleSlug, children}) {
  const data = usePluginData('academy-modules') || {};
  const mod  = data.modules && data.modules[moduleSlug];

  if (!mod) {
    return (
      <main className="marketing-page">
        <div className={styles.notFound}>
          <h1>Module not found</h1>
          <p>No academy module with slug <code>{moduleSlug}</code> is registered. Check the module slug in this page's frontmatter.</p>
        </div>
      </main>
    );
  }

  const metaBits = [];
  metaBits.push(mod.parts.length === 1 ? '1 part' : `${mod.parts.length} parts`);
  if (mod.totalMinutes) metaBits.push(`${mod.totalMinutes} min total`);
  if (mod.contentTypes && mod.contentTypes.length === 1) {
    metaBits.push(CONTENT_TYPE_LABELS[mod.contentTypes[0]] || mod.contentTypes[0]);
  }

  return (
    <main className="marketing-page">
      <header className={styles.hero}>
        <div className={styles.eyebrow}>Module · academy</div>
        <h1 className={styles.title}>{mod.title}</h1>
        {mod.lede && <p className={styles.lede}>{mod.lede}</p>}

        <div className={styles.metaRow}>
          <span className={styles.metaBit}>{metaBits.join(' · ')}</span>
          {mod.audience && mod.audience.length > 0 && (
            <span className={styles.metaBit}>
              {mod.audience.map((a) => AUDIENCE_LABELS[a] || a).join(' · ')}
            </span>
          )}
        </div>
      </header>

      {children && <section className={styles.intro}>{children}</section>}

      <section className={styles.parts}>
        <h2 className={styles.partsTitle}>In this module</h2>
        <ContentCardGrid columns={1}>
          {mod.parts.map((part) => (
            <ContentCard key={part.slug} {...partToCardProps(part)} />
          ))}
        </ContentCardGrid>
      </section>
    </main>
  );
}
