<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\TranslatorSandbox;

use FormatJson;
use Html;
use Language;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Languages\LanguageNameUtils;
use SpecialPage;
use Title;
use TranslateSandbox;
use TranslateUtils;

/**
 * Special page for new users to translate example messages.
 *
 * @author Santhosh Thottingal
 * @license GPL-2.0-or-later
 * @ingroup SpecialPage TranslateSpecialPage
 */
class TranslationStashSpecialPage extends SpecialPage {
	/** @var TranslationStashReader */
	private $stash;
	/** @var ServiceOptions */
	private $options;
	/** @var LanguageNameUtils */
	private $languageNameUtils;

	public const CONSTRUCTOR_OPTIONS = [
		'TranslateSandboxLimit',
		'TranslateSecondaryPermissionUrl'
	];

	public function __construct(
		LanguageNameUtils $languageNameUtils,
		TranslationStashReader $stash,
		ServiceOptions $options
	) {
		$this->languageNameUtils = $languageNameUtils;
		$this->stash = $stash;
		$this->options = $options;
		parent::__construct( 'TranslationStash' );
	}

	public function doesWrites() {
		return true;
	}

	protected function getGroupName() {
		return 'translation';
	}

	public function execute( $params ) {
		$limit = $this->options->get( 'TranslateSandboxLimit' );
		$secondaryPermissionUrl = $this->options->get( 'TranslateSecondaryPermissionUrl' );

		$this->setHeaders();
		$out = $this->getOutput();

		$this->stash = new TranslationStashStorage( wfGetDB( DB_MASTER ) );

		if ( !$this->hasPermissionToUse() ) {
			if ( $secondaryPermissionUrl && $this->getUser()->isRegistered() ) {
				$out->redirect(
					Title::newFromText( $secondaryPermissionUrl )->getLocalURL()
				);

				return;
			}

			$out->redirect( Title::newMainPage()->getLocalURL() );

			return;
		}

		$out->addJsConfigVars( 'wgTranslateSandboxLimit', $limit );
		$out->addModules( 'ext.translate.special.translationstash' );
		$out->addModuleStyles( 'mediawiki.ui.button' );
		$this->showPage();
	}

	/** Checks that the user is in the sandbox. */
	private function hasPermissionToUse(): bool {
		return TranslateSandbox::isSandboxed( $this->getUser() );
	}

	/** Generates the whole page html and appends it to output */
	private function showPage(): void {
		$out = $this->getOutput();
		$user = $this->getUser();

		$count = count( $this->stash->getTranslations( $user ) );
		if ( $count === 0 ) {
			$progress = $this->msg( 'translate-translationstash-initialtranslation' )->parse();
		} else {
			$progress = $this->msg( 'translate-translationstash-translations' )
				->numParams( $count )
				->parse();
		}

		$out->addHTML(
			<<<HTML
<div class="grid">
	<div class="row translate-welcome-header">
		<h1>
			{$this->msg( 'translate-translationstash-welcome', $user->getName() )->parse()}
		</h1>
		<p>
			{$this->msg( 'translate-translationstash-welcome-note' )->parse()}
		</p>
	</div>
	<div class="row translate-stash-control">
		<div class="six columns stash-stats">
			{$progress}
		</div>
		<div class="six columns ext-translate-language-selector right">
			{$this->tuxLanguageSelector()}
		</div>
	</div>
	{$this->getMessageTable()}
	<div class="row limit-reached hide"></div>
</div>
HTML
		);
	}

	private function getMessageTable(): string {
		$sourceLang = $this->getSourceLanguage();
		$targetLang = $this->getTargetLanguage();

		return Html::element(
			'div',
			[
				'class' => 'row tux-messagelist',
				'data-sourcelangcode' => $sourceLang->getCode(),
				'data-sourcelangdir' => $sourceLang->getDir(),
				'data-targetlangcode' => $targetLang->getCode(),
				'data-targetlangdir' => $targetLang->getDir(),
			]
		);
	}

	private function tuxLanguageSelector(): string {
		// The name will be displayed in the UI language,
		// so use for lang and dir
		$language = $this->getTargetLanguage();
		$targetLangName = $this->languageNameUtils->getLanguageName( $language->getCode() );

		$label = Html::element(
			'span',
			[ 'class' => 'ext-translate-language-selector-label' ],
			$this->msg( 'tux-languageselector' )->text()
		);

		$trigger = Html::element(
			'span',
			[
				'class' => 'uls',
				'lang' => $language->getHtmlCode(),
				'dir' => $language->getDir(),
			],
			$targetLangName
		);

		// No-break space is added for spacing after the label
		// and to ensure separation of words (in Arabic, for example)
		return "$label&#160;$trigger";
	}

	/** Returns the source language for messages. */
	protected function getSourceLanguage(): Language {
		// Bad
		return Language::factory( 'en' );
	}

	/** Returns the default target language for messages. */
	private function getTargetLanguage(): Language {
		$ui = $this->getLanguage();
		$source = $this->getSourceLanguage();
		if ( !$ui->equals( $source ) ) {
			return $ui;
		}

		$options = FormatJson::decode( $this->getUser()->getOption( 'translate-sandbox' ), true );
		$supported = TranslateUtils::getLanguageNames( 'en' );

		if ( isset( $options['languages'] ) ) {
			foreach ( $options['languages'] as $code ) {
				if ( !isset( $supported[$code] ) ) {
					continue;
				}

				if ( $code !== $source->getCode() ) {
					return Language::factory( $code );
				}
			}
		}

		// User has not chosen any valid language. Pick the source.
		return Language::factory( $source->getCode() );
	}
}

class_alias( TranslationStashSpecialPage::class, '\MediaWiki\Extensions\Translate\TranslationStashSpecialPage' );
