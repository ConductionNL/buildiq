## ADDED Requirements

### Requirement: Installing a linked app provisions its bound infrastructure

Installing an application from a linked GitHub repository SHALL provision the registers and
connectors the application is bound to, not only its manifest. An installed application
whose bindings resolve to nothing is not installed.

The install response SHALL carry a `channels` report so that the caller can distinguish an
application that is installed and runnable from one that is installed but missing the
infrastructure or credentials it needs.

#### Scenario: An installed application resolves its bindings
- **WHEN** an application declaring one bound data register and four connector kinds is
  installed from its repository onto a clean instance
- **THEN** the bound register exists on the instance
- **AND** every declared connector resolves at its published UUID
- **AND** the response `channels` report accounts for every declared item

#### Scenario: A partially installable application says so
- **WHEN** the same application is installed onto an instance without `openconnector`
- **THEN** the response reports the connectors channel as skipped with its reason
- **AND** the caller can tell from the response that the application is not yet runnable
