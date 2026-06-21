/**
 * <PartnersFor />
 *
 * Section that lists the partners shipping a given app or solution.
 * Reuses <PartnerCard/> (full variant) for each partner, with the
 * <BecomePartner/> CTA as the trailing grid cell.
 *
 * Pure presentation: the consuming site resolves which partners ship
 * the subject (via usePartnersByApp / usePartnersBySolution) and
 * passes the locale-resolved copy in. When `partners` is empty, the
 * grid contains only the BecomePartner cell so the section still
 * recruits without looking broken.
 *
 * Usage in MDX:
 *
 *   import {PartnersFor} from '@conduction/docusaurus-preset/components';
 *   import {usePartnersByApp, useBecomePartner} from '@site/src/data/partners-catalog';
 *
 *   <PartnersFor
 *     eyebrow="Partners"
 *     title="Partners shipping OpenRegister"
 *     lede="Implementation, hosting, and integration partners that deliver OpenRegister to their customers."
 *     partners={usePartnersByApp('OpenRegister')}
 *     becomePartner={useBecomePartner()}
 *   />
 */

import React from 'react';
import Section from '../primitives/Section';
import SectionHead from '../primitives/SectionHead';
import PartnerCard, {PartnerGrid, BecomePartner} from '../PartnerCard/PartnerCard';

export default function PartnersFor({
  eyebrow = 'Partners',
  title,
  lede,
  partners = [],
  becomePartner,
  gridColumns = 3,
  background = 'default',
  spacing = 'default',
  className,
}) {
  return (
    <Section background={background} spacing={spacing} className={className}>
      <SectionHead eyebrow={eyebrow} title={title} lede={lede} />
      <PartnerGrid columns={gridColumns}>
        {partners.map((p, i) => (
          <PartnerCard key={p.href || p.name || i} {...p} />
        ))}
        {becomePartner && <BecomePartner {...becomePartner} />}
      </PartnerGrid>
    </Section>
  );
}
