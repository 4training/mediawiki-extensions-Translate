<?php
/**
 * TTMServer - The Translate extension translation memory interface
 *
 * @file
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 * @defgroup TTMServer The Translate extension translation memory interface
 */

use MediaWiki\Extension\Translate\Services;

/**
 * Some general static methods for instantiating TTMServer and helpers.
 * @since 2012-01-28
 * Rewritten in 2012-06-27.
 * @ingroup TTMServer
 */
abstract class TTMServer {
	/** @var array */
	protected $config;

	/** @param array $config */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * @param array $config
	 * @return TTMServer|null
	 * @throws MWException
	 * @deprecated Use Services::getInstance()->getTtmServerFactory()->create()
	 */
	public static function factory( array $config ) {
		// Cannot call factory directly because we don't have the name.
		if ( isset( $config['class'] ) ) {
			$class = $config['class'];

			return new $class( $config );
		} elseif ( isset( $config['type'] ) ) {
			$type = $config['type'];
			switch ( $type ) {
				case 'ttmserver':
					return new DatabaseTTMServer( $config );
				case 'remote-ttmserver':
					return new RemoteTTMServer( $config );
				default:
					return null;
			}
		}

		throw new MWException( 'TTMServer with no type' );
	}

	/**
	 * Returns the primary server instance, useful for chaining.
	 * Primary instance is defined by $wgTranslateTranslationDefaultService
	 * which is a key to $wgTranslateTranslationServices.
	 * @return WritableTTMServer
	 * @deprecated Use Services::getInstance()->getTtmServerFactory()->getDefault()
	 */
	public static function primary() {
		return Services::getInstance()->getTtmServerFactory()->getDefault();
	}

	/**
	 * @param array[] $suggestions
	 * @return array[]
	 */
	public static function sortSuggestions( array $suggestions ) {
		usort( $suggestions, function ( $a, $b ) {
			return $b['quality'] <=> $a['quality'];
		} );

		return $suggestions;
	}

	/**
	 * PHP implementation of Levenshtein edit distance algorithm.
	 * Uses the native PHP implementation when possible for speed.
	 * The native levenshtein is limited to 255 bytes.
	 *
	 * @param string $str1
	 * @param string $str2
	 * @param int $length1
	 * @param int $length2
	 * @return int
	 */
	public static function levenshtein( $str1, $str2, $length1, $length2 ) {
		if ( $length1 === 0 ) {
			return $length2;
		}
		if ( $length2 === 0 ) {
			return $length1;
		}
		if ( $str1 === $str2 ) {
			return 0;
		}

		$bytelength1 = strlen( $str1 );
		$bytelength2 = strlen( $str2 );
		if ( $bytelength1 === $length1 && $bytelength1 <= 255
			&& $bytelength2 === $length2 && $bytelength2 <= 255
		) {
			return levenshtein( $str1, $str2 );
		}

		$prevRow = range( 0, $length2 );
		for ( $i = 0; $i < $length1; $i++ ) {
			$currentRow = [];
			$currentRow[0] = $i + 1;
			$c1 = mb_substr( $str1, $i, 1 );
			for ( $j = 0; $j < $length2; $j++ ) {
				$c2 = mb_substr( $str2, $j, 1 );
				$insertions = $prevRow[$j + 1] + 1;
				$deletions = $currentRow[$j] + 1;
				$substitutions = $prevRow[$j] + ( ( $c1 !== $c2 ) ? 1 : 0 );
				$currentRow[] = min( $insertions, $deletions, $substitutions );
			}
			$prevRow = $currentRow;
		}

		return $prevRow[$length2];
	}

	/**
	 * Hook: ArticleDeleteComplete
	 * @param WikiPage $wikipage
	 */
	public static function onDelete( WikiPage $wikipage ) {
		$handle = new MessageHandle( $wikipage->getTitle() );
		$job = TTMServerMessageUpdateJob::newJob( $handle, 'delete' );
		JobQueueGroup::singleton()->push( $job );
	}

	/**
	 * Called from TranslateEditAddons::onSave
	 * @param MessageHandle $handle
	 */
	public static function onChange( MessageHandle $handle ) {
		$job = TTMServerMessageUpdateJob::newJob( $handle, 'refresh' );
		JobQueueGroup::singleton()->push( $job );
	}

	/**
	 * @param MessageHandle $handle
	 * @param array $old
	 */
	public static function onGroupChange( MessageHandle $handle, $old ) {
		if ( $old === [] ) {
			// Don't bother for newly added messages
			return;
		}

		$job = TTMServerMessageUpdateJob::newJob( $handle, 'rebuild' );
		JobQueueGroup::singleton()->push( $job );
	}

	/** @return string[] */
	public function getMirrors() {
		global $wgTranslateTranslationServices;
		if ( isset( $this->config['mirrors'] ) ) {
			$mirrors = [];
			foreach ( $this->config['mirrors'] as $name ) {
				if ( !is_string( $name ) ) {
					throw new TTMServerException( "Invalid configuration set in " .
						"mirrors, expected an array of strings" );
				}
				if ( !isset( $wgTranslateTranslationServices[$name] ) ) {
					throw new TTMServerException( "Invalid configuration in " .
						"mirrors, unknown service $name" );
				}
				$mirrors[$name] = true;
			}
			return array_keys( $mirrors );
		}
		return [];
	}

	/** @return bool */
	public function isFrozen() {
		return false;
	}
}
