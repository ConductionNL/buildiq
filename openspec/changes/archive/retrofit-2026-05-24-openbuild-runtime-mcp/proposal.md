# Retrofit — openbuilt-runtime (MCP surface)

Describes observed behaviour of 16 methods under `lib/Mcp/OpenBuiltToolProvider.php`
as 4 new REQs added to the `openbuilt-runtime` capability. Code already exists —
this change retroactively specifies it.

## Affected code units

- lib/Mcp/OpenBuiltToolProvider.php::getAppId
- lib/Mcp/OpenBuiltToolProvider.php::getTools
- lib/Mcp/OpenBuiltToolProvider.php::invokeTool
- lib/Mcp/OpenBuiltToolProvider.php::errorResult
- lib/Mcp/OpenBuiltToolProvider.php::requireAuthenticatedUser
- lib/Mcp/OpenBuiltToolProvider.php::isAdmin
- lib/Mcp/OpenBuiltToolProvider.php::validateListAppsArgs
- lib/Mcp/OpenBuiltToolProvider.php::isValidSlug
- lib/Mcp/OpenBuiltToolProvider.php::resolveApplicationBySlug
- lib/Mcp/OpenBuiltToolProvider.php::mapApplication
- lib/Mcp/OpenBuiltToolProvider.php::sourceDescriptor
- lib/Mcp/OpenBuiltToolProvider.php::buildDeepLink
- lib/Mcp/OpenBuiltToolProvider.php::toArray
- lib/Mcp/OpenBuiltToolProvider.php::extractUuid
- lib/Mcp/OpenBuiltToolProvider.php::loadVersion
- lib/Mcp/OpenBuiltToolProvider.php::saveVersionManifest

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
