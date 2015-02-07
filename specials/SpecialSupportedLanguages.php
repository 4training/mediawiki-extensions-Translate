<?php
/**
 * Contains logic for special page Special:SupportedLanguages
 *
 * @file
 * @author Niklas Laxström
 * @author Siebrand Mazeland
 * @copyright Copyright © 2012-2013, Niklas Laxström, Siebrand Mazeland
 * @license GPL-2.0+
 */

/**
 * Implements special page Special:SupportedLanguages. The wiki administrator
 * must define NS_PORTAL, otherwise this page does not work. This page displays
 * a list of language portals for all portals corresponding with a language
 * code defined for MediaWiki and a subpage called "translators". The subpage
 * "translators" must contain the template [[:{{ns:template}}:User|User]],
 * taking a user name as parameter.
 *
 * @ingroup SpecialPage TranslateSpecialPage Stats
 */
class SpecialSupportedLanguages extends TranslateSpecialPage {
	/// Whether to skip and regenerate caches
	protected $purge = false;

	/// Cutoff time for inactivity in days
	protected $period = 180;

	public function __construct() {
		parent::__construct( 'SupportedLanguages' );
	}

	public function execute( $par ) {
		$out = $this->getOutput();
		$lang = $this->getLanguage();

		$this->purge = $this->getRequest()->getVal( 'action' ) === 'purge';

		$this->setHeaders();
		$out->addModules( 'ext.translate.special.supportedlanguages' );

		// Do not add html content to OutputPage before this block of code!
		$cache = wfGetCache( CACHE_ANYTHING );
		$cachekey = wfMemcKey( 'translate-supportedlanguages', $lang->getCode() );
		if ( $this->purge ) {
			$cache->delete( $cachekey );
		} else {
			$data = $cache->get( $cachekey );
			if ( is_string( $data ) ) {
				TranslateUtils::addSpecialHelpLink(
					$out,
					'Help:Extension:Translate/Statistics_and_reporting#List_of_languages_and_translators'
				);
				$out->addHtml( $data );

				return;
			}
		}

		TranslateUtils::addSpecialHelpLink(
			$out,
			'Help:Extension:Translate/Statistics_and_reporting#List_of_languages_and_translators'
		);

		$this->outputHeader();
		$dbr = wfGetDB( DB_SLAVE );
		if ( $dbr->getType() === 'sqlite' ) {
			$out->addWikiText( '<div class=errorbox>SQLite is not supported.</div>' );

			return;
		}

		$out->addWikiMsg( 'supportedlanguages-colorlegend', $this->getColorLegend() );
		$out->addWikiMsg( 'supportedlanguages-localsummary' );

		// Check if CLDR extension has been installed.
		$cldrInstalled = class_exists( 'LanguageNames' );
		$locals = Language::fetchLanguageNames( $lang->getCode(), 'all' );
		$natives = Language::fetchLanguageNames( null, 'all' );

		$this->outputLanguageCloud( $natives );

		if ( !defined( 'NS_PORTAL' ) ) {
			$users = $this->fetchTranslatorsAuto();
		} else {
			$users = $this->fetchTranslatorsPortal( $natives );
		}

		if ( $users === array() ) {
			return;
		}

		$this->preQueryUsers( $users );

		$usernames = array_keys( call_user_func_array( 'array_merge', array_values( $users ) ) );
		$userStats = $this->getUserStats( $usernames );

		// Information to be used inside the foreach loop.
		$linkInfo['rc']['title'] = SpecialPage::getTitleFor( 'Recentchanges' );
		$linkInfo['rc']['msg'] = $this->msg( 'supportedlanguages-recenttranslations' )->escaped();
		$linkInfo['stats']['title'] = SpecialPage::getTitleFor( 'LanguageStats' );
		$linkInfo['stats']['msg'] = $this->msg( 'languagestats' )->escaped();

		foreach ( array_keys( $natives ) as $code ) {
			if ( !isset( $users[$code] ) ) {
				continue;
			}

			// If CLDR is installed, add localised header and link title.
			if ( $cldrInstalled ) {
				$headerText = $this->msg( 'supportedlanguages-portallink' )
					->params( $code, $locals[$code], $natives[$code] )->escaped();
			} else {
				// No CLDR, so a less localised header and link title.
				$headerText = $this->msg( 'supportedlanguages-portallink-nocldr' )
					->params( $code, $natives[$code] )->escaped();
			}

			$headerText = htmlspecialchars( $headerText );

			$out->addHtml( Html::openElement( 'h2', array( 'id' => $code ) ) );
			if ( defined( 'NS_PORTAL' ) ) {
				$portalTitle = Title::makeTitleSafe( NS_PORTAL, $code );
				$out->addHtml( Linker::linkKnown( $portalTitle, $headerText ) );
			} else {
				$out->addHtml( $headerText );
			}

			$out->addHTML( "</h2>" );

			// Add useful links for language stats and recent changes for the language.
			$links = array();
			$links[] = Linker::link(
				$linkInfo['stats']['title'],
				$linkInfo['stats']['msg'],
				array(),
				array(
					'code' => $code,
					'suppresscomplete' => '1'
				),
				array( 'known', 'noclasses' )
			);
			$links[] = Linker::link(
				$linkInfo['rc']['title'],
				$linkInfo['rc']['msg'],
				array(),
				array(
					'translations' => 'only',
					'trailer' => "/" . $code
				),
				array( 'known', 'noclasses' )
			);
			$linkList = $lang->listToText( $links );

			$out->addHTML( "<p>" . $linkList . "</p>\n" );
			$this->makeUserList( $users[$code], $userStats );
		}

		$out->addHtml( Html::element( 'hr' ) );
		$out->addWikiMsg( 'supportedlanguages-count', $lang->formatNum( count( $users ) ) );

		$cache->set( $cachekey, $out->getHTML(), 3600 );
	}

	protected function languageCloud() {
		global $wgTranslateMessageNamespaces;

		$cache = wfGetCache( CACHE_ANYTHING );
		$cachekey = wfMemcKey( 'translate-supportedlanguages-language-cloud' );
		if ( $this->purge ) {
			$cache->delete( $cachekey );
		} else {
			$data = $cache->get( $cachekey );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		$dbr = wfGetDB( DB_SLAVE );
		$tables = array( 'recentchanges' );
		$fields = array( 'substring_index(rc_title, \'/\', -1) as lang', 'count(*) as count' );
		$timestamp = $dbr->timestamp( TS_DB, wfTimeStamp( TS_UNIX ) - 60 * 60 * 24 * $this->period );
		$conds = array(
			'rc_title' . $dbr->buildLike( $dbr->anyString(), '/', $dbr->anyString() ),
			'rc_namespace' => $wgTranslateMessageNamespaces,
			'rc_timestamp > ' . $timestamp,
		);
		$options = array( 'GROUP BY' => 'lang', 'HAVING' => 'count > 20' );

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $options );

		$data = array();
		foreach ( $res as $row ) {
			$data[$row->lang] = $row->count;
		}

		$cache->set( $cachekey, $data, 3600 );

		return $data;
	}

	protected function fetchTranslatorsAuto() {
		global $wgTranslateMessageNamespaces;

		$cache = wfGetCache( CACHE_ANYTHING );
		$cachekey = wfMemcKey( 'translate-supportedlanguages-translator-list' );
		if ( $this->purge ) {
			$cache->delete( $cachekey );
		} else {
			$data = $cache->get( $cachekey );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		$dbr = wfGetDB( DB_SLAVE );
		$tables = array( 'page', 'revision' );
		$fields = array(
			'rev_user_text',
			'substring_index(page_title, \'/\', -1) as lang',
			'count(page_id) as count'
		);
		$conds = array(
			'page_title' . $dbr->buildLike( $dbr->anyString(), '/', $dbr->anyString() ),
			'page_namespace' => $wgTranslateMessageNamespaces,
			'page_id=rev_page',
		);
		$options = array( 'GROUP BY' => 'rev_user_text, lang' );

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $options );

		$data = array();
		foreach ( $res as $row ) {
			$data[$row->lang][$row->rev_user_text] = $row->count;
		}

		$cache->set( $cachekey, $data, 3600 );

		return $data;
	}

	public function fetchTranslatorsPortal( $natives ) {
		$titles = array();
		foreach ( $natives as $code => $_ ) {
			$titles[] = Title::capitalize( $code, NS_PORTAL ) . '/translators';
		}

		$dbr = wfGetDB( DB_SLAVE );
		$tables = array( 'page', 'revision', 'text' );
		$vars = array_merge(
			Revision::selectTextFields(),
			Revision::selectPageFields(),
			Revision::selectFields()
		);
		$conds = array(
			'page_latest = rev_id',
			'rev_text_id = old_id',
			'page_namespace' => NS_PORTAL,
			'page_title' => $titles,
		);

		$res = $dbr->select( $tables, $vars, $conds, __METHOD__ );

		$users = array();
		$lb = new LinkBatch;
		$lc = LinkCache::singleton();

		foreach ( $res as $row ) {
			$title = Title::newFromRow( $row );
			// Does not contain page_content_model, but should not matter
			$lc->addGoodLinkObjFromRow( $title, $row );

			$rev = Revision::newFromRow( $row );
			$text = ContentHandler::getContentText( $rev->getContent() );
			$code = strtolower( preg_replace( '!/translators$!', '', $row->page_title ) );

			preg_match_all( '!{{[Uu]ser\|([^}|]+)!', $text, $matches, PREG_SET_ORDER );
			foreach ( $matches as $match ) {
				$user = Title::capitalize( $match[1], NS_USER );
				$lb->add( NS_USER, $user );
				$lb->add( NS_USER_TALK, $user );
				if ( !isset( $users[$code] ) ) {
					$users[$code] = array();
				}
				$users[$code][strtr( $user, '_', ' ' )] = -1;
			}
		}

		$lb->execute();

		return $users;
	}

	protected function outputLanguageCloud( $names ) {
		$out = $this->getOutput();

		$langs = $this->languageCloud();
		$out->addHtml( '<div class="tagcloud autonym">' );
		$langs = $this->shuffle_assoc( $langs );
		foreach ( $langs as $k => $v ) {
			if ( !isset( $names[$k] ) ) {
				// All sorts of incorrect languages may turn up
				continue;
			}
			$name = $names[$k];
			$size = round( log( $v ) * 20 ) + 10;

			$params = array(
				'href' => "#$k",
				'class' => 'tag',
				'style' => "font-size:$size%",
				'lang' => $k,
			);

			$tag = Html::element( 'a', $params, $name );
			$out->addHtml( $tag . "\n" );
		}
		$out->addHtml( '</div>' );
	}

	protected function makeUserList( $users, $stats ) {
		$day = 60 * 60 * 24;

		// Scale of the activity colors, anything
		// longer than this is just inactive
		$period = $this->period;

		$links = array();
		$statsTable = new StatsTable();

		foreach ( $users as $username => $count ) {
			$title = Title::makeTitleSafe( NS_USER, $username );
			$enc = htmlspecialchars( $username );

			$attribs = array();
			$styles = array();
			if ( isset( $stats[$username][0] ) ) {
				if ( $count === -1 ) {
					$count = $stats[$username][0];
				}

				$styles['font-size'] = round( log( $count, 10 ) * 30 ) + 70 . '%';

				$last = wfTimestamp( TS_UNIX ) - wfTimeStamp( TS_UNIX, $stats[$username][1] );
				$last = round( $last / $day );
				$attribs['title'] = $this->msg( 'supportedlanguages-activity', $username )
					->numParams( $count, $last )->text();
				$last = max( 1, min( $period, $last ) );
				$styles['border-bottom'] = '3px solid #' .
					$statsTable->getBackgroundColor( $period - $last, $period );
			} else {
				$enc = "<del>$enc</del>";
			}

			$stylestr = $this->formatStyle( $styles );
			if ( $stylestr ) {
				$attribs['style'] = $stylestr;
			}

			$links[] = Linker::link( $title, $enc, $attribs );
		}

		// for GENDER support
		$username = '';
		if ( count( $users ) === 1 ) {
			$keys = array_keys( $users );
			$username = $keys[0];
		}

		$linkList = $this->getLanguage()->listToText( $links );
		$html = "<p class='mw-translate-spsl-translators'>";
		$html .= $this->msg( 'supportedlanguages-translators' )
			->rawParams( $linkList )
			->numParams( count( $links ) )
			->params( $username )
			->escaped();
		$html .= "</p>\n";
		$this->getOutput()->addHTML( $html );
	}

	protected function getUserStats( $users ) {
		$cache = wfGetCache( CACHE_ANYTHING );
		$dbr = wfGetDB( DB_SLAVE );
		$keys = array();

		foreach ( $users as $username ) {
			$keys[] = wfMemcKey( 'translate', 'sl-usertats', $username );
		}

		$cached = $cache->getMulti( $keys );
		$data = array();

		foreach ( $users as $index => $username ) {
			$cachekey = $keys[$index];

			if ( !$this->purge && isset( $cached[$cachekey] ) ) {
				$data[$username] = $cached[$cachekey];
				continue;
			}

			$tables = array( 'user', 'revision' );
			$fields = array( 'user_name', 'user_editcount', 'MAX(rev_timestamp) as lastedit' );
			$conds = array(
				'user_name' => $username,
				'user_id = rev_user',
			);

			$res = $dbr->selectRow( $tables, $fields, $conds, __METHOD__ );
			$data[$username] = array( $res->user_editcount, $res->lastedit );

			$cache->set( $cachekey, $data[$username], 3600 );
		}

		return $data;
	}

	protected function formatStyle( $styles ) {
		$stylestr = '';
		foreach ( $styles as $key => $value ) {
			$stylestr .= "$key:$value;";
		}

		return $stylestr;
	}

	function shuffle_assoc( $list ) {
		if ( !is_array( $list ) ) {
			return $list;
		}

		$keys = array_keys( $list );
		shuffle( $keys );
		$random = array();
		foreach ( $keys as $key )
			$random[$key] = $list[$key];

		return $random;
	}

	protected function preQueryUsers( $users ) {
		$lb = new LinkBatch;
		foreach ( $users as $translators ) {
			foreach ( $translators as $user => $count ) {
				$user = Title::capitalize( $user, NS_USER );
				$lb->add( NS_USER, $user );
				$lb->add( NS_USER_TALK, $user );
			}
		}
		$lb->execute();
	}

	protected function getColorLegend() {
		$legend = '';
		$period = $this->period;
		$statsTable = new StatsTable();

		for ( $i = 0; $i <= $period; $i += 30 ) {
			$iFormatted = htmlspecialchars( $this->getLanguage()->formatNum( $i ) );
			$legend .= '<span style="background-color:#' .
				$statsTable->getBackgroundColor( $period - $i, $period ) .
				"\"> $iFormatted</span>";
		}

		return $legend;
	}
}
