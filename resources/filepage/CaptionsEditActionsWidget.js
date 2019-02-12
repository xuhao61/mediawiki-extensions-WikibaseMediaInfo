( function ( sd ) {

	'use strict';

	/**
	 * Widget containing other widgets to add a row, cancel, and save multi-lingual caption data
	 *
	 * For use with CaptionsPanel
	 *
	 * @constructor
	 * @param {Object} [config]
	 * @cfg {Object} appendToSelector Selector for element the widger should be appended to
	 * @param {object} captionsPanel CaptionsPanel object
	 */
	sd.CaptionsEditActionsWidget = function CaptionsEditActionsWidget( config, captionsPanel ) {

		var cancelAndPublishButtons = new sd.CancelPublishWidget( captionsPanel );

		var addCaptionButton = new OO.ui.ButtonWidget( {
			icon: 'add',
			label: mw.message( 'wikibasemediainfo-filepage-add-caption' ).text(),
			flags: 'progressive',
			classes: [ 'wbmi-entityview-addCaptionButton' ],
			framed: false
		} )
			.on( 'click', function () {
				captionsPanel.addNewEditableLanguageRow();
			} );

		var editActions = new OO.ui.Element( {
			content: [ addCaptionButton, cancelAndPublishButtons.$element ],
			classes: [ 'wbmi-entityview-editActions' ]
		} );

		this.hide = function () {
			editActions.$element.detach();
		};

		this.show = function () {
			$( config.appendToSelector ).append( editActions.$element );
		};

		this.disablePublish = function () {
			cancelAndPublishButtons.disablePublish();
		};

		this.enablePublish = function () {
			cancelAndPublishButtons.enablePublish( false );
		};

		this.setStateSending = function () {
			cancelAndPublishButtons.setStateSending();
			addCaptionButton.$element.hide();
		};

		this.setStateReady = function () {
			cancelAndPublishButtons.setStateReady();
			addCaptionButton.$element.show();
		};
	};

}( mw.mediaInfo.structuredData ) );