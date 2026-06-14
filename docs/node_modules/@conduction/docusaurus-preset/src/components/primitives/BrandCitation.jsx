/**
 * Brand-citation primitives: <NextBlue/>, <CgYellow/>, <KnvbOrange/>,
 * <CommonGroundPlus/>.
 *
 * Thin wrappers around the brand-citation classes from css/brand.css.
 * They render an inline <span> with the right colour for the brand
 * they cite, so consumers don't have to remember the global class name
 * (or which colour goes with which brand).
 *
 * The classes still ship as plain global CSS in brand.css so the
 * preview pages and any plain HTML can use them directly. These
 * components are the type-checked, autocompletable React surface.
 *
 * Usage in MDX:
 *
 *   import {NextBlue, CgYellow, KnvbOrange, CommonGroundPlus} from '@conduction/docusaurus-preset/components';
 *
 *   Twelve apps · one <NextBlue>Nextcloud</NextBlue> workspace.
 *   Built on <CgYellow>Common Ground</CgYellow> with one <KnvbOrange>orange</KnvbOrange> accent.
 *   The productized offering is <CommonGroundPlus/>.
 *
 * Per huisstijl:
 *   - <NextBlue>          cites Nextcloud (the platform); colour --c-nextcloud-blue
 *   - <CgYellow>          cites Common Ground (the ecosystem); colour --c-commonground-yellow
 *   - <KnvbOrange>        KNVB-orange highlight; colour --c-orange-knvb
 *   - <CommonGroundPlus>  the Common Ground+ brand mark, tri-colour: "Common"
 *                         in Conduction cobalt, "Ground" in CG-yellow, "+" in
 *                         Nextcloud blue. Use this for product-brand surfaces
 *                         (navbar wordmark, hero eyebrow, doors). For editorial
 *                         references to the official Dutch Common Ground
 *                         ecosystem, use <CgYellow>Common Ground</CgYellow>.
 *
 * One orange per screen: KnvbOrange should be reserved for highlights,
 * never primary fills, per the design-system rule.
 */

import React from 'react';

export function NextBlue({as: Tag = 'span', children, className, ...rest}) {
  return (
    <Tag className={['next-blue', className].filter(Boolean).join(' ')} {...rest}>
      {children}
    </Tag>
  );
}

export function CgYellow({as: Tag = 'span', children, className, ...rest}) {
  return (
    <Tag className={['cg-yellow', className].filter(Boolean).join(' ')} {...rest}>
      {children}
    </Tag>
  );
}

export function KnvbOrange({as: Tag = 'span', children, className, ...rest}) {
  return (
    <Tag className={['knvb-orange', className].filter(Boolean).join(' ')} {...rest}>
      {children}
    </Tag>
  );
}

export function CommonGroundPlus({as: Tag = 'span', className, ...rest}) {
  return (
    <Tag className={['cg-plus', className].filter(Boolean).join(' ')} {...rest}>
      <span className="cg-plus-common">Common</span>{' '}
      <span className="cg-yellow">Ground</span>
      <span className="cg-plus-plus">+</span>
    </Tag>
  );
}
