/**
 * <ManagedCommonGround />
 *
 * Section that pitches the managed Common Ground+ tenant: a SectionHead,
 * a centred CTA pair, a PairRow of the components on offer, and a
 * compliance footnote. Same shape on /support and /commonground;
 * keeping it as a shared component prevents copy drift between the
 * two surfaces (price changes, new components, eligibility tweaks
 * land once and propagate).
 *
 * Content is passed as props so callers can localise per page (NL +
 * EN MDX twins). The component owns layout and brand-look only.
 *
 * Usage in MDX:
 *
 *   <ManagedCommonGround
 *     eyebrow="Managed Common Ground"
 *     title={<>A government-only Nextcloud framework.<br/>€250 per component per month.</>}
 *     lede={<>For public-sector clients we run a managed <NextBlue>Nextcloud</NextBlue> tenant...</>}
 *     primaryCta={{label: 'Talk to us', href: 'mailto:info@conduction.nl'}}
 *     secondaryCta={{label: 'Compliance signals', href: '/iso'}}
 *     components={[
 *       {name: 'OpenRegister', href: '/apps/openregister', why: 'Typed register store...', icon: <svg>...</svg>},
 *       ...
 *     ]}
 *     footnote="Available to public-sector clients only..."
 *   />
 */

import React from 'react';
import Section from '../primitives/Section';
import SectionHead from '../primitives/SectionHead';
import Button from '../primitives/Button';
import PairCard, {PairRow} from '../PairCard/PairCard';
import styles from './ManagedCommonGround.module.css';

export default function ManagedCommonGround({
  eyebrow,
  title,
  lede,
  primaryCta,
  secondaryCta,
  components = [],
  footnote,
  background,
  spacing = 'default',
}) {
  return (
    <Section spacing={spacing} background={background}>
      <SectionHead eyebrow={eyebrow} title={title} lede={lede} align="stack" />

      {(primaryCta || secondaryCta) && (
        <div className={styles.actions}>
          {primaryCta && (
            <Button variant="primary" size="lg" href={primaryCta.href}>
              {primaryCta.label}
            </Button>
          )}
          {secondaryCta && (
            <Button variant="secondary" size="lg" href={secondaryCta.href}>
              {secondaryCta.label}
            </Button>
          )}
        </div>
      )}

      {components.length > 0 && (
        <PairRow columns={3}>
          {components.map((c, i) => (
            <PairCard key={i} href={c.href} icon={c.icon} name={c.name} why={c.why} />
          ))}
        </PairRow>
      )}

      {footnote && <p className={styles.footnote}>{footnote}</p>}
    </Section>
  );
}
