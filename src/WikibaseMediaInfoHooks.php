<?php

namespace Wikibase\MediaInfo;

use AbstractContent;
use CirrusSearch\Connection;
use CirrusSearch\Search\CirrusIndexField;
use Content;
use Elastica\Document;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use OutputPage;
use ParserOutput;
use Title;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Services\EntityId\EntityIdComposer;
use Wikibase\LanguageFallbackChainFactory;
use Wikibase\Lib\Store\EntityByLinkedTitleLookup;
use Wikibase\Lib\Store\EntityInfo;
use Wikibase\Lib\UserLanguageLookup;
use Wikibase\MediaInfo\Content\MediaInfoContent;
use Wikibase\MediaInfo\Content\MediaInfoHandler;
use Wikibase\MediaInfo\DataModel\MediaInfo;
use Wikibase\MediaInfo\Services\MediaInfoByLinkedTitleLookup;
use Wikibase\Repo\BabelUserLanguageLookup;
use Wikibase\Repo\MediaWikiLocalizedTextProvider;
use Wikibase\Repo\Store\EntityTitleStoreLookup;
use Wikibase\Repo\WikibaseRepo;
use WikiPage;

/**
 * MediaWiki hook handlers for the Wikibase MediaInfo extension.
 *
 * @license GPL-2.0-or-later
 * @author Bene* < benestar.wikimedia@gmail.com >
 */
class WikibaseMediaInfoHooks {

	/**
	 * @var EntityIdComposer
	 */
	private $entityIdComposer;

	/**
	 * @var EntityTitleStoreLookup
	 */
	private $entityTitleStoreLookup;

	private static function newFromGlobalState() {
		$wikibaseRepo = WikibaseRepo::getDefaultInstance();

		return new self(
			$wikibaseRepo->getEntityIdComposer(),
			$wikibaseRepo->getEntityTitleLookup()
		);
	}

	/**
	 * WikibaseMediaInfoHooks constructor.
	 * @param EntityIdComposer $entityIdComposer
	 * @param EntityTitleStoreLookup $entityTitleStoreLookup
	 * @codeCoverageIgnore
	 */
	public function __construct(
		EntityIdComposer $entityIdComposer,
		EntityTitleStoreLookup $entityTitleStoreLookup
	) {
		$this->entityIdComposer = $entityIdComposer;
		$this->entityTitleStoreLookup = $entityTitleStoreLookup;
	}

	public static function onExtensionLoad() {
		// Tell MediaWiki where to put our entity content.
		MediaWikiServices::getInstance()->getSlotRoleRegistry()->defineRole(
			'mediainfo',
			function () {
				return new Services\WikibaseMediaInfoSlotRoleHandler();
			}
		);
	}

	/**
	 * Hook to register the MediaInfo entity namespaces for EntityNamespaceLookup.
	 *
	 * @param array $entityNamespacesSetting
	 */
	public static function onWikibaseRepoEntityNamespaces( &$entityNamespacesSetting ) {
		// Exit if the extension is disabled.
		if ( !MediaWikiServices::getInstance()->getMainConfig()->get( 'MediaInfoEnable' ) ) {
			return;
		}

		// Tell Wikibase where to put our entity content.
		$entityNamespacesSetting[ MediaInfo::ENTITY_TYPE ] = NS_FILE . '/' . MediaInfo::ENTITY_TYPE;
	}

	/**
	 * Adds the definition of the media info entity type to the definitions array Wikibase uses.
	 *
	 * @see WikibaseMediaInfo.entitytypes.php
	 *
	 * @note: This is bootstrap code, it is executed for EVERY request. Avoid instantiating
	 * objects or loading classes here!
	 *
	 * @param array[] $entityTypeDefinitions
	 */
	public static function onWikibaseEntityTypes( array &$entityTypeDefinitions ) {
		// Exit if the extension is disabled.
		if ( !MediaWikiServices::getInstance()->getMainConfig()->get( 'MediaInfoEnable' ) ) {
			return;
		}

		$entityTypeDefinitions = array_merge(
			$entityTypeDefinitions,
			require __DIR__ . '/../WikibaseMediaInfo.entitytypes.php'
		);
	}

	/**
	 * The placeholder mw:slotheader is replaced by default with the name of the slot
	 *
	 * Replace it with a different placeholder so we can replace it with a message later
	 * on in onBeforePageDisplay() - can't replace it here because RequestContext (and therefore
	 * the language) is not available
	 *
	 * Won't be necessary when T205444 is done
	 *
	 * @see https://phabricator.wikimedia.org/T205444
	 * @see onBeforePageDisplay()
	 *
	 * @param ParserOutput $parserOutput
	 * @param $text
	 * @param array $options
	 */
	public static function onParserOutputPostCacheTransform(
		ParserOutput $parserOutput,
		&$text,
		array $options
	) {
		// Exit if the extension is disabled.
		if ( !MediaWikiServices::getInstance()->getMainConfig()->get( 'MediaInfoEnable' ) ) {
			return;
		}

		$text = preg_replace(
			'#<mw:slotheader>(.*?)</mw:slotheader>#',
			self::getMediaInfoSlotHeaderPlaceholder(),
			$text
		);
	}

	private static function getMediaInfoSlotHeaderPlaceholder() {
		return '<mw:mediainfoslotheader />';
	}

	/**
	 * Replace mediainfo-specific placeholders (if any), move structured data, add data and modules
	 *
	 * @param \OutputPage $out
	 * @param \Skin $skin
	 */
	public static function onBeforePageDisplay( $out, $skin ) {
		// Exit if the extension is disabled.
		if ( !MediaWikiServices::getInstance()->getMainConfig()->get( 'MediaInfoEnable' ) ) {
			return;
		}

		$allLanguages = \Language::fetchLanguageNames();
		$termsLanguages = WikibaseRepo::getDefaultInstance()->getTermsLanguages()->getLanguages();

		self::newFromGlobalState()->doBeforePageDisplay(
			$out,
			array_intersect_key(
				$allLanguages,
				array_flip( $termsLanguages )
			),
			new BabelUserLanguageLookup()
		);
	}

	/**
	 * @param \OutputPage $out
	 * @param string[] $termsLanguages Array with language codes as keys and autonyms as values
	 * @param UserLanguageLookup $userLanguageLookup
	 */
	public function doBeforePageDisplay(
		$out,
		array $termsLanguages,
		UserLanguageLookup $userLanguageLookup
	) {
		$out->preventClickjacking();
		$imgTitle = $out->getTitle();
		// Don't load …
		if (
			// … for files that don't exist
			!$imgTitle->exists() ||
			// … for pages that aren't files
			!$imgTitle->inNamespace( NS_FILE ) ||
			// … for page views that aren't reads
			\Action::getActionName( $out->getContext() ) !== 'view'
		) {
			return;
		}

		$out = $this->moveMediaInfoData( $out );

		$pageId = $imgTitle->getArticleID();
		$entityId = $this->entityIdFromPageId( $pageId );

		$out->addJsConfigVars( [
			'wbUserSpecifiedLanguages' => $userLanguageLookup->getAllUserLanguages(
				$out->getUser()
			),
			'wbCurrentRevision' => $out->getWikiPage()->getRevision()->getId(),
			'wbEntityId' => $entityId->getSerialization(),
			'wbTermsLanguages' => $termsLanguages,
			'wbRepoApiUrl' => wfScript( 'api' ),
			'maxCaptionLength' => self::getMaxCaptionLength(),
		] );

		$out->addModuleStyles( [
			'wikibase.mediainfo.filepagestyles',
		] );

		$out->addModules( 'wikibase.mediainfo.filePageDisplay' );
	}

	/**
	 * @param OutputPage $out
	 * @return OutputPage $out
	 */
	private function moveMediaInfoData( OutputPage $out ) {
		$html = $out->getHTML();
		$out->clearHTML();
		$html = $this->moveCaptions( $html, $out );
		$html = $this->moveStructuredDataHeader( $html, $out );
		$out->addHTML( $html );
		return $out;
	}

	/**
	 * Move the structured data multi-lingual captions to the place we want them
	 *
	 * If there are no captions to be displayed, inject an empty MediaInfoView
	 *
	 * @param $text
	 * @param OutputPage $out
	 * @return string
	 */
	private function moveCaptions( $text, $out ) {
		if ( preg_match(
			'/<mw:mediainfoView>(.*)<\/mw:mediainfoView>/is',
			$text,
			$matches
		) ) {
			$captionsHtml = $matches[1];
			$text = str_replace( $matches[0], '', $text );
		} else {
			$factory = new LanguageFallbackChainFactory();
			$emptyMediaInfo = new MediaInfo();
			$view = WikibaseRepo::getDefaultInstance()->getEntityViewFactory()->newEntityView(
				$out->getLanguage(),
				$factory->newFromLanguage( $out->getLanguage() ),
				$emptyMediaInfo,
				new EntityInfo( [] )
			);
			$captionsHtml = $view->getContent( $emptyMediaInfo )->getHtml();
		}

		return preg_replace(
			'/<div class="mw-parser-output">/',
			'<div class="mw-parser-output">' . $captionsHtml,
			$text
		);
	}

	/**
	 * Move the structured data header to the place we want it
	 *
	 * Also replace the mediainfo-specific placeholder added in onParserOutputPostCacheTransform
	 *
	 * @see onParserOutputPostCacheTransform()
	 *
	 * The placeholder should no longer be necessary when T205444 is done
	 * @see https://phabricator.wikimedia.org/T205444
	 *
	 * @param $text
	 * @param OutputPage $out
	 * @return string
	 */
	private function moveStructuredDataHeader( $text, $out ) {

		// First do the move
		if (
			preg_match(
				'#<h1\b[^>]*\bclass=(\'|")mw-slot-header\\1[^>]*>' .
				$this->getMediaInfoSlotHeaderPlaceholder() . '</h1>#iU',
				$text,
				$matches
			)
		) {
			// Take from the old place
			$text = str_replace( $matches[0], '', $text );

			// Add at the new place
			$text = preg_replace(
				'/<div class="mw-parser-output">/',
				'<div class="mw-parser-output">' . $matches[0],
				$text
			);
		}

		// Now replace the placeholder
		$textProvider = new MediaWikiLocalizedTextProvider( $out->getLanguage() );
		$text = str_replace(
			$this->getMediaInfoSlotHeaderPlaceholder(),
			htmlspecialchars(
				$textProvider->get( 'wikibasemediainfo-filepage-structured-data-heading' )
			),
			$text
		);

		return $text;
	}

	private static function getMaxCaptionLength() {
		global $wgWBRepoSettings;
		return $wgWBRepoSettings['string-limits']['multilang']['length'];
	}

	/**
	 * The ID for a MediaInfo item is the same as the ID of its associated File page, with an
	 * 'M' prepended - this is encapsulated by EntityIdComposer::composeEntityId()
	 *
	 * @param int $pageId
	 * @return EntityId
	 */
	private function entityIdFromPageId( $pageId ) {
		return $this->entityIdComposer->composeEntityId(
			'',
			MediaInfo::ENTITY_TYPE,
			$pageId
		);
	}

	public static function onGetEntityByLinkedTitleLookup( EntityByLinkedTitleLookup &$lookup ) {
		$lookup = new MediaInfoByLinkedTitleLookup( $lookup );
	}

	/**
	 * Note that this is a workaround until all slots are passed automatically to CirrusSearch
	 *
	 * @see https://phabricator.wikimedia.org/T190066
	 */
	public static function onCirrusSearchBuildDocumentParse(
		Document $document,
		Title $title,
		AbstractContent $contentObject,
		ParserOutput $parserOutput,
		Connection $connection
	) {
		// Exit if the extension is disabled.
		if ( !MediaWikiServices::getInstance()->getMainConfig()->get( 'MediaInfoEnable' ) ) {
			return;
		}
		self::newFromGlobalState()->doCirrusSearchBuildDocumentParse(
			$document,
			WikiPage::factory( $title ),
			\ContentHandler::getForModelID( MediaInfoContent::CONTENT_MODEL_ID )
		);
	}

	public function doCirrusSearchBuildDocumentParse(
		Document $document,
		WikiPage $page,
		MediaInfoHandler $handler
	) {
		$revisionRecord = $page->getRevisionRecord();
		if (
			!is_null( $revisionRecord ) && $revisionRecord->hasSlot( MediaInfo::ENTITY_TYPE )
		) {
			/** @var SlotRecord $mediaInfoSlot */
			$mediaInfoSlot = $page->getRevisionRecord()->getSlot( MediaInfo::ENTITY_TYPE );

			$engine = new \CirrusSearch();
			$fieldDefinitions = $handler->getFieldsForSearchIndex( $engine );
			$slotData = $handler->getSlotDataForSearchIndex(
				$mediaInfoSlot->getContent()
			);
			foreach ( $slotData as $field => $fieldData ) {
				$document->set( $field, $fieldData );
				if ( isset( $fieldDefinitions[$field] ) ) {
					$hints = $fieldDefinitions[$field]->getEngineHints( $engine );
					CirrusIndexField::addIndexingHints( $document, $field, $hints );
				}
			}
		}
	}

}
