/**
 * <FAQ /> + <FAQItem />
 *
 * FAQ accordion ported from preview/pages/support.html (.faq, .faq details).
 * Native <details>/<summary> for keyboard, screen-reader, and no-JS support.
 * One question expands at a time on click; users can have several open.
 *
 * Emits FAQPage JSON-LD automatically from the FAQItem children, so AI
 * crawlers (Google AI Overviews, Perplexity, ChatGPT) pick up each
 * question + answer as a structured entity. Pass `schema={false}` to
 * suppress the JSON-LD output (useful when the FAQ block isn't the
 * primary content on the page).
 *
 * Usage:
 *
 *   <FAQ title="FAQ.">
 *     <FAQItem question="If the apps are free, what does support cost?" defaultOpen>
 *       Whatever the partner you pick charges. Conduction sets no minimum.
 *     </FAQItem>
 *     <FAQItem question="Why doesn't Conduction sell support directly?">
 *       Two reasons. First, ...
 *     </FAQItem>
 *   </FAQ>
 */

import React from 'react';
import Head from '@docusaurus/Head';
import styles from './FAQ.module.css';

export function FAQItem({question, defaultOpen, children, className}) {
  return (
    <details className={[styles.item, className].filter(Boolean).join(' ')} open={defaultOpen || undefined}>
      <summary className={styles.summary}>{question}</summary>
      {/* div not p: MDX wraps inline children in its own <p>, which would
          be invalid nested inside another <p>. */}
      <div className={styles.body}>{children}</div>
    </details>
  );
}

/**
 * Walk a React children tree and flatten to plain text. Used to
 * convert the JSX body of each <FAQItem> into the `text` field
 * schema.org/Question expects. Strips elements, keeps strings and
 * numbers, joins siblings with a single space.
 */
function flattenToText(node) {
  if (node == null || node === false) return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(flattenToText).join(' ');
  if (node.props && node.props.children) return flattenToText(node.props.children);
  return '';
}

export default function FAQ({title = 'FAQ.', children, className, schema = true}) {
  const composed = [styles.faq, className].filter(Boolean).join(' ');

  /* Build FAQPage JSON-LD from FAQItem children. Each item turns into
     a Question entity with an Answer body. Items without a `question`
     prop (or with empty body text) are skipped so a partial render
     doesn't ship a malformed schema. */
  const items = schema
    ? React.Children.toArray(children)
        .filter(c => c && c.props && c.props.question)
        .map(c => {
          const text = flattenToText(c.props.children).trim().replace(/\s+/g, ' ');
          if (!text) return null;
          return {
            '@type': 'Question',
            name: c.props.question,
            acceptedAnswer: {'@type': 'Answer', text},
          };
        })
        .filter(Boolean)
    : [];
  const showSchema = schema && items.length > 0;

  return (
    <section className={composed}>
      {showSchema && (
        <Head>
          <script type="application/ld+json">
            {JSON.stringify({
              '@context': 'https://schema.org',
              '@type': 'FAQPage',
              mainEntity: items,
            })}
          </script>
        </Head>
      )}
      {title && <h2 className={styles.heading}>{title}</h2>}
      {children}
    </section>
  );
}
