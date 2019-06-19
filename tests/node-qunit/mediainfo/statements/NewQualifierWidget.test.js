var sinon = require( 'sinon' ),
	pathToWidget = '../../../../resources/statements/NewQualifierWidget.js',
	helpers = require( '../../support/helpers.js' ),
	sandbox;

QUnit.module( 'NewQualifierWidget', {
	beforeEach: function () {
		sandbox = sinon.createSandbox();

		// Set up global MW and wikibase objects
		global.mw = helpers.createMediaWikiEnv();
		global.dataValues = helpers.createDataValuesEnv();
		global.wikibase = helpers.createWikibaseEnv();

		// make sure that mediainfo modules (usually exposed via RL)
		// can be required
		helpers.registerModules();
	},

	afterEach: function () {
		delete require.cache[ require.resolve( 'jquery' ) ];
		sandbox.reset();
		helpers.deregisterModules();
	}
}, function () {
	QUnit.test( 'Valid data roundtrip', function ( assert ) {
		var QualifierWidget = require( pathToWidget ),
			widget = new QualifierWidget(),
			data = new wikibase.datamodel.PropertyValueSnak(
				'P1',
				new wikibase.datamodel.EntityId( 'Q1' )
			);

		widget.setData( data );
		assert.ok( widget.getData() );
		assert.strictEqual( data.equals( widget.getData() ), true );
	} );

	QUnit.test( 'setData() sets property ID in the PropertyInput widget', function ( assert ) {
		var QualifierWidget = require( pathToWidget ),
			widget = new QualifierWidget(),
			data = new wikibase.datamodel.PropertyValueSnak(
				'P1',
				new wikibase.datamodel.EntityId( 'Q1' )
			);

		widget.setData( data );
		assert.strictEqual( widget.propertyInput.getData().id, data.getPropertyId() );
	} );

	QUnit.test( 'setData() sets value data in the valueInput widget', function ( assert ) {
		var QualifierWidget = require( pathToWidget ),
			widget = new QualifierWidget(),
			data = new wikibase.datamodel.PropertyValueSnak(
				'P1',
				new wikibase.datamodel.EntityId( 'Q1' )
			);

		widget.setData( data );
		assert.strictEqual( widget.valueInput.getData().equals( data.getValue() ), true );
	} );

	QUnit.test( 'property labels are available after API calls complete', function ( assert ) {
		var QualifierWidget = require( pathToWidget ),
			widget = new QualifierWidget(),
			data = new wikibase.datamodel.PropertyValueSnak(
				'P1',
				new wikibase.datamodel.EntityId( 'Q1' )
			),
			propertyLabel = 'some property',
			valueLabel = 'some value',
			formatPropertyStub = sinon.stub( widget, 'formatProperty' ),
			formatValueStub = sinon.stub( widget, 'formatValue' ),
			done = assert.async();

		formatPropertyStub.returns( $.Deferred().resolve( propertyLabel ) );
		formatValueStub.returns( $.Deferred().resolve( valueLabel ) );
		widget.setData( data );

		setTimeout( function () {
			assert.strictEqual( formatPropertyStub.called, true );
			assert.strictEqual( widget.propertyInput.getData().label, propertyLabel );
			done();
		}, 200 );
	} );
} );