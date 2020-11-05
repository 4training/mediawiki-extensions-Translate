<?php
/**
 * Contains logic for Special:ManageTranslatorSandbox
 *
 * @file
 * @author Niklas Laxström
 * @author Amir E. Aharoni
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extensions\Translate\TranslatorSandbox;

use FormatJson;
use Html;
use MediaWiki\Config\ServiceOptions;
use MWTimestamp;
use Sanitizer;
use SpecialPage;
use TranslateSandbox;
use User;

/**
 * Special page for managing sandboxed users.
 *
 * @ingroup SpecialPage TranslateSpecialPage
 */
class SpecialManageTranslatorSandbox extends SpecialPage {
	/** @var TranslationStashReader */
	private $stash;
	public const CONSTRUCTOR_OPTIONS = [
		'TranslateUseSandbox',
	];

	public function __construct(
		TranslationStashReader $stash,
		ServiceOptions $options
	) {
		$this->stash = $stash;

		parent::__construct(
			'ManageTranslatorSandbox',
			'translate-sandboxmanage',
			$options->get( 'TranslateUseSandbox' )
		);
	}

	public function doesWrites() {
		return true;
	}

	protected function getGroupName() {
		return 'translation';
	}

	public function execute( $params ) {
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();
		$out->addModuleStyles(
			[
				'ext.translate.special.managetranslatorsandbox.styles',
				'mediawiki.ui.button',
				'jquery.uls.grid',
			]
		);
		$out->addModules( 'ext.translate.special.managetranslatorsandbox' );

		$this->showPage();
	}

	/**
	 * Generates the whole page html and appends it to output
	 */
	protected function showPage() {
		$out = $this->getOutput();

		$nojs = Html::element(
			'div',
			[ 'class' => 'tux-nojs errorbox' ],
			$this->msg( 'tux-nojs' )->plain()
		);
		$out->addHTML( $nojs );

		$out->addHTML(
			<<<HTML
<div class="grid tsb-container">
	<div class="row">
		<div class="nine columns pane filter">{$this->makeFilter()}</div>
		<div class="three columns pane search">{$this->makeSearchBox()}</div>
	</div>
	<div class="row tsb-body">
		<div class="four columns pane requests">
			{$this->makeList()}
			<div class="request-footer">
				<span class="selected-counter">
					{$this->msg( 'tsb-selected-count' )->numParams( 0 )->escaped()}
				</span>
				&nbsp;
				<a href="#" class="older-requests-indicator"></a>
			</div>
		</div>
		<div class="eight columns pane details"></div>
	</div>
</div>
HTML
		);
	}

	protected function makeFilter() {
		return $this->msg( 'tsb-filter-pending' )->escaped();
	}

	protected function makeSearchBox() {
		return <<<HTML
<input class="request-filter-box right"
	placeholder="{$this->msg( 'tsb-search-requests' )->escaped()}" type="search" />
HTML;
	}

	protected function makeList() {
		$items = [];
		$requests = [];
		$users = TranslateSandbox::getUsers();

		/** @var User $user */
		foreach ( $users as $user ) {
			$reminders = $user->getOption( 'translate-sandbox-reminders' );
			$reminders = $reminders ? explode( '|', $reminders ) : [];
			$remindersCount = count( $reminders );
			if ( $remindersCount ) {
				$lastReminderTimestamp = new MWTimestamp( end( $reminders ) );
				$lastReminderAgo = htmlspecialchars(
					$lastReminderTimestamp->getHumanTimestamp()
				);
			} else {
				$lastReminderAgo = '';
			}

			$requests[] = [
				'username' => $user->getName(),
				'email' => $user->getEmail(),
				'gender' => $user->getOption( 'gender' ),
				'registrationdate' => $user->getRegistration(),
				'translations' => count( $this->stash->getTranslations( $user ) ),
				'languagepreferences' => FormatJson::decode(
					$user->getOption( 'translate-sandbox' )
				),
				'userid' => $user->getId(),
				'reminderscount' => $remindersCount,
				'lastreminder' => $lastReminderAgo,
			];
		}

		// Sort the requests based on translations and registration date
		usort( $requests, [ __CLASS__, 'translatorRequestSort' ] );

		foreach ( $requests as $request ) {
			// @phan-suppress-next-line SecurityCheck-DoubleEscaped
			$items[] = $this->makeRequestItem( $request );
		}

		$requestsList = implode( "\n", $items );

		return <<<HTML
<div class="row request-header">
	<div class="four columns">
		<button class="language-selector unselected">
			{$this->msg( 'tsb-all-languages-button-label' )->escaped()}
		</button>
	</div>
	<div class="five columns request-count"></div>
	<div class="three columns center">
		<input class="request-selector-all" name="request" type="checkbox" />
	</div>
</div>
<div class="requests-list">
	{$requestsList}
</div>
HTML;
	}

	protected function makeRequestItem( $request ) {
		$requestdataEnc = htmlspecialchars( FormatJson::encode( $request ) );
		$nameEnc = htmlspecialchars( $request['username'] );
		$nameEncForId =
			htmlspecialchars(
				Sanitizer::escapeIdForAttribute( 'tsb-request-' . $request['username'] )
			);
		$emailEnc = htmlspecialchars( $request['email'] );
		$countEnc = htmlspecialchars( $request['translations'] );
		$timestamp = new MWTimestamp( $request['registrationdate'] );
		$agoEnc = htmlspecialchars( $timestamp->getHumanTimestamp() );

		return <<<HTML
<div class="row request" data-data="$requestdataEnc" id="$nameEncForId">
	<div class="two columns amount">
		<div class="translation-count">$countEnc</div>
	</div>
	<div class="seven columns request-info">
		<div class="row username">$nameEnc</div>
		<div class="row email" dir="ltr">$emailEnc</div>
	</div>
	<div class="three columns approval center">
		<input class="row request-selector" name="request" type="checkbox" />
		<div class="row signup-age">$agoEnc</div>
	</div>
</div>
HTML;
	}

	/**
	 * Sorts groups by descending order of number of translations,
	 * registration date and username
	 *
	 * @param array $a Translation request
	 * @param array $b Translation request
	 * @return int comparison result
	 * @since 2013.12
	 */
	public static function translatorRequestSort( $a, $b ) {
		$translationCountDiff = $b['translations'] - $a['translations'];
		if ( $translationCountDiff !== 0 ) {
			return $translationCountDiff;
		}

		$registrationDateDiff = $b['registrationdate'] - $a['registrationdate'];
		if ( $registrationDateDiff !== 0 ) {
			return $registrationDateDiff;
		}

		return strcmp( $a['username'], $b['username'] );
	}
}