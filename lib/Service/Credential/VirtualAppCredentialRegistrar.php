<?php

/**
 * VirtualAppCredentialRegistrar — onboards a published virtual app to the OpenRegister credential broker.
 *
 * A pure-virtual OpenBuild app (a record in OpenBuild's own register, rendered
 * inside the OpenBuild shell — never installed on disk) has no OpenRegister
 * AppHost runtime context, so OpenRegister's own on-disk onboarding hook
 * ({@see \OCA\OpenRegister\AppHost\Repair\GenericInitializeSettings}) can never
 * reach it. This service is the OpenBuild-side trigger that closes that gap:
 * when an owner publishes a virtual app whose resolved manifest declares a
 * non-empty top-level `credentials[]`, OpenBuild onboards that app to the broker
 * exactly as an on-disk leaf would be, using OpenBuild's own app-id namespace
 * (`openbuild-{slug}`).
 *
 * Onboarding runs the SAME two independent, idempotent paths OpenRegister uses
 * on disk, both delegated to OpenRegister-owned services (OpenBuild owns only
 * the trigger, never the registration logic — design "OR owns it"):
 *   1. Broker app-key — {@see \OCA\OpenRegister\Service\Credential\CredentialAppTokenService::registerApp},
 *      guarded by `isRegistered()` so an existing signing secret is NEVER rotated
 *      by a re-publish.
 *   2. Per-app Doriath application — {@see \OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar::registerApplication}
 *      (identity-only, pending admin approval; itself idempotent + never-throw).
 *
 * OpenRegister is a hard dependency of OpenBuild, but the two credential
 * services are resolved lazily via `class_exists` + `OCP\Server::get` and every
 * path is wrapped so onboarding NEVER blocks or fails a publish — a broker/
 * Doriath hiccup must not stop an app going live.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service\Credential;

use OCA\OpenBuild\Service\ManifestResolverService;
use OCP\IUser;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Triggers OpenRegister credential-broker onboarding for a published virtual app.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The OpenRegister credential services are
 *   resolved via `OCP\Server::get` behind `class_exists` guards so the exact
 *   onboarding wiring stays owned by OpenRegister, resolved at call time.
 *
 * @spec openregister/openspec/changes/per-app-doriath-application/specs/credential-broker/spec.md#per-app-doriath-application-registration
 */
class VirtualAppCredentialRegistrar
{
    /**
     * FQCN of OpenRegister's broker app-key service.
     *
     * @var string
     */
    private const TOKEN_SERVICE = 'OCA\\OpenRegister\\Service\\Credential\\CredentialAppTokenService';

    /**
     * FQCN of OpenRegister's per-app Doriath application registrar.
     *
     * @var string
     */
    private const DORIATH_REGISTRAR = 'OCA\\OpenRegister\\Service\\Credential\\DoriathApplicationRegistrar';

    /**
     * App-id prefix for an OpenBuild virtual app (matches the per-app register slug prefix).
     *
     * @var string
     */
    private const APP_ID_PREFIX = 'openbuild-';

    /**
     * Constructor.
     *
     * @param ManifestResolverService $manifestResolver Version-aware manifest resolver (reads `credentials[]`).
     * @param LoggerInterface         $logger           Secret-free diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly ManifestResolverService $manifestResolver,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Onboard a just-published virtual app to the credential broker when its manifest declares credentials.
     *
     * Never throws: any failure (manifest unresolvable, broker/Doriath absent,
     * registration error) is logged and swallowed so the publish that triggered
     * it is never blocked. A no-op when the manifest declares no `credentials[]`.
     *
     * @param string     $slug   The virtual app slug (its app-id becomes `openbuild-{slug}`).
     * @param IUser|null $caller The publishing user (forwarded to the manifest resolver).
     *
     * @return void
     *
     * @spec openregister/openspec/changes/per-app-doriath-application/specs/credential-broker/spec.md#per-app-doriath-application-registration
     */
    public function onPublish(string $slug, ?IUser $caller): void
    {
        try {
            $manifest = $this->manifestResolver->resolve(appSlug: $slug, versionSlug: null, caller: $caller);
            if ($this->manifestDeclaresCredentials(manifest: $manifest) === false) {
                return;
            }

            $appId = self::APP_ID_PREFIX.$slug;
            if (preg_match('/^[a-z0-9_-]+$/', $appId) !== 1) {
                $this->logger->warning(
                    'OpenBuild: virtual-app credential onboarding skipped — unsafe app id {appId}',
                    ['appId' => $appId]
                );
                return;
            }

            $this->registerBrokerAppKey(appId: $appId);
            $this->registerDoriathApplication(appId: $appId, description: $this->manifestDescription(manifest: $manifest));
        } catch (Throwable $e) {
            // Onboarding is best-effort — a broker/Doriath problem must never fail a publish.
            $this->logger->warning(
                'OpenBuild: virtual-app credential onboarding skipped for {slug}',
                ['slug' => $slug, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end onPublish()

    /**
     * Register the virtual app's broker signing key, guarded so a re-publish never rotates it.
     *
     * @param string $appId The virtual app id (`openbuild-{slug}`).
     *
     * @return void
     */
    private function registerBrokerAppKey(string $appId): void
    {
        try {
            $tokenService = $this->resolveService(fqcn: self::TOKEN_SERVICE);
            if ($tokenService === null) {
                return;
            }

            if ($tokenService->isRegistered($appId) === true) {
                // Never rotate an existing signing secret from a re-publish.
                $this->logger->debug('OpenBuild: {appId} already registered with the credential broker', ['appId' => $appId]);
                return;
            }

            // The returned secret is intentionally unused — a virtual app that
            // does not sign its own tokens still gets a stable key so it can be
            // exported to a real app later without a broker re-onboard.
            $tokenService->registerApp($appId);
            $this->logger->info('OpenBuild: registered {appId} with the credential broker (manifest declares credentials)', ['appId' => $appId]);
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild: broker app-key registration skipped for {appId}',
                ['appId' => $appId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end registerBrokerAppKey()

    /**
     * Register the virtual app as its own identity-only, pending Doriath application.
     *
     * Delegates to OpenRegister's {@see DoriathApplicationRegistrar}, which is
     * itself idempotent (a live persisted UUID is a no-op) and never-throw.
     *
     * @param string      $appId       The virtual app id (Doriath application name).
     * @param string|null $description The manifest description (falls back to the app id in the registrar).
     *
     * @return void
     */
    private function registerDoriathApplication(string $appId, ?string $description): void
    {
        try {
            $registrar = $this->resolveService(fqcn: self::DORIATH_REGISTRAR);
            if ($registrar === null) {
                return;
            }

            $registrar->registerApplication($appId, $description);
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild: per-app Doriath registration skipped for {appId}',
                ['appId' => $appId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end registerDoriathApplication()

    /**
     * Whether a resolved manifest declares a non-empty top-level `credentials[]`.
     *
     * @param array<string,mixed>|null $manifest The resolved manifest, or null.
     *
     * @return bool True when the manifest declares at least one credential.
     */
    private function manifestDeclaresCredentials(?array $manifest): bool
    {
        if ($manifest === null) {
            return false;
        }

        $credentials = ($manifest['credentials'] ?? null);
        return (is_array($credentials) === true && $credentials !== []);
    }//end manifestDeclaresCredentials()

    /**
     * Derive a human description for the Doriath application from the manifest.
     *
     * Prefers a top-level `description`, then `name`, else null (the registrar
     * falls back to the app id).
     *
     * @param array<string,mixed>|null $manifest The resolved manifest.
     *
     * @return string|null The description, or null.
     */
    private function manifestDescription(?array $manifest): ?string
    {
        if ($manifest === null) {
            return null;
        }

        foreach (['description', 'name', 'title'] as $key) {
            $value = ($manifest[$key] ?? null);
            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        return null;
    }//end manifestDescription()

    /**
     * Resolve an optional OpenRegister credential service, or null when unavailable.
     *
     * Protected so unit tests can substitute a contract fake for either service.
     *
     * @param string $fqcn The service FQCN.
     *
     * @return object|null The resolved service, or null when the class is absent/unresolvable.
     */
    protected function resolveService(string $fqcn): ?object
    {
        if (class_exists($fqcn) === false) {
            return null;
        }

        try {
            return Server::get($fqcn);
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: failed to resolve {fqcn}', ['fqcn' => $fqcn, 'exception' => $e->getMessage()]);
            return null;
        }
    }//end resolveService()
}//end class
