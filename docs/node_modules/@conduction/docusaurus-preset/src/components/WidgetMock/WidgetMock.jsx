/**
 * <WidgetMock />
 *
 * Standalone abstract of a Nextcloud dashboard widget that a
 * Conduction app registers (DocuDesk anonymise, Procest werkvoorraad,
 * OpenRegister activity, etc.) plus a few of the stock Nextcloud
 * widgets we frame around (Mail, Calendar, Files, Decks, RSS).
 *
 * Companion to <AppMock />: AppMock paints the whole product surface
 * (topbar + nav + col + optional sidebar); WidgetMock paints just one
 * tile, sized for inline use in marketing copy or docs that talk
 * about a single integration point.
 *
 * Visual reference: every Conduction widget renders against a dashboard
 * background (cobalt-900 in LaunchPad, white in stock Nextcloud). The
 * WidgetMock chrome is cobalt to match LaunchPad since most Conduction
 * widgets are built for that surface; the same widget atoms render
 * fine on white when reused there.
 *
 * Usage:
 *
 *   <WidgetMock kind="procest-werkvoorraad" />
 *   <WidgetMock kind="docudesk-anonymise" caption />
 *   <WidgetMock kind="nextcloud-calendar" size="sm" />
 *
 * Props:
 *   - kind:    one of VARIANTS keys                   (required)
 *   - size:    'sm' | 'md' (default) | 'lg'           — frame width
 *   - caption: boolean                                — adds a small
 *                                                       label below
 *                                                       the frame
 *   - className: string
 */

import React from 'react';
import styles from './WidgetMock.module.css';
import amStyles from '../AppMock/AppMock.module.css';

import DocuDeskAnonymise       from './variants/DocuDeskAnonymise.jsx';
import DocuDeskPendingSign     from './variants/DocuDeskPendingSign.jsx';
import ProcestWerkvoorraad     from './variants/ProcestWerkvoorraad.jsx';
import ProcestDueToday         from './variants/ProcestDueToday.jsx';
import OpenRegisterActivity    from './variants/OpenRegisterActivity.jsx';
import OpenCatalogiPublications from './variants/OpenCatalogiPublications.jsx';
import OpenConnectorRuns       from './variants/OpenConnectorRuns.jsx';
import DeciDeskActions         from './variants/DeciDeskActions.jsx';
import PipelinQDeals           from './variants/PipelinQDeals.jsx';
import NextcloudMail           from './variants/NextcloudMail.jsx';
import NextcloudCalendar       from './variants/NextcloudCalendar.jsx';
import NextcloudFiles          from './variants/NextcloudFiles.jsx';
import NextcloudDecks          from './variants/NextcloudDecks.jsx';
import NextcloudRss            from './variants/NextcloudRss.jsx';

const VARIANTS = {
  'docudesk-anonymise':       { Component: DocuDeskAnonymise,       label: 'DocuDesk · Anonymise drop' },
  'docudesk-pending-sign':    { Component: DocuDeskPendingSign,     label: 'DocuDesk · Pending signatures' },
  'procest-werkvoorraad':     { Component: ProcestWerkvoorraad,     label: 'Procest · Werkvoorraad' },
  'procest-due-today':        { Component: ProcestDueToday,         label: 'Procest · Due today' },
  'openregister-activity':    { Component: OpenRegisterActivity,    label: 'OpenRegister · Activity' },
  'opencatalogi-publications':{ Component: OpenCatalogiPublications,label: 'OpenCatalogi · Recent publications' },
  'openconnector-runs':       { Component: OpenConnectorRuns,       label: 'OpenConnector · Recent runs' },
  'decidesk-actions':         { Component: DeciDeskActions,         label: 'DeciDesk · Action items' },
  'pipelinq-deals':           { Component: PipelinQDeals,           label: 'PipelinQ · Deals closing' },
  'nextcloud-mail':           { Component: NextcloudMail,           label: 'Nextcloud · Important mail' },
  'nextcloud-calendar':       { Component: NextcloudCalendar,       label: 'Nextcloud · Calendar' },
  'nextcloud-files':          { Component: NextcloudFiles,          label: 'Nextcloud · Recent files' },
  'nextcloud-decks':          { Component: NextcloudDecks,          label: 'Nextcloud · Decks' },
  'nextcloud-rss':            { Component: NextcloudRss,            label: 'Nextcloud · RSS feed' },
};

export default function WidgetMock({ kind, size = 'md', caption = false, className }) {
  const variant = VARIANTS[kind];
  const wrap = [amStyles.am, styles.wm].filter(Boolean).join(' ');
  if (!variant) {
    return (
      <div className={wrap}>
        <div className={[styles.wmFrame, styles[`wm-size-${size}`]].filter(Boolean).join(' ')}>
          <div style={{ color: 'var(--c-cobalt-200)', fontSize: 11 }}>Unknown widget: {kind}</div>
        </div>
      </div>
    );
  }
  const { Component, label } = variant;
  return (
    <div className={wrap}>
      <figure className={[styles.wmFigure, className].filter(Boolean).join(' ')}>
        <div className={[styles.wmFrame, styles[`wm-size-${size}`]].filter(Boolean).join(' ')}>
          <Component />
        </div>
        {caption && <figcaption className={styles.wmCaption}>{label}</figcaption>}
      </figure>
    </div>
  );
}
