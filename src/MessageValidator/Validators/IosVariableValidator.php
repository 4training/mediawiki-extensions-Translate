<?php
/**
 * @file
 * @author Abijeet Patro
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extensions\Translate\MessageValidator\Validators;

// phpcs:disable Generic.Files.LineLength.TooLong
/**
 * An insertable IOS variable validator.
 * See: https://github.com/dcordero/Rubustrings/blob/61d477bffbb318ca3ffed9c2afc49ec301931d93/lib/rubustrings/action.rb#L91
 * @since 2020.03
 */
class IosVariableValidator extends InsertableRegexValidator {
	public function __construct() {
		parent::__construct(
			 "/%(?:([1-9]\d*)\$|\(([^\)]+)\))?(\+)?(0|\'[^$])?" .
			 "(-)?(\d+)?(?:\.(\d+))?(hh|ll|[hlLzjt])?([b-fiosuxX@])/"
		);
	}
}
