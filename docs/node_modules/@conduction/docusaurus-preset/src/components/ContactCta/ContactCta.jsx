/**
 * <ContactCta />
 *
 * Soft tinted call-to-action panel for the bottom of academy
 * tutorials, docs pages, or any long-form content. Composed from
 * <HexCard decoration> with a mail icon in the top-left badge and
 * the primary CTA in the right-side actions slot.
 *
 * Tone: cobalt-50 ground, cobalt-900 type, cobalt-700 body, KNVB
 * orange reserved for the trailing arrow inside the CTA. One
 * orange accent per screen rule still holds: this panel uses the
 * orange only as a 12px arrow, never as the button fill.
 *
 * Distinct from <CtaBanner /> (cobalt-900 marketing footer with
 * dual CTAs and centred type) and from <NewsletterCta /> (form,
 * not a single button). Use ContactCta when:
 *   - Reader has finished a tutorial and you want them to email
 *   - You're at the end of a docs page and want them to read further
 *   - You need a "talk to us" prompt that is calmer than the dark
 *     CtaBanner but louder than a plain link
 *
 * Usage in MDX:
 *
 *   <ContactCta
 *     title="Wil je meer weten?"
 *     body="Mail ons. We helpen je in een halve dag op weg met je eigen Woo-publicatieflow."
 *     cta={{ label: "Mail ons", href: "mailto:info@conduction.nl" }}
 *   />
 *
 * Mirrors the .contact-cta section in
 * preview/components/contact-cta.html.
 */

import React from 'react';
import HexCard from '../HexCard/HexCard';
import styles from './ContactCta.module.css';

function CtaButton({cta}) {
  if (!cta) return null;
  const isExternal = cta.href && /^https?:/.test(cta.href);
  return (
    <a
      className={styles.button}
      href={cta.href || '#'}
      target={isExternal ? '_blank' : undefined}
      rel={isExternal ? 'noreferrer noopener' : undefined}
    >
      <span>{cta.label}</span>
      <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"
           fill="none" stroke="currentColor" strokeWidth="2"
           strokeLinecap="round" strokeLinejoin="round">
        <path d="M5 12h14M12 5l7 7-7 7"/>
      </svg>
    </a>
  );
}

export default function ContactCta({title, body, cta, className}) {
  return (
    <HexCard
      title={title}
      icon="mail"
      decoration
      actions={<CtaButton cta={cta} />}
      className={className}
    >
      {body && <p>{body}</p>}
    </HexCard>
  );
}
