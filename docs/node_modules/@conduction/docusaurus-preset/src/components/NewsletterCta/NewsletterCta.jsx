/**
 * <NewsletterCta />
 *
 * Cobalt-50 panel with title, lede, an email input, a submit button,
 * and a fineprint line. Used at the bottom of the academy landing
 * and on detail pages.
 *
 * The form is unwired by default (`onSubmit` no-op, `action` left
 * empty). Pass an `action` URL or an `onSubmit` handler to wire it.
 * Pass `inverse` to render on a cobalt-900 ground for use inside dark
 * sections.
 *
 * Usage in MDX:
 *
 *   <NewsletterCta
 *     title="New posts in your inbox, monthly."
 *     lede="One mail a month. New guides, case studies, webinars."
 *     placeholder="je@bedrijf.nl"
 *     submitLabel="Subscribe"
 *     fineprint="We mail vanuit info@conduction.nl. Geen lijstverkoop."
 *   />
 */

import React from 'react';
import styles from './NewsletterCta.module.css';

export default function NewsletterCta({
  title,
  lede,
  placeholder = 'jij@bedrijf.nl',
  submitLabel = 'Aanmelden',
  fineprint,
  action,
  method = 'POST',
  name = 'email',
  inverse = false,
  onSubmit,
  className,
}) {
  const composed = [
    styles.cta,
    inverse ? styles.inverse : null,
    className,
  ].filter(Boolean).join(' ');

  const handleSubmit = (event) => {
    if (onSubmit) {
      onSubmit(event);
    } else if (!action) {
      event.preventDefault();
    }
  };

  return (
    <section className={composed}>
      <div className={styles.copy}>
        {title && <h3 className={styles.title}>{title}</h3>}
        {lede && <p className={styles.lede}>{lede}</p>}
      </div>
      <div className={styles.form}>
        <form
          className={styles.formRow}
          action={action || undefined}
          method={method}
          onSubmit={handleSubmit}
        >
          <input
            type="email"
            name={name}
            className={styles.input}
            placeholder={placeholder}
            aria-label={placeholder}
            required
          />
          <button type="submit" className={styles.submit}>
            {submitLabel}
          </button>
        </form>
        {fineprint && <p className={styles.fineprint}>{fineprint}</p>}
      </div>
    </section>
  );
}
