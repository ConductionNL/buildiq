/**
 * OpenBuilt landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

/* Builder glyph: a stylised stack of blocks being assembled — the
   citizen developer composing an app from registers, connectors,
   workflows, and documents. */
const OPENBUILT_ICON = (
  <svg viewBox="0 0 24 24">
    <path d="M4 7l8-4 8 4-8 4-8-4zm0 5l8 4 8-4M4 17l8 4 8-4" />
  </svg>
);

const TAGLINE = (
  <>
    App builder on your <span className="next-blue">Nextcloud</span>. Compose
    a working app from typed registers, connectors, workflows, and document
    templates — design schemas and pages, wire data, preview, version, export.
    No code, no second platform.
  </>
);

function TemplateGalleryPanel() {
  const rows = [
    { label: 'CRM starter', tone: 'var(--c-cobalt-300)' },
    { label: 'Intake form', tone: 'var(--c-lavender-300)' },
    { label: 'Asset register', tone: 'var(--c-mint-500)' },
    { label: 'Help desk', tone: 'var(--c-forest-300)' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 12,
              height: 14,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 2,
            }}
          >
            <div
              style={{
                height: 4,
                width: '60%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '40%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.label}
          </div>
        </div>
      ))}
    </div>
  );
}

function SchemaDesignerPanel() {
  const rows = [
    { label: 'title', type: 'string' },
    { label: 'status', type: 'enum' },
    { label: 'owner', type: 'relation' },
    { label: 'dueDate', type: 'date' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '4px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              fontWeight: 600,
              color: 'var(--c-cobalt-700)',
              width: 60,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 4,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-mint-500)',
            }}
          >
            {row.type}
          </div>
        </div>
      ))}
    </div>
  );
}

function VersionSnapshotPanel() {
  const rows = [
    { val: 'v1.3', tone: 'var(--c-mint-500)', tag: 'current' },
    { val: 'v1.2', tone: 'var(--c-cobalt-300)', tag: 'snapshot' },
    { val: 'v1.1', tone: 'var(--c-cobalt-300)', tag: 'snapshot' },
    { val: 'v1.0', tone: 'var(--c-cobalt-200)', tag: 'snapshot' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 10,
              height: 11,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 11,
              fontWeight: 700,
              color: 'var(--c-cobalt-700)',
              width: 36,
            }}
          >
            {row.val}
          </div>
          <div
            style={{
              flex: 1,
              height: 4,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              letterSpacing: '0.05em',
              textTransform: 'uppercase',
              color: 'var(--c-cobalt-500)',
            }}
          >
            {row.tag}
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Template gallery',
    desc: 'Start from a curated template — CRM, intake form, asset register, help desk — or a blank app. Admins decide what shows up here.',
    panel: <TemplateGalleryPanel />,
  },
  {
    title: 'Schema designer',
    desc: 'Define your data model field by field. Strings, enums, dates, relations — typed registers backed by OpenRegister, no migrations.',
    panel: <SchemaDesignerPanel />,
  },
  {
    title: 'Version snapshots',
    desc: 'Snapshot the whole app — schemas, pages, wiring — and roll back when an edit goes wrong. Export the bundle as a ZIP any time.',
    panel: <VersionSnapshotPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="OpenBuilt"
      description="Citizen-developer app builder for Nextcloud — compose apps from registers, connectors, workflows, and documents without code."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="openbuilt"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          version="v0.x"
          locales="NL · EN"
          title="OpenBuilt"
          tagline={TAGLINE}
          primaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/openbuilt',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          iconColor="var(--c-orange-knvb)"
          icon={OPENBUILT_ICON}
          illustration={<AppMock app="openbuilt" />}
        />

        <WidgetShelf
          eyebrow="What you build with"
          title="Compose an app, no code required."
          lede="Pick a template, design your schemas and pages, wire in registers and connectors, preview the running app, snapshot a version, export when you're done."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
