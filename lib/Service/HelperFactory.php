<?php

/**
 * Factory of the Service helpers that other components build per use
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\AppTemplateCaptureInterface;
use OCA\Buildiq\Kernel\Contracts\CompanionSchemaCollectorInterface;
use OCA\Buildiq\Kernel\Contracts\RegistrationFormPresenterInterface;
use OCA\Buildiq\Kernel\Contracts\SyncRequestParamsInterface;
use OCA\Buildiq\Service\GitHub\SyncRequestParams;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Builds a fresh helper per call, so callers outside Service depend on the
 * helper's contract only.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class HelperFactory {

	/**
	 * The collector of an app's companion schemas.
	 *
	 * @param RegisterMapper  $registerMapper OpenRegister's register mapper.
	 * @param SchemaMapper    $schemaMapper   OpenRegister's schema mapper.
	 * @param LoggerInterface $logger         The logger.
	 *
	 * @return CompanionSchemaCollectorInterface
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function companionSchemaCollector(
		RegisterMapper $registerMapper,
		SchemaMapper $schemaMapper,
		LoggerInterface $logger,
	): CompanionSchemaCollectorInterface {
		return new CompanionSchemaCollector(registerMapper: $registerMapper, schemaMapper: $schemaMapper, logger: $logger);
	}//end companionSchemaCollector()

	/**
	 * The capture of an app as a template array.
	 *
	 * @return AppTemplateCaptureInterface
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function appTemplateCapture(): AppTemplateCaptureInterface {
		return new AppTemplateCapture();
	}//end appTemplateCapture()

	/**
	 * A request's GitHub sync parameters.
	 *
	 * @param IRequest $request The request the parameters are read from.
	 *
	 * @return SyncRequestParamsInterface
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function syncRequestParams(IRequest $request): SyncRequestParamsInterface {
		return new SyncRequestParams(request: $request);
	}//end syncRequestParams()

	/**
	 * The shape a registration form takes on its way out.
	 *
	 * @return RegistrationFormPresenterInterface
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registrationFormPresenter(): RegistrationFormPresenterInterface {
		return new RegistrationFormPresenter();
	}//end registrationFormPresenter()
}//end class
