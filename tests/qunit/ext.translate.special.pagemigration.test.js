/**
 * Tests for ext.translate.special.pagemigration.js.
 *
 * @file
 * @license GPL-2.0+
 */

( function ( $, mw ) {
	'use strict';

	QUnit.module( 'ext.translate.special.pagemigration', QUnit.newMwEnvironment( {
		setup: function () {
			this.server = this.sandbox.useFakeServer();
		}
	} ) );

	QUnit.asyncTest( '-- Source units', function ( assert ) {
		QUnit.expect( 1 );
		var data = '{ "query": { "messagecollection": [ { "key": "key_",' +
			' "definition": "definition_", "title": "title_" }, { "key": "key_1",' +
			' "definition": "definition_1", "title": "title_1" } ] } }';

		mw.translate.getSourceUnits( 'Help:Special pages' ).done( function ( sourceUnits ) {
			assert.strictEqual( 1, sourceUnits.length, 'Source units retrieved' );
			QUnit.start();
		} );

		this.server.respond( function ( request ) {
			request.respond( 200, { 'Content-Type': 'application/json' }, data );
		} );
	} );

	QUnit.asyncTest( '-- Page does not exist', function ( assert ) {
		QUnit.expect( 1 );
		var data = '{ "query": { "pages": { "-1": { "missing": "" } } } }';

		mw.translate.getFuzzyTimestamp( 'ugagagagagaga/uga' ).fail( function ( timestamp ) {
			assert.strictEqual( undefined, timestamp, 'Page does not exist' );
			QUnit.start();
		} );

		this.server.respond( function ( request ) {
			request.respond( 200, { 'Content-Type': 'application/json' }, data );
		} );
	} );

	QUnit.asyncTest( '-- Fuzzy timestamp', function ( assert ) {
		QUnit.expect( 1 );
		var data = '{ "query": { "pages": { "19563": {"revisions": ' +
			'[ {"timestamp": "2014-02-18T20:59:58Z" }, { "timestamp": "t2" } ] } } } }';

		mw.translate.getFuzzyTimestamp( 'Help:Special pages/fr' ).done( function ( timestamp ) {
			assert.strictEqual( '2014-02-18T20:59:57.000Z', timestamp, 'Fuzzy timestamp retrieved' );
			QUnit.start();
		} );

		this.server.respond( function ( request ) {
			request.respond( 200, { 'Content-Type': 'application/json' }, data );
		} );
	} );

	QUnit.asyncTest( '-- Split translation page', function ( assert ) {
		QUnit.expect( 1 );
		var data = '{ "query": { "pages": { "19563": { "revisions": ' +
			'[ { "*": "unit1\\n\\nunit2\\n\\nunit3" } ] } } } }';

		mw.translate.splitTranslationPage( '2014-02-18T20:59:57.000Z', 'Help:Special pages/fr' )
			.done( function ( translationUnits ) {
				assert.strictEqual( 3, translationUnits.length, 'Translation page split into units' );
				QUnit.start();
			} );

		this.server.respond( function ( request ) {
			request.respond( 200, { 'Content-Type': 'application/json' }, data );
		} );
	} );

}( jQuery, mediaWiki ) );