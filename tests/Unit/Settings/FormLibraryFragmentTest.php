<?php

/**
 * Unit tests for the form library register fragment (83-form-library.json).
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The register import accepts a form template as the save dialog writes it,
 * and refuses what is not one.
 */
final class FormLibraryFragmentTest extends TestCase {

	/**
	 * The fragment file.
	 *
	 * @var string
	 */
	private string $path = __DIR__ . '/../../../lib/Settings/register.d/83-form-library.json';

	/**
	 * The formTemplate schema, reduced to its JSON Schema keywords and closed,
	 * so an undeclared key the code writes fails here instead of being dropped.
	 *
	 * @return object
	 */
	private function schema(): object {
		$data = json_decode((string)file_get_contents($this->path));
		$this->assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: json_last_error_msg());

		$fragment = $data->components->schemas->formTemplate;

		return (object)[
			'type'                 => $fragment->type,
			'required'             => $fragment->required,
			'properties'           => $fragment->properties,
			'additionalProperties' => false,
		];
	}//end schema()

	/**
	 * The record formCapture.js builds for the subsidy form of the spec scenario:
	 * a registration form with six bound properties and no records.
	 *
	 * @return array<string, mixed>
	 */
	private function subsidyRecord(): array {
		$names = ['naam', 'adres', 'postcode', 'verbruikKwh', 'iban', 'toelichting'];
		$fields = [];
		$fragment = [];
		foreach ($names as $index => $name) {
			$fields[] = ['name' => $name, 'label' => ucfirst($name), 'order' => $index];
			$type = 'string';
			if ($name === 'verbruikKwh') {
				$type = 'number';
			}

			$fragment[$name] = ['type' => $type];
		}

		return [
			'slug'                  => 'aanvraag-energiesubsidie',
			'name'                  => 'Aanvraag energiesubsidie',
			'description'           => 'Vraag een energiesubsidie aan.',
			'category'              => 'citizen-engagement',
			'kind'                  => 'registration-form',
			'form'                  => [
				'fields'           => $fields,
				'steps'            => [['title' => 'Gegevens']],
				'presets'          => [['field' => 'naam', 'value' => '', 'hidden' => false]],
				'confirmationText' => 'Bedankt voor uw aanvraag.',
			],
			'schemaFragment'        => $fragment,
			'sourceSchema'          => 'aanvraag',
			'publisher'             => 'Gemeente Voorbeeld',
			'version'               => '1.0.0',
			'sourceApplicationSlug' => 'subsidies',
			'createdBy'             => 'maker',
		];
	}//end subsidyRecord()

	/**
	 * Validate a record against the closed fragment.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The formatted errors, or '' when valid.
	 */
	private function errors(array $record): string {
		$payload = json_decode((string)json_encode($record));
		$result = (new Validator())->validate($payload, $this->schema());
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end errors()

	/**
	 * The subsidy form, as the save dialog writes it, is accepted.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsASavedForm(): void {
		$this->assertSame(expected: '', actual: $this->errors(record: $this->subsidyRecord()));
	}//end testTheImportAcceptsASavedForm()

	/**
	 * A record of another kind is refused.
	 *
	 * @return void
	 */
	public function testTheImportRefusesAnotherKind(): void {
		$record = $this->subsidyRecord();
		$record['kind'] = 'component-block';

		$this->assertNotSame(expected: '', actual: $this->errors(record: $record));
	}//end testTheImportRefusesAnotherKind()

	/**
	 * A form without its property definitions is refused.
	 *
	 * @return void
	 */
	public function testTheImportRefusesAFormWithoutItsDefinitions(): void {
		$record = $this->subsidyRecord();
		unset($record['schemaFragment']);

		$this->assertNotSame(expected: '', actual: $this->errors(record: $record));
	}//end testTheImportRefusesAFormWithoutItsDefinitions()

	/**
	 * The category is one of the four the app store filters templates by.
	 *
	 * @return void
	 */
	public function testTheCategoryIsTheTemplateCategory(): void {
		$template = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/openbuild_register.json'), true);
		$templateCategories = $this->findCategoryEnum(register: $template);
		$formSchemas = json_decode((string)file_get_contents($this->path), true)['components']['schemas'];
		$formCategories = $formSchemas['formTemplate']['properties']['category']['enum'];

		$this->assertSame(expected: $templateCategories, actual: $formCategories);
	}//end testTheCategoryIsTheTemplateCategory()

	/**
	 * The ApplicationTemplate category enum in the main register file.
	 *
	 * @param array<string, mixed> $register The decoded register.
	 *
	 * @return array<int, string>
	 */
	private function findCategoryEnum(array $register): array {
		$schemas = $register['components']['schemas'] ?? [];
		foreach ($schemas as $key => $schema) {
			if (strcasecmp((string)$key, 'ApplicationTemplate') === 0 || ($schema['slug'] ?? '') === 'application-template') {
				return $schema['properties']['category']['enum'] ?? [];
			}
		}

		return [];
	}//end findCategoryEnum()
}//end class
