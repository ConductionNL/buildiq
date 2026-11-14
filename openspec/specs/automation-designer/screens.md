# Screens

The flow screens are one set (decision 379, 5 Nov 2026): the canonical boards live under OpenRegister, and an app's flow board is a variant that imports the shared parts with the app's own example flow, step types and wording.

- BqAutomatiseringen: automations per app version with the run log of one automation; variant of the canonical set, imports OrFlowOverzichtTabel, OrFlowRuns https://identity.conduction.nl/screens/board?id=buildiq/BqAutomatiseringen
- BqAutomatiseringBewerken: an automation in list mode: when, what, and then; variant of the canonical set, imports OrFlowKop, OrFlowStappenlijst, OrFlowStap https://identity.conduction.nl/screens/board?id=buildiq/BqAutomatiseringBewerken
- BqAutomatiseringTesten: a dry run on an example payload; variant of the canonical set, imports OrFlowKop, OrFlowStappenlijst, OrFlowTestrunKaart https://identity.conduction.nl/screens/board?id=buildiq/BqAutomatiseringTesten
- BqFlowsBewerken: a pipelinq flow edited from buildiq in list mode; replaces the separate edit dialog; variant of the canonical set, imports OrFlowKop, OrFlowStappenlijst, OrFlowStap https://identity.conduction.nl/screens/board?id=buildiq/BqFlowsBewerken
- openregister/OrFlowLijst: the canonical list mode these variants follow https://identity.conduction.nl/screens/board?id=openregister/OrFlowLijst
