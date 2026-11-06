<?php

/**
 * Stubs for OpenRegister's form destination contract (openregister#4563).
 *
 * Mirrors openregister/lib/Service/Form/FormDependent.php and
 * lib/Event/FormDestinationDependentsEvent.php, so buildiq's listener can be
 * unit-tested and analysed against an OpenRegister that predates them. Every
 * declaration is guarded with an autoloading class_exists(), so the real
 * classes answer first whenever they are on the autoload path.
 *
 * @category Stub
 * @package  OCA\Buildiq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form {

	// Autoload is ALLOWED here: when the real OpenRegister (with the form
	// destination validator, openregister#4563) is on the autoload path its
	// classes answer first, and these stubs only fire on an OpenRegister that
	// predates them. They mirror the real shapes.
	if (class_exists(FormDependent::class) === false) {
		/**
		 * Stub FormDependent: a published form an owning app announces to a schema save.
		 */
		final class FormDependent {

			/**
			 * @param string               $id        The form's id in its owning app.
			 * @param string               $app       The owning app's id.
			 * @param string               $title     The form's title.
			 * @param string|null          $author    The user id to notify.
			 * @param array<string, mixed> $mapping   The form's mapping into the schema.
			 * @param \Closure|null        $unpublish Unpublishes the form in its owning app.
			 * @param string|null          $audience  `public` or `authenticated`.
			 */
			public function __construct(
				public readonly string $id,
				public readonly string $app,
				public readonly string $title,
				public readonly ?string $author,
				public readonly array $mapping,
				public readonly ?\Closure $unpublish = null,
				public readonly ?string $audience = null,
			) {
			}
		}
	}
}

namespace OCA\OpenRegister\Event {

	if (class_exists(FormDestinationDependentsEvent::class) === false) {
		/**
		 * Stub FormDestinationDependentsEvent: asks owning apps for the forms that submit into a schema.
		 */
		class FormDestinationDependentsEvent extends \OCP\EventDispatcher\Event {

			/**
			 * @var array<int, \OCA\OpenRegister\Service\Form\FormDependent>
			 */
			private array $forms = [];

			/**
			 * @param \OCA\OpenRegister\Db\Schema $schema The proposed schema.
			 */
			public function __construct(private readonly \OCA\OpenRegister\Db\Schema $schema) {
				parent::__construct();
			}

			/**
			 * @return \OCA\OpenRegister\Db\Schema
			 */
			public function getSchema(): \OCA\OpenRegister\Db\Schema {
				return $this->schema;
			}

			/**
			 * @param \OCA\OpenRegister\Service\Form\FormDependent $form The form.
			 *
			 * @return void
			 */
			public function addForm(\OCA\OpenRegister\Service\Form\FormDependent $form): void {
				$this->forms[] = $form;
			}

			/**
			 * @return array<int, \OCA\OpenRegister\Service\Form\FormDependent>
			 */
			public function getForms(): array {
				return $this->forms;
			}
		}
	}
}
