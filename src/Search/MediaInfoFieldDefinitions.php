<?php

namespace Wikibase\MediaInfo\Search;

use Wikibase\Repo\Search\Elastic\Fields\FieldDefinitions;
use Wikibase\Repo\Search\Elastic\Fields\StatementCountField;
use Wikibase\Repo\Search\Elastic\Fields\WikibaseIndexField;

/**
 * @license GPL-2.0-or-later
 * @author Katie Filbert < aude.wiki@gmail.com >
 */
class MediaInfoFieldDefinitions implements FieldDefinitions {

	/**
	 * @var FieldDefinitions
	 */
	private $labelsProviderFieldDefinitions;

	/**
	 * @var FieldDefinitions
	 */
	private $descriptionsProviderFieldDefinitions;

	/**
	 * @var FieldDefinitions
	 */
	private $statementProviderDefinitions;

	public function __construct(
		FieldDefinitions $labelsProviderFieldDefinitions,
		FieldDefinitions $descriptionsProviderFieldDefinitions,
		FieldDefinitions $statementProviderDefinitions
	) {
		$this->labelsProviderFieldDefinitions = $labelsProviderFieldDefinitions;
		$this->descriptionsProviderFieldDefinitions = $descriptionsProviderFieldDefinitions;
		$this->statementProviderDefinitions = $statementProviderDefinitions;
	}

	/**
	 * @see FieldDefinitions::getFields
	 *
	 * @return WikibaseIndexField[]
	 */
	public function getFields() {
		$fields = array_merge(
			$this->labelsProviderFieldDefinitions->getFields(),
			$this->descriptionsProviderFieldDefinitions->getFields(),
			$this->statementProviderDefinitions->getFields()
		);

		$fields['statement_count'] = new StatementCountField();

		return $fields;
	}

}
