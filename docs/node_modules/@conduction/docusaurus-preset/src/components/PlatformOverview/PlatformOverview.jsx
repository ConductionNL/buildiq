/**
 * <PlatformOverview />
 *
 * Section wrapper around the bespoke <platform-diagram> web component
 * (workspace + corner-hex categories + flow lines). Renders the
 * "section-head" pattern (eyebrow + h2 + lede) above the diagram.
 *
 * The platform-diagram custom element is registered by
 * /lib/platform-diagram.js and styled by /lib/platform-diagram.css.
 * Both are loaded post-hydration by the <PlatformDiagram /> component;
 * sites no longer need to inject anything in <Head> manually.
 *
 * Mirrors the .platform section in preview/pages/landing.html.
 *
 * Usage in MDX:
 *
 *   import {PlatformOverview} from '@conduction/docusaurus-preset/components';
 *
 *   <PlatformOverview
 *     eyebrow="The platform"
 *     title={<>Five components.<br/>Twenty-four apps. One <span className="next-blue">Nextcloud</span> workspace.</>}
 *     lede={<>...</>}
 *   >
 *     <platform-diagram>
 *       <pd-workspace logo="/img/nextcloud-logo.svg" alt="Nextcloud" role="Workspace" />
 *       <pd-list position="top" family="nextcloud">
 *         <pd-item name="Files" desc="...">
 *           <svg>...</svg>
 *         </pd-item>
 *         ...
 *       </pd-list>
 *       ...
 *     </platform-diagram>
 *   </PlatformOverview>
 */

import React from 'react';
import styles from './PlatformOverview.module.css';

export default function PlatformOverview({eyebrow, title, lede, children}) {
  return (
    <section className={styles.section}>
      <div className={styles.inner}>
        {(eyebrow || title || lede) && (
          <div className={styles.head}>
            <div>
              {eyebrow && (
                <div className={styles.eyebrow}>
                  <span className={styles.eyebrowHex} aria-hidden="true"></span>
                  {eyebrow}
                </div>
              )}
              {title && <h2 className={styles.title}>{title}</h2>}
            </div>
            {lede && <p className={styles.lede}>{lede}</p>}
          </div>
        )}
        {children}
      </div>
    </section>
  );
}
