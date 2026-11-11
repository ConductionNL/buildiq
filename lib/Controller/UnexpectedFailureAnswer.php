<?php

/**
 * Buildiq UnexpectedFailureAnswer
 *
 * A failure that is not a refusal: logged with its cause, answered without
 * it, because the cause names stored objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs an unexpected failure and answers it with a generic 500.
 */
final class UnexpectedFailureAnswer {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the failure with its cause and answer it without.
	 *
	 * @param string $what What was being attempted.
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse The 500.
	 */
	public function answer(string $what, Throwable $e): JSONResponse {
		$this->logger->error('Buildiq: ' . $what . ' failed: ' . $e->getMessage(), ['exception' => $e]);

		return new JSONResponse(
			['error' => 'internal_error', 'message' => 'That did not work. The log says why.'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end answer()
}//end class
