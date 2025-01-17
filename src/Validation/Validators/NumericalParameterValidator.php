<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\Validation\Validators;

/**
 * An insertable numerical parameter validator that also acts as an InsertableSuggester
 * @license GPL-2.0-or-later
 * @since 2020.03
 */
class NumericalParameterValidator extends InsertableRegexValidator {
	public function __construct() {
		parent::__construct( '/\$\d+/' );
	}
}

class_alias( NumericalParameterValidator::class, '\MediaWiki\Extensions\Translate\NumericalParameterValidator' );
