/**
 * OpenRegister · object sidebar, Metadata tab.
 *
 * Object frontmatter as a key/value list: register, schema, slug,
 * created, updated, owner. Plus a section break and the object's
 * primary identifiers. Reads as "what is this object" at a glance.
 */
import React from 'react';
import styles from '../SidebarMock.module.css';

export default function OpenRegisterMetadata() {
  return (
    <>
      <div className={[styles.row, styles.head].join(' ')}></div>
      <div className={[styles.row, styles.short].join(' ')}></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smBreak}></div>
      <div className={styles.smSub}></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
      <div className={styles.smKv}><div className={styles.k}></div><div className={styles.v}></div></div>
    </>
  );
}
