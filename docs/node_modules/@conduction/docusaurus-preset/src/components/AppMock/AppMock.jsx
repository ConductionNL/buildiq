/**
 * <AppMock />
 *
 * Token-built abstract representation of a Conduction app's canonical
 * view. Used wherever a marketing surface needs to *show* an app without
 * embedding a real screenshot. Reference for the level of abstraction:
 * honeycomb.io/technologies/* — recognisable as the product, never
 * literal.
 *
 * The mock paints with design tokens only (no images, no real text,
 * one orange accent max per variant). When you add a new app, follow
 * SKILL.md "App glyphs" and "Status palette" — same mint = stable,
 * KNVB orange = beta, mint hex = primary action confirmation.
 *
 * Usage:
 *
 *   <AppMock app="launchpad" />
 *   <AppMock app="openregister" caption />
 *   <AppMock app="decidesk" size="sm" />
 *   <AppMock app="procest" sidebar={<SidebarMock kind="procest-xwiki" />} />
 *
 * Props:
 *   - app:     keyof VARIANTS                           (required)
 *   - size:    'sm' | 'md' (default)                    — frame width
 *   - sidebar: ReactNode                                — renders inside
 *              the right-edge overlay of the frame. Pass a SidebarMock
 *              JSX to model a Nextcloud sidebar opened over this app
 *              (e.g. xWiki tab over a Procest case). Any other node
 *              renders verbatim
 *   - caption: boolean — adds a small app-name caption below the frame
 *   - className: string
 */

import React from 'react';
import styles from './AppMock.module.css';

import LaunchPadMock from './variants/LaunchPadMock.jsx';
import LaunchPadTilesMock from './variants/LaunchPadTilesMock.jsx';
import LaunchPadBiMock from './variants/LaunchPadBiMock.jsx';
import LaunchPadWidgetsMock from './variants/LaunchPadWidgetsMock.jsx';
import OpenBuildMock from './variants/OpenBuildMock.jsx';
import OpenCatalogiMock from './variants/OpenCatalogiMock.jsx';
import OpenConnectorMock from './variants/OpenConnectorMock.jsx';
import OpenRegisterMock from './variants/OpenRegisterMock.jsx';
import ProcestMock from './variants/ProcestMock.jsx';
import DeciDeskMock from './variants/DeciDeskMock.jsx';
import DocuDeskMock from './variants/DocuDeskMock.jsx';
import LarpingAppMock from './variants/LarpingAppMock.jsx';
import NLDesignMock from './variants/NLDesignMock.jsx';
import OpenWooMock from './variants/OpenWooMock.jsx';
import PipelinQMock from './variants/PipelinQMock.jsx';
import SoftwareCatalogMock from './variants/SoftwareCatalogMock.jsx';
import ZaakAfhandelAppMock from './variants/ZaakAfhandelAppMock.jsx';

const VARIANTS = {
  launchpad:           {Component: LaunchPadMock,           label: 'LaunchPad'},
  'launchpad-tiles':   {Component: LaunchPadTilesMock,      label: 'LaunchPad · Tiles & grids'},
  'launchpad-bi':      {Component: LaunchPadBiMock,         label: 'LaunchPad · BI on registers'},
  'launchpad-widgets': {Component: LaunchPadWidgetsMock,    label: 'LaunchPad · Widgets'},
  openbuild:        {Component: OpenBuildMock,        label: 'OpenBuild'},
  opencatalogi:     {Component: OpenCatalogiMock,     label: 'OpenCatalogi'},
  openconnector:    {Component: OpenConnectorMock,    label: 'OpenConnector'},
  openregister:     {Component: OpenRegisterMock,     label: 'OpenRegister'},
  procest:          {Component: ProcestMock,          label: 'Procest'},
  decidesk:         {Component: DeciDeskMock,         label: 'DeciDesk'},
  docudesk:         {Component: DocuDeskMock,         label: 'DocuDesk'},
  larpingapp:       {Component: LarpingAppMock,       label: 'LarpingApp'},
  nldesign:         {Component: NLDesignMock,         label: 'NLDesign'},
  openwoo:          {Component: OpenWooMock,          label: 'OpenWoo'},
  pipelinq:         {Component: PipelinQMock,         label: 'PipelinQ'},
  softwarecatalog:  {Component: SoftwareCatalogMock,  label: 'SoftwareCatalog'},
  zaakafhandelapp:  {Component: ZaakAfhandelAppMock,  label: 'ZaakAfhandelApp'},
};

export default function AppMock({app, size = 'md', sidebar = null, caption = false, className}) {
  const variant = VARIANTS[app];
  if (!variant) {
    return (
      <div className={styles.am}>
        <div className={[styles.frame, styles[`size-${size}`], className].filter(Boolean).join(' ')}>
          <div className={styles.empty}>Unknown app: {app}</div>
        </div>
      </div>
    );
  }
  const {Component, label} = variant;
  // The `sidebar` prop is forwarded to the variant Component, which
  // renders it as a flex sibling of `.col` inside the variant's
  // `.body`, taking the `.detail` slot. SidebarMock children get
  // `embedded: true` so they drop their standalone .smFrame chrome
  // and render as the bare `.detail.rich` panel that slots into
  // .body. Variants that don't accept a sidebar prop (e.g. LaunchPad)
  // ignore it; this keeps the change additive.
  const renderedSidebar = React.isValidElement(sidebar)
    ? React.cloneElement(sidebar, { embedded: true })
    : sidebar;
  return (
    <div className={styles.am}>
      <figure className={[styles.figure, className].filter(Boolean).join(' ')}>
        <div className={[styles.frame, styles[`size-${size}`]].filter(Boolean).join(' ')}>
          <Component sidebar={renderedSidebar} />
        </div>
        {caption && <figcaption className={styles.caption}>{label}</figcaption>}
      </figure>
    </div>
  );
}
