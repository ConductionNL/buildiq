/**
 * <SidebarMock />
 *
 * Token-painted abstract of the Nextcloud right-side detail panel.
 * Each Conduction app registers tabs into specific Nextcloud
 * sidebars (Procest adds xWiki + Timeline tabs to the case sidebar,
 * DocuDesk adds Signatures + PII Map to the document sidebar, and
 * so on). A SidebarMock variant represents one such sidebar with
 * one tab active.
 *
 * Markup matches the existing .detail.rich pattern that already
 * lived in the AppMock kit anatomy: a .sb-head row (icon + meta of
 * title + description + close), a .sb-tabs strip (one per registered
 * tab, active marker on the current one), and a .sb-body. All
 * styles live in AppMock.module.css under .am .detail.rich; the
 * body atoms (smKv / smPerson / smStage / smLog / smPii) live in
 * SidebarMock.module.css under .am .sb-body so they only apply
 * inside a sidebar.
 *
 * Two render modes:
 *
 *   STANDALONE (default): wraps the .detail.rich panel in
 *   <div class="am sm"> + .smFrame so it reads as a card on the
 *   kit page. Use in marketing copy that talks about a single
 *   sidebar surface in isolation.
 *
 *     <SidebarMock kind="procest-xwiki" />
 *
 *   EMBEDDED: drops the .smFrame wrapper, leaving just
 *   <div class="detail rich">…</div>. Slots into the .body flex
 *   layout of an AppMock variant as a sibling of .col, taking the
 *   detail-rail position. Set automatically by AppMock when the
 *   `sidebar` prop is a SidebarMock JSX element.
 *
 *     <AppMock app="procest" sidebar={<SidebarMock kind="procest-xwiki" />} />
 *
 * Status colour map matches WidgetMock and AppMock atoms:
 *   mint     = stable / done / signed
 *   orange   = active / pending / due soon
 *   red      = blocked / overdue / failed
 *   cobalt-200 = upcoming / idle / no action
 *
 * Props:
 *   - kind:     one of VARIANTS keys      (required)
 *   - size:     'sm' | 'md' (default) | 'lg'   — standalone frame size
 *                                                 (240x320 / 300x400 /
 *                                                 360x480). Ignored in
 *                                                 embedded mode (the
 *                                                 panel sizes itself
 *                                                 to AppMock's .body
 *                                                 layout)
 *   - embedded: boolean (default false)   — drop the standalone
 *                                           .smFrame chrome so the
 *                                           panel slots into
 *                                           AppMock's .body layout
 *   - className: string
 */

import React from 'react';
import styles from './SidebarMock.module.css';
import amStyles from '../AppMock/AppMock.module.css';
import IntegrationIcon from '../IntegrationIcon/IntegrationIcon.jsx';

import ProcestXWiki                  from './variants/ProcestXWiki.jsx';
import ProcestTimeline               from './variants/ProcestTimeline.jsx';
import DocuDeskSignatures            from './variants/DocuDeskSignatures.jsx';
import DocuDeskPiiMap                from './variants/DocuDeskPiiMap.jsx';
import OpenRegisterMetadata          from './variants/OpenRegisterMetadata.jsx';
import OpenCatalogiPublicationHistory from './variants/OpenCatalogiPublicationHistory.jsx';
import OpenConnectorRunDetail        from './variants/OpenConnectorRunDetail.jsx';
import DeciDeskDecision              from './variants/DeciDeskDecision.jsx';
import NextcloudActivity             from './variants/NextcloudActivity.jsx';

/**
 * Each VARIANTS entry carries:
 *   Component: the JSX that fills the .sb-body
 *   label:     human-readable name (used in caption / kit page)
 *   tabs:      ordered list of tabs with one .active. The id is
 *              decorative (rendered as a placeholder bar in .sb-tab .l)
 *              but the active flag drives the highlight.
 */
/**
 * Each tab carries an `icon` field: the kebab-case key into the
 * IntegrationIcon registry (see IntegrationIcon/registry.js). The
 * SidebarMock renders that icon in the tab's .ico slot, tinted via
 * currentColor so active tabs read full-cobalt and inactive tabs
 * read muted-cobalt. The icon makes the tab self-documenting:
 * Procest's xWiki tab carries the xWiki icon, DocuDesk's Signatures
 * tab carries an activity-style icon for "stuff happens here", etc.
 */
const VARIANTS = {
  'procest-xwiki': {
    Component: ProcestXWiki,
    label: 'Procest · Case sidebar, xWiki tab',
    tabs: [
      { id: 'activity',  active: false, icon: 'activity' },
      { id: 'xwiki',     active: true,  icon: 'xwiki' },
      { id: 'timeline',  active: false, icon: 'calendar' },
      { id: 'documents', active: false, icon: 'files' },
    ],
  },
  'procest-timeline': {
    Component: ProcestTimeline,
    label: 'Procest · Case sidebar, Timeline tab',
    tabs: [
      { id: 'activity',  active: false, icon: 'activity' },
      { id: 'xwiki',     active: false, icon: 'xwiki' },
      { id: 'timeline',  active: true,  icon: 'calendar' },
      { id: 'documents', active: false, icon: 'files' },
    ],
  },
  'docudesk-signatures': {
    Component: DocuDeskSignatures,
    label: 'DocuDesk · Document sidebar, Signatures tab',
    tabs: [
      { id: 'activity',   active: false, icon: 'activity' },
      { id: 'signatures', active: true,  icon: 'mail' },
      { id: 'pii',        active: false, icon: 'keycloak' },
      { id: 'versions',   active: false, icon: 'files' },
    ],
  },
  'docudesk-pii-map': {
    Component: DocuDeskPiiMap,
    label: 'DocuDesk · Document sidebar, PII map tab',
    tabs: [
      { id: 'activity',   active: false, icon: 'activity' },
      { id: 'signatures', active: false, icon: 'mail' },
      { id: 'pii',        active: true,  icon: 'keycloak' },
      { id: 'versions',   active: false, icon: 'files' },
    ],
  },
  'openregister-metadata': {
    Component: OpenRegisterMetadata,
    label: 'OpenRegister · Object sidebar, Metadata tab',
    tabs: [
      { id: 'activity', active: false, icon: 'activity' },
      { id: 'metadata', active: true,  icon: 'files' },
      { id: 'audit',    active: false, icon: 'keycloak' },
    ],
  },
  'opencatalogi-publication-history': {
    Component: OpenCatalogiPublicationHistory,
    label: 'OpenCatalogi · Publication sidebar, History tab',
    tabs: [
      { id: 'activity', active: false, icon: 'activity' },
      { id: 'history',  active: true,  icon: 'calendar' },
      { id: 'sources',  active: false, icon: 'rss' },
    ],
  },
  'openconnector-run-detail': {
    Component: OpenConnectorRunDetail,
    label: 'OpenConnector · Run sidebar, Logs tab',
    tabs: [
      { id: 'activity', active: false, icon: 'activity' },
      { id: 'logs',     active: true,  icon: 'n8n' },
      { id: 'mapping',  active: false, icon: 'windmill' },
    ],
  },
  'decidesk-decision': {
    Component: DeciDeskDecision,
    label: 'DeciDesk · Decision sidebar, Detail tab',
    tabs: [
      { id: 'activity', active: false, icon: 'activity' },
      { id: 'detail',   active: true,  icon: 'files' },
      { id: 'actions',  active: false, icon: 'decks' },
    ],
  },
  'nextcloud-activity': {
    Component: NextcloudActivity,
    label: 'Nextcloud · Stock activity feed',
    tabs: [
      { id: 'activity', active: true,  icon: 'activity' },
      { id: 'comments', active: false, icon: 'talk' },
    ],
  },
};

export default function SidebarMock({ kind, size = 'md', embedded = false, className }) {
  const variant = VARIANTS[kind];
  if (!variant) {
    return (
      <div className={[amStyles.am, styles.sm].filter(Boolean).join(' ')}>
        <div className={[styles.smFrame, styles[`sb-size-${size}`]].filter(Boolean).join(' ')}>
          <div style={{ padding: 12, color: 'var(--c-cobalt-400)', fontSize: 11 }}>Unknown sidebar: {kind}</div>
        </div>
      </div>
    );
  }
  const { Component, tabs } = variant;
  const panel = (
    <div className={[amStyles.detail, amStyles.rich].join(' ')}>
      <div className={amStyles['sb-head']}>
        <div className={amStyles.ico}></div>
        <div className={amStyles.meta}>
          <div className={amStyles.title}></div>
          <div className={amStyles.desc}></div>
        </div>
        <div className={amStyles.close}></div>
      </div>
      {tabs && tabs.length > 0 && (
        <div className={amStyles['sb-tabs']}>
          {tabs.map((t, i) => (
            <div key={t.id || i} className={[amStyles['sb-tab'], t.active && amStyles.active].filter(Boolean).join(' ')}>
              {t.icon ? (
                <IntegrationIcon name={t.icon} size="xs" />
              ) : (
                <div className={amStyles.ico}></div>
              )}
              <div className={amStyles.l}></div>
            </div>
          ))}
        </div>
      )}
      <div className={amStyles['sb-body']}>
        <Component />
      </div>
    </div>
  );

  if (embedded) return panel;

  return (
    <div className={[amStyles.am, styles.sm, className].filter(Boolean).join(' ')}>
      <div className={[styles.smFrame, styles[`sb-size-${size}`]].filter(Boolean).join(' ')}>
        {panel}
      </div>
    </div>
  );
}
