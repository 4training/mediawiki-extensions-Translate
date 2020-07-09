<?php
declare( strict_types = 1 );

use MediaWiki\Extensions\Translate\Utilities\ParsingPlaceholderFactory;

/**
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 * @covers \MediaWiki\Extensions\Translate\Utilities\ParsingPlaceholderFactory
 */
class ParsingPlaceholderFactoryTest extends MediaWikiUnitTestCase {
	public function testMake() {
		$obj = new ParsingPlaceholderFactory();
		$this->assertRegExp( '/[a-zA-Z0-9\x7f]/', $obj->make() );
	}
}
