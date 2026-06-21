/**
 * <MockScene />
 *
 * Free-form positioning surface for assembling overlap shots out of
 * WidgetMocks and SidebarMocks. The scene itself is a transparent
 * stage; the caller decides the canvas size and where each item
 * lands. Multiple sidebars and widgets can share a scene, can be
 * different sizes, and can overlap as needed.
 *
 * Sibling to AppMock + WidgetMock + SidebarMock. AppMock paints a
 * single product surface, WidgetMock one tile, SidebarMock one detail
 * panel; MockScene paints the overlap. No background, no rotation,
 * no centred-anchor pattern; each item carries its own shadow and
 * lifts off whatever card the scene sits inside.
 *
 * Usage:
 *
 *   <MockScene
 *     width={960}
 *     height={420}
 *     items={[
 *       { type: 'widget',  kind: 'nextcloud-mail',         x: 0,   y: 0,   size: 'sm' },
 *       { type: 'sidebar', kind: 'openregister-metadata',  x: 220, y: 30,  size: 'md' },
 *       { type: 'widget',  kind: 'openregister-activity',  x: 540, y: 80,  size: 'md' },
 *       { type: 'sidebar', kind: 'procest-xwiki',          x: 700, y: 0,   size: 'sm' },
 *     ]}
 *   />
 *
 * Each item:
 *   - type:  'widget' | 'sidebar'
 *   - kind:  variant slug (see WidgetMock or SidebarMock VARIANTS)
 *   - x, y:  pixel offset from the scene's top-left corner
 *   - size:  'sm' | 'md' | 'lg' (defaults to 'md' for both types)
 *   - z:     z-index override (defaults to array order, later items
 *             render on top)
 *
 * Caller can also pass a `style` field on an item for inline overrides
 * beyond what `size` provides (custom widths, transforms, etc.). The
 * inline style is merged onto the item wrapper, so anything
 * position-related on the wrapper (left, top, z-index) takes
 * precedence over the caller's style.
 *
 * Props:
 *   - items:     Array of item descriptors (see above)
 *   - width:     scene width in px (default 960)
 *   - height:    scene height in px (default 420)
 *   - className: string
 */

import React from 'react';
import styles from './MockScene.module.css';
import amStyles from '../AppMock/AppMock.module.css';
import WidgetMock from '../WidgetMock/WidgetMock.jsx';
import SidebarMock from '../SidebarMock/SidebarMock.jsx';

function renderItem(item) {
  const size = item.size || 'md';
  if (item.type === 'widget') {
    return <WidgetMock kind={item.kind} size={size} />;
  }
  if (item.type === 'sidebar') {
    return <SidebarMock kind={item.kind} size={size} />;
  }
  return item.node || null;
}

export default function MockScene({ items = [], width = 960, height = 420, className }) {
  return (
    <div className={[amStyles.am, styles.scene, className].filter(Boolean).join(' ')}>
      <div className={styles.sceneFrame} style={{ width, height }}>
        {items.map((item, i) => {
          const wrapperStyle = {
            ...(item.style || {}),
            left: item.x || 0,
            top: item.y || 0,
            zIndex: item.z != null ? item.z : i,
          };
          return (
            <div key={i} className={styles.sceneItem} style={wrapperStyle}>
              {renderItem(item)}
            </div>
          );
        })}
      </div>
    </div>
  );
}
