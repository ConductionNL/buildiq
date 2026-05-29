# Retrofit — openbuild-runtime (MCP surface)

Describes observed behaviour of 16 methods under `lib/Mcp/OpenBuildToolProvider.php`
as 4 new REQs added to the `openbuild-runtime` capability. Code already exists —
this change retroactively specifies it.

## Affected code units

- lib/Mcp/OpenBuildToolProvider.php::getAppId
- lib/Mcp/OpenBuildToolProvider.php::getTools
- lib/Mcp/OpenBuildToolProvider.php::invokeTool
- lib/Mcp/OpenBuildToolProvider.php::errorResult
- lib/Mcp/OpenBuildToolProvider.php::requireAuthenticatedUser
- lib/Mcp/OpenBuildToolProvider.php::isAdmin
- lib/Mcp/OpenBuildToolProvider.php::validateListAppsArgs
- lib/Mcp/OpenBuildToolProvider.php::isValidSlug
- lib/Mcp/OpenBuildToolProvider.php::resolveApplicationBySlug
- lib/Mcp/OpenBuildToolProvider.php::mapApplication
- lib/Mcp/OpenBuildToolProvider.php::sourceDescriptor
- lib/Mcp/OpenBuildToolProvider.php::buildDeepLink
- lib/Mcp/OpenBuildToolProvider.php::toArray
- lib/Mcp/OpenBuildToolProvider.php::extractUuid
- lib/Mcp/OpenBuildToolProvider.php::loadVersion
- lib/Mcp/OpenBuildToolProvider.php::saveVersionManifest

## Approach

- Group the 16 methods into 4 REQs by observable behaviour (provider contract,
  auth-gated dispatch + arg validation, application resolution + response
  mapping, draft-version manifest mutation isolation).
- One REQ per distinct observable behaviour; helpers like `toArray` and
  `extractUuid` fold into the resolution REQ they support rather than getting
  their own REQ.
- Notes flag the duplicated slug-validation surface (`isValidSlug` overlaps the
  existing `SlugValidator` service) for future tightening — not silently fixed.

Source: `openspec/coverage-report.md` generated 2026-05-24. See
[retrofit playbook](../../../../hydra/.github/docs/claude/retrofit.md).
