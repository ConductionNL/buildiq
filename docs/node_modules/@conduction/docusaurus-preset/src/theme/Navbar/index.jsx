/**
 * Brand Navbar swizzle.
 *
 * Replaces Docusaurus's default Infima navbar with the Conduction
 * top-navbar pattern: brand wordmark + nav links + locale + chrome.
 * Navigation items come from themeConfig.navbar.items (configured by
 * the consuming site); the chrome (typography, spacing, brand citation)
 * stays locked in this component.
 *
 * Brand wordmark switches based on pathname so a single Conduction hub
 * can host vanity sub-brand entry points:
 *
 *   /connext, /nl/connext           → "Con<Next>" with next-blue accent
 *   /commonground, /nl/commonground → "Common <Ground>" with cg-yellow accent
 *   anything else                   → navbar.title (e.g. "Conduction")
 *
 * The home link follows the brand: clicking the wordmark while on a
 * sub-brand section keeps you in that section. Outside a sub-brand
 * section it goes to the site root.
 *
 * Item types the brand navbar recognises (sites declare them in
 * docusaurus.config.js → themeConfig.navbar.items):
 *
 *   { type: 'doc', label, to }                       internal doc link
 *   { type: 'link', label, to | href }               internal/external link
 *   { type: 'localeDropdown' }                       Docusaurus locale switcher
 *   { type: 'custom-github', href }                  icon-only GitHub mark
 *   { type: 'custom-apiDocs', label?, to }           icon + "API Documentation"
 *   { type: 'custom-versionPill', prefix? }          "Stable · v{x.y.z}" pill
 *                                                    reads customFields.appVersion;
 *                                                    hidden when no version
 *
 * The `custom-` prefix is required so Docusaurus's themeConfig schema
 * validator passes (`@docusaurus/theme-classic` rejects unknown bare
 * type names). The swizzle below accepts both the prefixed and the
 * bare names so 2.7.0-beta.1 sites that wired the bare names keep
 * working after the upgrade.
 *
 * The pill prefix defaults to "Stable" but can be overridden per site
 * (e.g. prefix="Beta" while on a pre-1.0 release line).
 *
 * Mirrors preview/components/top-navbar.html in the design-system kit.
 */

import React from 'react';
import Link from '@docusaurus/Link';
import {useLocation} from '@docusaurus/router';
import useBaseUrl from '@docusaurus/useBaseUrl';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import {useThemeConfig} from '@docusaurus/theme-common';
import {translate} from '@docusaurus/Translate';
import LocaleDropdownNavbarItem from '@theme/NavbarItem/LocaleDropdownNavbarItem';
import {brandFor, productWordmark, deriveStability} from '../brand.jsx';
import {ICONS} from '../../components/primitives/icons';
import styles from './styles.module.css';

/**
 * Brand-specific navbar item types live under the `custom-` prefix
 * because Docusaurus's themeConfig validator (Joi schema in
 * @docusaurus/theme-classic) rejects unknown top-level types. The
 * `custom-` namespace is the documented escape hatch: items prefixed
 * with `custom-` bypass schema validation and are passed through to
 * the theme as-is. The brand Navbar then dispatches on them below.
 *
 * Sites may also use the bare names (`github`, `apiDocs`,
 * `versionPill`) — they render identically here but Docusaurus will
 * reject the config at load time. Accept both forms so the migration
 * from 2.7.0-beta.1 to .beta.2 doesn't break sites that already
 * configured the bare names.
 */
function typeIs(item, kind) {
  return item.type === kind || item.type === 'custom-' + kind;
}

/**
 * Render a single navbar item. The brand navbar supports a small
 * subset of Docusaurus item types plus the three brand-specific types
 * (github, apiDocs, versionPill); everything else falls back to a
 * plain link for forward-compatibility.
 */
function NavItem({item, location, appVersion}) {
  if (item.type === 'localeDropdown') {
    return (
      <div className={styles.localeWrapper}>
        <LocaleDropdownNavbarItem mobile={false} {...item} />
      </div>
    );
  }

  /* GitHub: icon-only link with an accessible label. The aria-label
     gives screen-readers + browser tooltips a name without rendering
     a visible text label in the navbar. */
  if (typeIs(item, 'github')) {
    return (
      <a
        href={item.href || 'https://codeberg.org/Conduction'}
        className={styles.iconLink}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={item['aria-label'] || translate({id: 'preset.navbar.github.ariaLabel', message: 'GitHub repository', description: 'Default accessible label for the icon-only GitHub link in the navbar'})}
        title="GitHub"
      >
        <span className={styles.iconGlyph} aria-hidden="true">{ICONS.github}</span>
      </a>
    );
  }

  /* API Documentation: icon + label link. Target defaults to /api
     (the Redocusaurus mount point used by every Conduction docs site).
     Sites can override via `to` or `href`. */
  if (typeIs(item, 'apiDocs')) {
    const label = item.label || translate({id: 'preset.navbar.apiDocs.label', message: 'API Documentation', description: 'Default label for the API Documentation navbar link when the consuming site does not set one'});
    const to = item.to || '/api';
    const href = item.href;
    const isActive = !href && (location?.pathname === to ||
                               location?.pathname?.startsWith(to + '/'));
    const className = `${styles.link} ${styles.iconLabelLink} ${isActive ? styles.linkActive : ''}`;
    const content = (
      <>
        <span className={styles.iconGlyph} aria-hidden="true">{ICONS.apiDocs}</span>
        {label}
      </>
    );
    if (href) {
      return <a href={href} className={className} target={href.startsWith('http') ? '_blank' : undefined} rel={href.startsWith('http') ? 'noopener noreferrer' : undefined}>{content}</a>;
    }
    return <Link to={to} className={className}>{content}</Link>;
  }

  /* Version pill: code-typeface "{Stability} · v{version}" chip.
     Source is customFields.appVersion (set by createConfig() from
     appinfo/info.xml or package.json). The maturity prefix
     (Stable/Beta/RC/Alpha) is auto-derived from the SemVer string —
     `0.1.0` → Beta, `1.0.0-rc.2` → RC, `1.2.3` → Stable. Sites can
     still pass an explicit `prefix` to override. Hidden when no
     version is available so sites without an app version (Hydra,
     design-system itself) get a clean navbar instead of an empty
     pill. */
  if (typeIs(item, 'versionPill')) {
    if (!appVersion) return null;
    const prefix = item.prefix || deriveStability(appVersion);
    return (
      <span className={styles.versionPill} title={`${prefix} · v${appVersion}`}>
        {prefix} · v{appVersion}
      </span>
    );
  }

  /* External link, no React-router prefetch */
  if (item.href && !item.to) {
    const isCta = item.cta === true;
    return (
      <a
        href={item.href}
        className={isCta ? styles.cta : styles.ghost}
        target={item.href.startsWith('http') ? '_blank' : undefined}
        rel={item.href.startsWith('http') ? 'noopener noreferrer' : undefined}
      >
        {item.label}{isCta && ' →'}
      </a>
    );
  }

  /* Internal route */
  if (item.to) {
    const isCta = item.cta === true;
    const isActive = location?.pathname === item.to ||
                     location?.pathname?.startsWith(item.to + '/');
    return (
      <Link
        to={item.to}
        className={
          isCta
            ? styles.cta
            : `${styles.link} ${isActive ? styles.linkActive : ''}`
        }
      >
        {item.label}{isCta && ' →'}
      </Link>
    );
  }

  return null;
}

export default function Navbar() {
  const {navbar} = useThemeConfig();
  const {siteConfig} = useDocusaurusContext();
  const appVersion = siteConfig?.customFields?.appVersion;
  const location = useLocation();
  const items = navbar.items || [];
  const brand = brandFor(location.pathname, navbar.title);

  /* Wordmark resolution order:
     1. ConNext / Common Ground sub-brand → custom JSX (Con<Next>, …)
     2. Conduction product app (Open*, Docu*, My*, …) → prefix-light
        treatment: cobalt-400 prefix + blue-cobalt rest, matching the
        preview/apps.html convention.
     3. Plain text title (single-word wordmark or unrecognised prefix). */
  let wordmark;
  if (brand) {
    wordmark = brand.wordmark;
  } else {
    const split = productWordmark(navbar.title, navbar.brandPrefix);
    wordmark = split ? (
      <>
        <span className={styles.wordmarkPrefix}>{split.prefix}</span>{split.rest}
      </>
    ) : navbar.title;
  }

  /* Path-match: keep the visitor in the sub-brand section on logo click.
     Title-match (the site's primary brand IS a sub-brand): logo goes to
     site root since the section IS the site. */
  const homeHref = brand?.source === 'path' ? brand.home : '/';

  /* App icon. The brand rule is that every product navbar shows the
     app's hex-glyph next to the wordmark. The icon is sourced from
     navbar.logo (createConfig defaults it to img/logo.svg, which every
     Conduction docs site ships under static/img/). Sites can opt the
     icon out by passing `logo: null` in their navbar config.

     `useBaseUrl` resolves the src against the site's configured
     baseUrl — without it, a relative `img/app-logo.svg` resolves
     against the current page's path, so the icon 404s on every
     sub-route (e.g. /docs/intro/img/app-logo.svg). */
  const logoSrcRaw = navbar.logo?.src;
  const logoSrc = useBaseUrl(logoSrcRaw || '');
  const logoAlt = navbar.logo?.alt || translate(
    {id: 'preset.navbar.logoAlt', message: '{title} avatar', description: 'Default alt text for the navbar logo. {title} is the site title.'},
    {title: navbar.title},
  );

  /* Split into "left links" (regular nav) and "right CTAs" (locale,
     external links, install button, GitHub icon, version pill).
     Items default to the left unless they explicitly carry
     position="right" — but the three brand-specific item types
     (github, apiDocs, versionPill) live on the right by convention,
     mirroring the docs-shell mock. */
  const RIGHT_TYPES = new Set([
    'localeDropdown',
    'github', 'custom-github',
    'apiDocs', 'custom-apiDocs',
    'versionPill', 'custom-versionPill',
  ]);
  const leftItems = items.filter(i => i.position !== 'right' && !RIGHT_TYPES.has(i.type));
  const rightItems = items.filter(i => i.position === 'right' || RIGHT_TYPES.has(i.type));

  return (
    /* `navbar` (Docusaurus's framework class) is added alongside the
       brand styles.nav so the internal scroll-anchor offset query
       `document.querySelector('.navbar').clientHeight` resolves. The
       previous JS-only class swizzle made every doc page crash with
       "Cannot read properties of null (reading 'clientHeight')" the
       moment Docusaurus ran its anchor logic on a heading scroll. */
    <nav className={`navbar ${styles.nav}`} role="navigation" aria-label="Main">
      <div className={styles.left}>
        <Link to={homeHref} className={styles.wordmark}>
          {logoSrcRaw && (
            <img
              src={logoSrc}
              alt={logoAlt}
              className={styles.wordmarkIcon}
              width="32"
              height="32"
            />
          )}
          <span className={styles.wordmarkText}>{wordmark}</span>
        </Link>
        <div className={styles.links}>
          {leftItems.map((item, i) => (
            <NavItem key={i} item={item} location={location} appVersion={appVersion} />
          ))}
        </div>
      </div>
      <div className={styles.ctas}>
        {rightItems.map((item, i) => (
          <NavItem key={i} item={item} location={location} appVersion={appVersion} />
        ))}
      </div>
    </nav>
  );
}
