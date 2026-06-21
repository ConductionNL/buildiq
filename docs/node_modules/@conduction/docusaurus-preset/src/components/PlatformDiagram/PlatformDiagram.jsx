/**
 * <PlatformDiagram />
 *
 * React wrapper around the bespoke <platform-diagram> web component
 * that ships in the kit (preview/components/_lib/platform-diagram.{js,css}).
 *
 * The web component does the heavy lifting: it reads its <pd-workspace>,
 * <pd-list>, <pd-item>, <pd-flow> children, lays them out on the
 * 1fr/360px/1fr grid, and draws the SVG flow lines between boxes.
 *
 * This wrapper accepts a typed prop graph instead of forcing callers
 * to inline 13+ <pd-item> SVG elements in MDX. It generates the same
 * DOM the web component expects.
 *
 * Lazy-loads the runtime CSS+JS from /lib/platform-diagram.* on the
 * client; sites that already include those assets via <Head> won't
 * double-load (the script's IIFE is no-op on re-import, the link tag
 * is dedupe'd by href).
 *
 * Usage in MDX:
 *
 *   <PlatformDiagram
 *     workspace={{logo: '/img/nextcloud-logo.svg', alt: 'Nextcloud', role: 'Workspace'}}
 *     lists={[
 *       {position: 'top', family: 'nextcloud', items: [
 *         {name: 'Files', meta: 'Files', desc: '...', icon: <svg>...</svg>},
 *         ...
 *       ]},
 *       {position: 'top-left', family: 'core', label: 'Technical\nCore', items: [...]},
 *       {position: 'top-right', family: 'solutions', label: 'Solutions', items: [...]},
 *       {position: 'bottom-left', family: 'workspace', label: 'Workspace\nApps', items: [...]},
 *       {position: 'bottom-right', family: 'integrate', label: 'Integrated\nApps',
 *        columns: 2, items: [...]},
 *       {position: 'bottom-center', family: 'builder', label: 'App\nBuilder',
 *        badge: 'COMING SOON', items: [...]},
 *     ]}
 *     flows={[
 *       {from: 'top-left:bottom', to: 'bottom-left:top', shape: 'straight'},
 *       {from: 'bottom-center:left', to: 'bottom-left:bottom', shape: 'l-h'},
 *       {from: 'bottom-right:bottom', to: 'bottom-left:bottom', shape: 'c-bracket-bottom', clearance: 280},
 *       {from: 'top-left:top', to: 'top-right:top', shape: 'c-bracket-top', clearance: 240},
 *     ]}
 *   />
 */

import React from 'react';
import {useLazyScript} from '../../utils/lazyScript';
import {useLazyStylesheet} from '../../utils/lazyStylesheet';

const ASSET_BASE = '/lib';

export default function PlatformDiagram({workspace, lists = [], flows = []}) {
  /* platform-diagram.{js,css} are loaded post-hydration. The runtime
     registers a custom-element definition that upgrades any
     <platform-diagram> element on the page; running it after React
     hydration prevents the custom-element constructor from mutating the
     DOM mid-hydration and producing #418 / #423 warnings. The matching
     stylesheet is held off the render path so its 19 KB doesn't block
     LCP on pages that render this component. */
  useLazyScript(ASSET_BASE + '/platform-diagram.js', 'platform-diagram');
  useLazyStylesheet(ASSET_BASE + '/platform-diagram.css', 'platform-diagram');

  return (
    <>
      <platform-diagram>
        {workspace && (
          <pd-workspace
            logo={workspace.logo}
            alt={workspace.alt}
            role={workspace.role || 'Workspace'}
          />
        )}

        {lists.map((list, i) => (
          <pd-list
            key={i}
            position={list.position}
            family={list.family}
            label={list.label}
            badge={list.badge}
            columns={list.columns}
          >
            {(list.items || []).map((item, j) => (
              <pd-item
                key={j}
                name={item.name}
                meta={item.meta}
                desc={item.desc}
                href={item.href}
                brand-color={item.brandColor}
              >
                {item.icon}
              </pd-item>
            ))}
          </pd-list>
        ))}

        {flows.map((flow, i) => (
          <pd-flow
            key={i}
            from={flow.from}
            to={flow.to}
            shape={flow.shape}
            clearance={flow.clearance}
          />
        ))}
      </platform-diagram>
    </>
  );
}
