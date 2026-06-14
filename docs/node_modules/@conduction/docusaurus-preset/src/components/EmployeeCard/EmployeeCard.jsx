/**
 * <EmployeeCard /> + <TeamGrid />
 *
 * Person-card pattern from preview/components/employee-cards.html.
 * Three variants:
 *   - compact: row card for dense team grids (avatar + name + role + links)
 *   - photo:   centered photo card for /about (large hex avatar + bio + links)
 *   - detail:  spotlight card with the cobalt-50 corner-hex for /about/team
 *
 * The avatar is a 44x50 (compact) or 72x83 (large) pointy-top hex.
 * Pass `initials` for an avatar-fill, or `photo` for a photographed person.
 *
 * Usage:
 *
 *   <TeamGrid columns={3}>
 *     <EmployeeCard
 *       variant="compact"
 *       name="Ruben van der Linde"
 *       role="Founder · Architect"
 *       initials="RV"
 *       avatarColor="var(--c-blue-cobalt)"
 *       links={[
 *         {label: 'email', href: 'mailto:ruben@conduction.nl', icon: 'mail'},
 *         {label: 'github', href: 'https://github.com/...', icon: 'github'},
 *       ]}
 *     />
 *   </TeamGrid>
 */

import React from 'react';
import styles from './EmployeeCard.module.css';

const ICONS = {
  mail: <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>,
  github: <svg viewBox="0 0 24 24"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>,
  linkedin: <svg viewBox="0 0 24 24"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>,
  phone: <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.37 1.9.72 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.35 1.85.59 2.81.72A2 2 0 0 1 22 16.92z"/></svg>,
};

export function TeamGrid({columns = 3, children, className}) {
  const composed = [styles.grid, styles['cols-' + columns], className].filter(Boolean).join(' ');
  return <div className={composed}>{children}</div>;
}

export default function EmployeeCard({
  variant = 'compact',
  name,
  role,
  initials,
  photo,
  avatarColor,
  bio,
  apps = [],
  links = [],
  className,
}) {
  if (variant === 'photo') {
    return (
      <div className={[styles.cardPhoto, className].filter(Boolean).join(' ')}>
        <div className={styles.avatarLarge} style={!photo ? {background: avatarColor || 'var(--c-blue-cobalt)'} : undefined}>
          {photo ? <img src={photo} alt={name} /> : initials}
        </div>
        {name && <div className={styles.name}>{name}</div>}
        {role && <div className={styles.role}>{role}</div>}
        {bio && <p className={styles.bio}>{bio}</p>}
        {links.length > 0 && (
          <div className={styles.contacts}>
            {links.map((l, i) => (
              <a key={i} href={l.href} aria-label={l.label}>{ICONS[l.icon] || l.label}</a>
            ))}
          </div>
        )}
      </div>
    );
  }

  if (variant === 'detail') {
    return (
      <div className={[styles.cardDetail, className].filter(Boolean).join(' ')}>
        <div className={styles.avatarLarge} style={!photo ? {background: avatarColor || 'var(--c-blue-cobalt)'} : undefined}>
          {photo ? <img src={photo} alt={name} /> : initials}
        </div>
        <div>
          {name && <div className={styles.name}>{name}</div>}
          {role && <div className={styles.role}>{role}</div>}
        </div>
        {bio && <p className={styles.bio}>{bio}</p>}
        {apps.length > 0 && (
          <div>
            <div className={styles.appsLabel}>Apps I work on</div>
            <div className={styles.appsList}>
              {apps.map((a, i) => <span key={i} className={styles.appPill}>{a}</span>)}
            </div>
          </div>
        )}
        {links.length > 0 && (
          <div className={styles.contactsInline}>
            {links.map((l, i) => (
              <a key={i} href={l.href}>{ICONS[l.icon]}{l.label}</a>
            ))}
          </div>
        )}
      </div>
    );
  }

  /* default: compact */
  const Tag = links.length > 0 && links[0].href ? 'a' : 'div';
  return (
    <Tag href={Tag === 'a' ? links[0].href : undefined} className={[styles.cardCompact, className].filter(Boolean).join(' ')}>
      <div className={styles.avatar} style={!photo ? {background: avatarColor || 'var(--c-blue-cobalt)'} : undefined}>
        {photo ? <img src={photo} alt={name} /> : initials}
      </div>
      <div className={styles.info}>
        {name && <div className={styles.name}>{name}</div>}
        {role && <div className={styles.role}>{role}</div>}
      </div>
      {links.length > 0 && (
        <div className={styles.linksRow}>
          {links.map((l, i) => (
            <a key={i} href={l.href} aria-label={l.label}>{ICONS[l.icon]}</a>
          ))}
        </div>
      )}
    </Tag>
  );
}
