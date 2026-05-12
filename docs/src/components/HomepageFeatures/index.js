import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Compose, don\'t code',
    description: (
      <>
        Build an app from typed registers, OpenConnector connectors, n8n workflows, and DocuDesk templates. Design schemas and pages in the browser — no migrations, no deployment pipeline.
      </>
    ),
  },
  {
    title: 'Start from a template',
    description: (
      <>
        Pick a curated starter — CRM, intake form, asset register, help desk — or a blank app. Administrators decide which templates appear in the catalogue.
      </>
    ),
  },
  {
    title: 'Built on OpenRegister',
    description: (
      <>
        Every app's data lives in OpenRegister: citation-stable IDs, audit trails, RBAC on records. Snapshot a version, roll back a bad edit, or export the whole bundle as a ZIP.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
