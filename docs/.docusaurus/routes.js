import React from 'react';
import ComponentCreator from '@docusaurus/ComponentCreator';

export default [
  {
    path: '/features/',
    component: ComponentCreator('/features/', 'cbb'),
    exact: true
  },
  {
    path: '/docs/',
    component: ComponentCreator('/docs/', '440'),
    routes: [
      {
        path: '/docs/',
        component: ComponentCreator('/docs/', 'e7e'),
        routes: [
          {
            path: '/docs/',
            component: ComponentCreator('/docs/', '941'),
            routes: [
              {
                path: '/docs/business-rules-engine/',
                component: ComponentCreator('/docs/business-rules-engine/', '4aa'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/category/admin-guide/',
                component: ComponentCreator('/docs/category/admin-guide/', 'bc9'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/category/tutorials/',
                component: ComponentCreator('/docs/category/tutorials/', 'ae9'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/category/user-guide/',
                component: ComponentCreator('/docs/category/user-guide/', 'a58'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/export-pipeline/',
                component: ComponentCreator('/docs/export-pipeline/', '053'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/integrator-guide/',
                component: ComponentCreator('/docs/integrator-guide/', '355'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/intro/',
                component: ComponentCreator('/docs/intro/', '224'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/openbuild-rbac/',
                component: ComponentCreator('/docs/openbuild-rbac/', 'd7b'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/openbuild-runtime/',
                component: ComponentCreator('/docs/openbuild-runtime/', 'f06'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/releasing/',
                component: ComponentCreator('/docs/releasing/', '278'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/admin/admin-settings/',
                component: ComponentCreator('/docs/tutorials/admin/admin-settings/', 'd1f'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/admin/rbac/',
                component: ComponentCreator('/docs/tutorials/admin/rbac/', '378'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/admin/template-catalogue/',
                component: ComponentCreator('/docs/tutorials/admin/template-catalogue/', 'f33'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/create-a-virtual-app/',
                component: ComponentCreator('/docs/tutorials/create-a-virtual-app/', '20f'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/update-a-virtual-app/',
                component: ComponentCreator('/docs/tutorials/update-a-virtual-app/', '020'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/connect-data/',
                component: ComponentCreator('/docs/tutorials/user/connect-data/', 'dbd'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/create-from-template/',
                component: ComponentCreator('/docs/tutorials/user/create-from-template/', '8d6'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/design-page/',
                component: ComponentCreator('/docs/tutorials/user/design-page/', '82d'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/design-schema/',
                component: ComponentCreator('/docs/tutorials/user/design-schema/', '1e0'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/export-app/',
                component: ComponentCreator('/docs/tutorials/user/export-app/', '504'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/first-launch/',
                component: ComponentCreator('/docs/tutorials/user/first-launch/', '391'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/preview-app/',
                component: ComponentCreator('/docs/tutorials/user/preview-app/', '356'),
                exact: true,
                sidebar: "tutorialSidebar"
              },
              {
                path: '/docs/tutorials/user/version-snapshots/',
                component: ComponentCreator('/docs/tutorials/user/version-snapshots/', '9d8'),
                exact: true,
                sidebar: "tutorialSidebar"
              }
            ]
          }
        ]
      }
    ]
  },
  {
    path: '/',
    component: ComponentCreator('/', '2e1'),
    exact: true
  },
  {
    path: '*',
    component: ComponentCreator('*'),
  },
];
