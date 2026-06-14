/**
 * @conduction/diagrams
 *
 * Brand-strict diagram primitives as web components.
 * Importing this module registers every <cn-*> element on the page.
 *
 * Usage (no build step):
 *   <script type="module" src="../diagrams/src/index.js"></script>
 *   <cn-hex color="cobalt" size="lg">Connect</cn-hex>
 *
 * Components:
 *   <cn-hex>            — pointy-top hex primitive (label + icon)
 *   <cn-domain-tree>    — vertical apex-and-branches layout for domain maps
 *   <cn-platform>       — workspace-plus-orbiting-apps cluster
 *   <cn-pipeline>       — horizontal flow of stages connected by arrows
 *   <cn-side-box>       — rectangle-feed pattern for non-app surfaces
 *   <cn-honeycomb-bg>   — honeycomb backdrop wrapper for hero scenes
 *   <cn-pair>           — two systems bridged by an orange arrow
 *   <cn-arch-flow>      — single row of an architecture / request-flow diagram
 */

export * from './cn-hex.js';
export * from './cn-domain-tree.js';
export * from './cn-platform.js';
export * from './cn-pipeline.js';
export * from './cn-side-box.js';
export * from './cn-honeycomb-bg.js';
export * from './cn-pair.js';
export * from './cn-arch-flow.js';
