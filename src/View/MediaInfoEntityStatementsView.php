<?php

namespace Wikibase\MediaInfo\View;

use DataValues\StringValue;
use Html;
use OOUI\HtmlSnippet;
use OOUI\PanelLayout;
use OOUI\Tag;
use OutputPage;
use ValueFormatters\FormatterOptions;
use ValueFormatters\ValueFormatter;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\SerializerFactory;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\Lib\OutputFormatSnakFormatterFactory;
use Wikibase\Lib\OutputFormatValueFormatterFactory;
use Wikibase\Lib\SnakFormatter;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Lib\Store\PropertyOrderProvider;
use Wikibase\MediaInfo\DataModel\MediaInfo;
use Wikibase\View\LocalizedTextProvider;

/**
 * Generates HTML to display the statements of a MediaInfo entity
 *
 * @license GPL-2.0-or-later
 */
class MediaInfoEntityStatementsView {

	private $propertyOrderProvider;
	private $textProvider;
	private $entityTitleLookup;
	private $defaultPropertyIds;
	private $snakFormatterFactory;
	private $valueFormatterFactory;
	private $serializerFactory;
	private $languageCode;
	private $showQualifiers;

	const EMPTY_DEFAULT_PROPERTY_PLACEHOLDER = 'EMPTY_DEFAULT_PROPERTY_PLACEHOLDER';

	/**
	 * MediaInfoEntityStatementsView constructor.
	 * @param PropertyOrderProvider $propertyOrderProvider
	 * @param LocalizedTextProvider $textProvider
	 * @param EntityTitleLookup $entityTitleLookup
	 * @param PropertyId[] $defaultPropertyIds Default values are displayed for these properties if
	 * 	we don't have values for them
	 * @param OutputFormatSnakFormatterFactory $snakFormatterFactory
	 * @param OutputFormatValueFormatterFactory $valueFormatterFactory
	 * @param SerializerFactory $serializerFactory
	 * @param string $languageCode
	 * @param bool $showQualifiers
	 */
	public function __construct(
		PropertyOrderProvider $propertyOrderProvider,
		LocalizedTextProvider $textProvider,
		EntityTitleLookup $entityTitleLookup,
		array $defaultPropertyIds,
		OutputFormatSnakFormatterFactory $snakFormatterFactory,
		OutputFormatValueFormatterFactory $valueFormatterFactory,
		SerializerFactory $serializerFactory,
		$languageCode,
		$showQualifiers
	) {
		OutputPage::setupOOUI();

		$this->propertyOrderProvider = $propertyOrderProvider;
		$this->textProvider = $textProvider;
		$this->entityTitleLookup = $entityTitleLookup;
		$this->defaultPropertyIds = $defaultPropertyIds;
		$this->snakFormatterFactory = $snakFormatterFactory;
		$this->valueFormatterFactory = $valueFormatterFactory;
		$this->serializerFactory = $serializerFactory;
		$this->languageCode = $languageCode;
		$this->showQualifiers = $showQualifiers;
	}

	/**
	 * @param MediaInfo $entity
	 * @return string
	 */
	public function getHtml( MediaInfo $entity ) {
		$statements = $this->statementsByPropertyId( $entity->getStatements() );

		/** @var PanelLayout[] $panels */
		$panels = [];
		foreach ( $statements as $propertyId => $statementArray ) {
			$panels[] = $this->getLayoutForProperty( $propertyId, $statementArray );
		}

		$html = '';
		foreach ( $panels as $panel ) {
			$html .= $panel->toString();
		}

		return $html;
	}

	public static function getHtmlContainerClass( $propertyIdString ) {
		return 'wbmi-entityview-statementsGroup-' . str_replace( ':', '_', $propertyIdString );
	}

	/**
	 * @param Statement $statement
	 * @return array
	 */
	private function getFormatValueCache( Statement $statement ) {
		$result = [];
		$formats = [];

		// gather a list of snaks that we'll want to pre-format
		$formats['text/html'][] = $statement->getMainSnak();
		$formats['text/plain'][] = $statement->getMainSnak();
		foreach ( $statement->getQualifiers() as $qualifier ) {
			$formats['text/plain'][] = $qualifier;
		}

		$snakSerializer = $this->serializerFactory->newSnakSerializer();

		foreach ( $formats as $format => $snaks ) {
			foreach ( $snaks as $snak ) {
				$serialized = $snakSerializer->serialize( $snak );
				if ( isset( $serialized['datavalue'] ) ) {
					$data = json_encode( $serialized[ 'datavalue' ] );
					$result[$data][$format][$this->languageCode] = $this->formatSnak(
						$snak,
						$format,
						$this->languageCode
					);
				}
			}
		}

		return $result;
	}

	/**
	 * @param Snak $snak
	 * @param string $format
	 * @param string $language
	 * @return string
	 */
	private function formatSnak( Snak $snak, $format, $language ) {
		$formatter = $this->snakFormatterFactory->getSnakFormatter(
			$format,
			new FormatterOptions( [ ValueFormatter::OPT_LANG => $language ] )
		);

		return $formatter->formatSnak( $snak );
	}

	/**
	 * @param string $propertyIdString
	 * @param Statement[] $statements
	 * @return PanelLayout
	 */
	private function getLayoutForProperty( $propertyIdString, array $statements ) {
		$statementSerializer = $this->serializerFactory->newStatementSerializer();

		$serializedStatements = [];
		$formatValueCache = [];

		$itemsGroupDiv = new Tag( 'div' );
		$itemsGroupDiv->addClasses( [ 'wbmi-content-items-group' ] );
		foreach ( $statements as $statement ) {
			$itemsGroupDiv->appendContent( $this->createStatementDiv( $statement ) );
			$serializedStatements[] = $statementSerializer->serialize( $statement );
			$formatValueCache += $this->getFormatValueCache( $statement );
		}

		$panel = new PanelLayout( [
			'classes' => [
				'wbmi-entityview-statementsGroup',
				self::getHtmlContainerClass( $propertyIdString )
			],
			'scrollable' => false,
			'padded' => false,
			'expanded' => false,
			'framed' => true,
			'content' => [
				$this->createPropertyHeader( $propertyIdString ),
				$itemsGroupDiv
			]
		] );
		$panel->setAttributes(
			[
				'data-statements' => json_encode( $serializedStatements ),
				'data-formatvalue' => json_encode( $formatValueCache ),
			]
		);
		return $panel;
	}

	private function createPropertyHeader( $propertyIdString ) {
		$propertyId = new PropertyId( $propertyIdString );
		$header = new Tag( 'div' );
		$header->appendContent(
			new HtmlSnippet(
				$this->decorateFormattedDataValue(
					$this->formatEntityId( $propertyId ),
					$propertyId
				)
			)
		);
		$header->addClasses( [ 'wbmi-entityview-statementsGroup-header' ] );
		return $header;
	}

	private function formatEntityId( EntityId $entityId, $format = SnakFormatter::FORMAT_PLAIN ) {
		$valueFormatter = $this->valueFormatterFactory->getValueFormatter(
			$format,
			new FormatterOptions()
		);
		return $valueFormatter->formatValue( new EntityIdValue( $entityId ) );
	}

	private function decorateFormattedDataValue( $formattedValue, EntityId $entityId = null ) {
		$links = '';
		$label = Html::rawElement(
			'h4',
			[ 'class' => 'wbmi-entity-label' ],
			$formattedValue
		);

		if ( !( is_null( $entityId ) ) ) {
			$linkClasses = [ 'wbmi-entity-link' ];
			$title = $this->entityTitleLookup->getTitleForId( $entityId );

			if ( $title !== null && !$title->exists() ) {
				$linkClasses[] = 'new';
			}

			// Decorate the link with an icon for the relevant repository
			if ( !empty( $entityId->getRepositoryName() ) ) {
				$linkClasses[] = 'wbmi-entity-link-foreign-repo-' . $entityId->getRepositoryName();
			}

			$links = Html::rawElement(
				'div',
				[ 'class' => 'wbmi-entity-label-extra' ],
				Html::element(
					'a',
					[
						'class' => implode( ' ', $linkClasses ),
						'href' => $title->isLocal() ? $title->getLocalURL() : $title->getFullURL(),
						'target' => '_blank',
					],
					$entityId->getLocalPart()
				)
			);
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'wbmi-entity-title' ],
			$label . $links
		);
	}

	private function createStatementDiv( Statement $statement ) {
		$div = new Tag( 'div' );
		$div->appendContent( $this->innerStatementDiv( $statement ) );
		$div->addClasses( [ 'wbmi-item', 'wbmi-item-read' ] );
		return $div;
	}

	private function innerStatementDiv( Statement $statement ) {
		$mainSnak = $statement->getMainSnak();

		$statementDiv = new Tag( 'div' );
		$statementDiv->addClasses( [ 'wbmi-item-container' ] );

		$guid = $statement->getGuid();
		if ( !is_null( $guid ) ) {
			$statementDiv->setAttributes( [ 'data-guid' => $guid ] );
		}

		$mainSnakDiv = new Tag( 'div' );
		$mainSnakValueEntityId = null;
		if (
			( $mainSnak instanceof PropertyValueSnak ) &&
			( $mainSnak->getDataValue() instanceof EntityIdValue )
		) {
			$mainSnakValueEntityId = $mainSnak->getDataValue()->getEntityId();
		}
		$mainSnakDiv->appendContent(
			new HtmlSnippet(
				$this->decorateFormattedDataValue(
					$this->formatSnakValue( $mainSnak ),
					$mainSnakValueEntityId
				)
			)
		);

		$statementDiv->appendContent( $mainSnakDiv );
		if ( $this->showQualifiers ) {
			$qualifiers = $statement->getQualifiers();
			if ( count( $qualifiers ) > 0 ) {
				$statementDiv->appendContent( $this->createQualifiersDiv( $qualifiers ) );
			}
		}
		return $statementDiv;
	}

	private function createQualifiersDiv( SnakList $snakList ) {
		$container = new Tag( 'div' );
		$container->addClasses( [ 'wbmi-item-content' ] );

		$qualifierHtmlByPropertyId = [];
		$propertyOrder = $this->propertyOrderProvider->getPropertyOrder();
		if ( is_null( $propertyOrder ) ) {
			$snakList->orderByProperty();
		} else {
			$propertyIds = array_flip( $propertyOrder );
			ksort( $propertyIds );
			$snakList->orderByProperty(
				array_values( $propertyIds )
			);
		}
		/** @var Snak $snak */
		foreach ( $snakList as $snak ) {
			$qualifierHtmlByPropertyId[$snak->getPropertyId()->getSerialization()][] =
				$this->formatSnakValue( $snak, SnakFormatter::FORMAT_PLAIN );
		}

		$qualifierDivs = [];
		foreach ( $qualifierHtmlByPropertyId as $propertyIdString => $qualifierHtmlArray ) {
			$content = $this->formatEntityId( new PropertyId( $propertyIdString ) );
			$content .= $this->textProvider->get( 'colon-separator' );
			$content .= implode(
				$this->textProvider->get( 'comma-separator' ),
				$qualifierHtmlArray
			);
			$qualifierValueDiv = new Tag( 'div' );
			$qualifierValueDiv->addClasses( [ 'wbmi-qualifier-value' ] );
			$qualifierValueDiv->appendContent( $content );

			$qualifierDiv = new Tag( 'div' );
			$qualifierDiv->addClasses( [ 'wbmi-qualifier' ] );
			$qualifierDiv->appendContent( $qualifierValueDiv );

			$qualifierDivs[] = $qualifierDiv;
		}

		$innerDiv = new Tag( 'div' );
		$innerDiv->addClasses( [ 'wbmi-item-content-group' ] );
		$innerDiv->appendContent( $qualifierDivs );
		$container->appendContent(
			$innerDiv
		);
		return $container;
	}

	/**
	 * @param Snak $snak
	 * @param string $format
	 * @return HtmlSnippet
	 * @throws \OOUI\Exception
	 */
	private function formatSnakValue( Snak $snak, $format = SnakFormatter::FORMAT_PLAIN ) {
		// First handle the special case where a dummy snak has been added by default because
		// there are no values at all for a property, but we want to display the property
		// anyway (e.g. "depicts" is always displayed)
		if ( $snak instanceof PropertyValueSnak ) {
			$value = $snak->getDataValue();
			if ( !( $value instanceof EntityIdValue ) ) {
				$content = $value->getValue();
				if ( $content === self::EMPTY_DEFAULT_PROPERTY_PLACEHOLDER ) {
					return new HtmlSnippet(
						Html::element(
							'em',
							[ 'class' => 'wbmi-statement-empty-default-property' ],
							$this->textProvider->get(
								'wikibasemediainfo-filepage-statement-no-data'
							)
						)
					);
				}
			}
		}

		$formatter = $this->snakFormatterFactory->getSnakFormatter(
			$format,
			new FormatterOptions()
		);
		return new HtmlSnippet( $formatter->formatSnak( $snak ) );
	}

	/**
	 * Gather statements into an array with property ids (ordered by $this->propertyOrderProvider)
	 * as keys, and arrays of statements pertaining to those property ids (ordered by rank) as
	 * values
	 *
	 * @param StatementList[] $statementList
	 * @return array
	 */
	private function statementsByPropertyId( StatementList $statementList ) {

		$statementsByProperty = $this->getOrderedStatementsByProperty( $statementList );

		$propertyOrder = $this->propertyOrderProvider->getPropertyOrder();

		if ( !$propertyOrder ) {
			return $statementsByProperty;
		}

		$ordered = [];
		$unordered = [];

		foreach ( $statementsByProperty as $propertyId => $statements ) {
			if ( isset( $propertyOrder[$propertyId] ) ) {
				$ordered[$propertyOrder[$propertyId]] = $statements;
			} else {
				$unordered[] = $statements;
			}
		}

		ksort( $ordered );
		$orderedButNotIndexed = array_merge( $ordered, $unordered );
		$orderedAndIndexed = [];
		foreach ( $orderedButNotIndexed  as $statementArray ) {
			$orderedAndIndexed[$statementArray[0]->getPropertyId()->getSerialization()] =
				$statementArray;
		}
		return $orderedAndIndexed;
	}

	/**
	 * Returns array with property ids as keys and arrays of statements as elements. The arrays
	 * of statements are ordered by rank.
	 *
	 * @param StatementList $statementList
	 * @return array
	 */
	private function getOrderedStatementsByProperty( StatementList $statementList ) {
		$statementsByPropertyAndRank = [];
		foreach ( $statementList as $statement ) {
			$propertyId = $statement->getPropertyId()->getSerialization();
			if ( !isset( $statementsByPropertyAndRank[$propertyId] ) ) {
				$statementsByPropertyAndRank[$propertyId] = [
					Statement::RANK_PREFERRED => [],
					Statement::RANK_NORMAL => [],
					Statement::RANK_DEPRECATED => [],
				];
			}
			$rank = $statement->getRank();
			$statementsByPropertyAndRank[$propertyId][$rank][] = $statement;
		}

		$statementsByProperty = [];
		foreach ( $statementsByPropertyAndRank as $propertyId => $array ) {
			$statementsByProperty[$propertyId] = array_merge(
				$array[Statement::RANK_PREFERRED],
				$array[Statement::RANK_NORMAL],
				$array[Statement::RANK_DEPRECATED]
			);
		}

		$statementsByProperty = $this->addDefaultStatements( $statementsByProperty );

		return $statementsByProperty;
	}

	private function addDefaultStatements( $statementsByProperty ) {
		foreach ( $this->defaultPropertyIds as $propertyId ) {
			if ( !isset( $statementsByProperty[ $propertyId->getSerialization() ] ) ) {
				$statementsByProperty[ $propertyId->getSerialization() ] =
					[
						new Statement(
							new PropertyValueSnak(
								$propertyId,
								new StringValue( self::EMPTY_DEFAULT_PROPERTY_PLACEHOLDER )
							)
						)
					];
			}
		}
		return $statementsByProperty;
	}

}
