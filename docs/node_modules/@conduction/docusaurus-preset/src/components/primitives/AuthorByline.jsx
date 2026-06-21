/**
 * <AuthorByline />
 *
 * The author + date line that appears on every academy card and at the
 * top of every content detail page. Avatar circle (initials or photo)
 * + name + bullet separator + formatted date.
 *
 * Mirrors the meta line in the academy "Keep learning…" cards and the
 * header byline on detail pages.
 *
 * Usage:
 *
 *   <AuthorByline name="Ruben van der Linde" date="2026-05-05" />
 *
 *   <AuthorByline
 *     name="Mike Goldsmith"
 *     date="2026-05-04"
 *     avatarSrc="/img/team/mike.jpg"
 *     locale="en"
 *   />
 *
 * The component derives initials from the name when no avatarSrc is
 * provided. Date is formatted with `Intl.DateTimeFormat(locale, {
 * day: 'numeric', month: 'long', year: 'numeric' })`. Pass a
 * pre-formatted string in `dateLabel` to override.
 */

import React from 'react';
import styles from './AuthorByline.module.css';

function initialsFor(name) {
  if (!name) return '';
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function formatDate(date, locale) {
  if (!date) return '';
  const d = date instanceof Date ? date : new Date(date);
  if (Number.isNaN(d.getTime())) return String(date);
  try {
    return new Intl.DateTimeFormat(locale || 'nl-NL', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(d);
  } catch (_) {
    return d.toISOString().slice(0, 10);
  }
}

export default function AuthorByline({
  name,
  date,
  dateLabel,
  avatarSrc,
  initials,
  locale,
  tone = 'default',
  className,
  ...rest
}) {
  const composed = [
    styles.byline,
    styles['tone-' + tone],
    className,
  ].filter(Boolean).join(' ');

  const computedInitials = initials || initialsFor(name);
  const formattedDate = dateLabel || formatDate(date, locale);

  return (
    <span className={composed} {...rest}>
      <span className={styles.avatar} aria-hidden="true">
        {avatarSrc
          ? <img src={avatarSrc} alt="" />
          : computedInitials}
      </span>
      {name && <span className={styles.name}>{name}</span>}
      {name && formattedDate && <span className={styles.sep}>·</span>}
      {formattedDate && <span className={styles.date}>{formattedDate}</span>}
    </span>
  );
}
