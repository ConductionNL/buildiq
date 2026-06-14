/**
 * <Clients />
 *
 * Public-sector and ecosystem logo wall. Trust-signal section for the
 * Conduction landing, separate from <PartnerCard /> which is the
 * commercial implementation-partner row on /partners.
 *
 * Two variants:
 *   - "grid"    (default): static columns, used for the partners row
 *                          and any short logo set
 *   - "marquee": three pointy-top hex rows, honeycomb stagger, all
 *                lanes scroll right-to-left at the same speed so the
 *                rows read as one continuous wall. Logos default to
 *                grayscale and restore colour on hover; the lane pauses
 *                on hover and respects prefers-reduced-motion. Below
 *                720px it collapses to a single lane.
 *
 * Logos live in sites/www/static/img/clients/ and ship via the brand
 * assets folder; the kit's downloads page mirrors the same set.
 */

import React from 'react';
import {useLazyStylesheet} from '../../utils/lazyStylesheet';
import SectionHead from '../primitives/SectionHead';
import {useLazyScript} from '../../utils/lazyScript';
import styles from './Clients.module.css';

const LOGO_MEMORY_BASE = '/lib';

export const DEFAULT_CLIENTS = [
  {name: 'Albrandswaard',     src: '/img/clients/albrandswaard.png'},
  {name: 'Alkmaar',           src: '/img/clients/alkmaar.png'},
  {name: 'Amsterdam',         src: '/img/clients/amsterdam.png'},
  {name: 'Baarn',             src: '/img/clients/baarn.png'},
  {name: 'Barendrecht',       src: '/img/clients/barendrecht.png'},
  {name: 'Barneveld',         src: '/img/clients/barneveld.svg'},
  {name: 'Beek',              src: '/img/clients/beek.svg'},
  {name: 'Breda',             src: '/img/clients/breda.png'},
  {name: 'Buren',             src: '/img/clients/buren.png'},
  {name: 'DBP',               src: '/img/clients/dbp.svg'},
  {name: 'De Bilt',           src: '/img/clients/de-bilt.svg'},
  {name: 'Delft',             src: '/img/clients/delft.png'},
  {name: 'Dinkelland',        src: '/img/clients/dinkelland.png'},
  {name: 'Edam-Volendam',     src: '/img/clients/edam-volendam.png'},
  {name: 'Ede',               src: '/img/clients/ede.svg'},
  {name: 'Epe',               src: '/img/clients/epe.png'},
  {name: 'Gooise Meren',      src: '/img/clients/gooise-meren.svg'},
  {name: 'Gouda',             src: '/img/clients/gouda.png'},
  {name: 'Hoeksche Waard',    src: '/img/clients/hoeksche-waard.png'},
  {name: 'Hof van Twente',    src: '/img/clients/hof-van-twente.svg'},
  {name: 'Kansspelautoriteit', src: '/img/clients/ksa.svg'},
  {name: 'Lansingerland',     src: '/img/clients/lansingerland.svg'},
  {name: 'Meppel',            src: '/img/clients/meppel.svg'},
  {name: 'Moerdijk',          src: '/img/clients/moerdijk.svg'},
  {name: 'Molenlanden',       src: '/img/clients/molenlanden.png'},
  {name: 'Noaberkracht',      src: '/img/clients/noaberkracht.svg'},
  {name: 'Noordwijk',         src: '/img/clients/noordwijk.svg'},
  {name: 'ODMH',              src: '/img/clients/odmh.svg'},
  {name: 'Oude IJsselstreek', src: '/img/clients/oude-ijsselstreek.png'},
  {name: 'Overbetuwe',        src: '/img/clients/over-betuwe.svg'},
  {name: 'Provincie Zeeland', src: '/img/clients/provincie-zeeland.svg'},
  {name: 'Ridderkerk',        src: '/img/clients/ridderkerk.png'},
  {name: 'Rijswijk',          src: '/img/clients/rijswijk.png'},
  {name: 'Roosendaal',        src: '/img/clients/roosendaal.svg'},
  {name: 'Rotterdam',         src: '/img/clients/rotterdam.png'},
  {name: 'Soest',             src: '/img/clients/soest.svg'},
  {name: 'Stichtse Vecht',    src: '/img/clients/stichtse-vecht.svg'},
  {name: 'SURF',              src: '/img/clients/surf.svg'},
  {name: 'Tilburg',           src: '/img/clients/tilburg.png'},
  {name: 'Tubbergen',         src: '/img/clients/tubbergen.png'},
  {name: 'VNG',               src: '/img/clients/vng.png'},
  {name: 'Zutphen',           src: '/img/clients/zutphen.svg'},
  {name: 'Zwolle',            src: '/img/clients/zwolle.svg'},
];

export const DEFAULT_PARTNERS = [
  {name: 'Nextcloud',          src: '/img/clients/nextcloud.png'},
  {name: 'iO',                 src: '/img/clients/io.webp'},
  {name: 'Ritense',            src: '/img/clients/ritense.png'},
  {name: 'Shift2',             src: '/img/clients/Shift2.png'},
  {name: 'Open Webconcept',    src: '/img/clients/open_webconcept.png'},
  {name: 'SIDN Fonds',         src: '/img/clients/SIDN.png'},
  {name: 'BCT',                src: '/img/clients/bct.png'},
];

function splitIntoRows(items, rowCount) {
  const rows = Array.from({length: rowCount}, () => []);
  items.forEach((item, i) => rows[i % rowCount].push(item));
  /* Pad shorter rows with duplicates from their own front so every row
     has the same length and the same track width. Without this, the
     marquee's translateX(-50%) animation drifts the rows out of
     honeycomb alignment because each track's 50% resolves to a
     different pixel offset. */
  const max = Math.max(...rows.map(r => r.length));
  rows.forEach(r => {
    let i = 0;
    while (r.length < max) {
      r.push(r[i % (r.length || 1)]);
      i++;
    }
  });
  return rows;
}

/* ClientsMarquee
 *
 * Marquee variant body. The hex wall is now driven by the JS runtime
 * lib/clients-flow.js: each row is a static relative band, the runtime
 * spawns absolutely-positioned hex anchors on the right with random
 * logos from the supplied pool, drifts them left, and culls them once
 * they leave the viewport. We render only the empty row containers
 * here and pass the client pool through as a data-clients JSON
 * attribute. CSS Modules hashes the .hex / .hexLogo class names, so
 * the runtime needs them via data-hex-class / data-hex-logo-class to
 * style its spawned children consistently.
 *
 * Logo Memory's click trigger still works the same way — the runtime
 * spawns real <a class={styles.hex}> elements, so e.target.closest('a')
 * inside the click handler picks them up.
 */
function ClientsMarquee({head, clients, title, className}) {
  useLazyScript(LOGO_MEMORY_BASE + '/clients-flow.js', 'clients-flow');
  useLazyScript(LOGO_MEMORY_BASE + '/logo-memory.js', 'logo-memory');
  useLazyStylesheet(LOGO_MEMORY_BASE + '/logo-memory.css', 'logo-memory');
  React.useEffect(() => {
    if (window.ConductionClientsFlow?.hydrate) window.ConductionClientsFlow.hydrate();
    if (window.LogoMemory?.hydrate) window.LogoMemory.hydrate();
  });
  const clientsJson = React.useMemo(() => JSON.stringify(clients), [clients]);
  return (
    <>
      <section
        className={[styles.section, className].filter(Boolean).join(' ')}
        data-logo-memory
      >
        <div className={styles.inner}>{head}</div>
        <div
          className={styles.marquee}
          data-memory-marquee
          data-clients={clientsJson}
          data-hex-class={styles.hex}
          data-hex-logo-class={styles.hexLogo}
          role="region"
          aria-label={typeof title === 'string' ? title : 'Clients'}
        >
          <div data-flow-row className={[styles.row, styles.row1].join(' ')} />
          <div data-flow-row className={[styles.row, styles.row2].join(' ')} />
          <div data-flow-row className={[styles.row, styles.row3].join(' ')} />
        </div>
      </section>
    </>
  );
}

export default function Clients({
  eyebrow = 'Who we work with',
  title = 'Public sector, ecosystem, partners.',
  lede,
  clients = DEFAULT_CLIENTS,
  variant = 'grid',
  className,
}) {
  const head = (
    <SectionHead
      eyebrow={eyebrow}
      title={title}
      align="stack"
      lede={lede}
    />
  );

  if (variant === 'marquee') {
    return (
      <ClientsMarquee
        head={head}
        clients={clients}
        title={title}
        className={className}
      />
    );
  }

  return (
    <section className={[styles.section, className].filter(Boolean).join(' ')}>
      <div className={styles.inner}>
        {head}
        <div className={styles.grid} role="list">
          {clients.map((c, i) => (
            <div key={i} className={styles.cell} role="listitem">
              <img
                src={c.src}
                alt={c.name}
                title={c.name}
                loading="lazy"
                className={styles.logo}
              />
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
