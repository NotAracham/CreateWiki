<?php

namespace WikiForge\CreateWiki\RequestWiki;

use Config;
use Exception;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use Title;
use WikiForge\CreateWiki\CreateWikiRegexConstraint;
use WikiForge\CreateWiki\Hooks\CreateWikiHookRunner;

class SpecialRequestWiki extends FormSpecialPage {

	/** @var Config */
	private $config;
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public function __construct( CreateWikiHookRunner $hookRunner ) {
		parent::__construct( 'RequestWiki', 'requestwiki' );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner;
	}

	public function execute( $par ) {
		$request = $this->getRequest();
		$out = $this->getOutput();

		$this->requireLogin( 'requestwiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$this->checkExecutePermissions( $this->getUser() );

		$out->addModules( [ 'mediawiki.special.userrights' ] );
		$out->addModuleStyles( 'mediawiki.notification.convertmessagebox.styles' );

		if ( !$request->wasPosted() && $this->config->get( 'CreateWikiCustomDomainPage' ) ) {
			$customdomainurl = Title::newFromText( $this->config->get( 'CreateWikiCustomDomainPage' ) )->getFullURL();

			$out->addWikiMsg( 'requestwiki-header', $customdomainurl );
		}

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	protected function getFormFields() {
		$formDescriptor = [
			'subdomain' => [
				'type' => 'text',
				'label-message' => [ 'requestwiki-label-siteurl', $this->config->get( 'CreateWikiSubdomain' ) ],
				'required' => true,
			],
			'sitename' => [
				'type' => 'text',
				'label-message' => 'requestwiki-label-sitename',
				'required' => true,
			],
			'language' => [
				'type' => 'language',
				'label-message' => 'requestwiki-label-language',
				'default' => 'en',
			],
		];

		if ( $this->config->get( 'CreateWikiCategories' ) ) {
			$formDescriptor['category'] = [
				'type' => 'select',
				'label-message' => 'createwiki-label-category',
				'options' => $this->config->get( 'CreateWikiCategories' ),
				'default' => 'uncategorized',
			];
		}

		if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) && !$this->config->get( 'RequestWikiDisablePrivateRequests' ) ) {
			$formDescriptor['private'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-private',
			];
		}

		if ( $this->config->get( 'CreateWikiShowBiographicalOption' ) ) {
			$formDescriptor['bio'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-bio',
			];
		}

		if ( $this->config->get( 'RequestWikiMigrationInquire' ) ) {
			$formDescriptor['migration'] = [
				'type' => 'check',
				'label-message' => 'requestwiki-label-migration',
			];

			$formDescriptor['migration-location'] = [
				'type' => 'text',
				'hide-if' => [ '==', 'wp-migration', 1 ],
				'label-message' => 'requestwiki-label-migration-location',
			];

			$formDescriptor['migration-type'] = [
				'type' => 'radio',
				'option-messages' => [
					'requestwiki-option-migration-fork' => 'fork',
					'requestwiki-option-migration-migrate' => 'migrate',
				],
				'hide-if' => [ '==', 'wp-migration', 1 ],
				'label-message' => 'requestwiki-label-migration-type',
			];

			$formDescriptor['migration-details'] = [
				'type' => 'textarea',
				'hide-if' => [ '==', 'wp-migration', 1 ],
				'label-message' => 'requestwiki-label-migration-details',
			];
		}

		if ( $this->config->get( 'CreateWikiPurposes' ) ) {
			$formDescriptor['purpose'] = [
				'type' => 'select',
				'label-message' => 'requestwiki-label-purpose',
				'options' => $this->config->get( 'CreateWikiPurposes' ),
			];
		}

		$formDescriptor['reason'] = [
			'type' => 'textarea',
			'rows' => 4,
			'minlength' => $this->config->get( 'RequestWikiMinimumLength' ) ?? false,
			'label-message' => 'createwiki-label-reason',
			'help-message' => 'createwiki-help-reason',
			'required' => true,
			'validation-callback' => [ $this, 'isValidReason' ],
		];

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		$request = new WikiRequest( null, $this->hookRunner );
		$subdomain = strtolower( $formData['subdomain'] );
		$out = $this->getOutput();
		$err = '';

		$status = $request->parseSubdomain( $subdomain, $err );
		if ( $status === false ) {
			if ( $err !== '' ) {
				$out->addHTML(
					Html::successBox(
						Html::element(
							'p',
							[],
							$this->msg( 'createwiki-error-' . $err )->parse()
						),
						'mw-notify-success'
					)
				);
			}

			return false;
		}

		$request->description = $formData['reason'];
		$request->sitename = $formData['sitename'];
		$request->language = $formData['language'];
		$request->private = $formData['private'] ?? 0;
		$request->requester = $this->getUser();
		$request->category = $formData['category'] ?? '';
		$request->purpose = $formData['purpose'] ?? '';
		$request->bio = $formData['bio'] ?? 0;
		$request->migration = $formData['migration'] ?? 0;
		$request->migrationlocation = $formData['migration-location'] ?? '';
		$request->migrationtype = $formData['migration-type'] ?? '';
		$request->migrationdetails = $formData['migration-details'] ?? '';

		try {
			$requestID = $request->save();
		} catch ( Exception $e ) {
			$out->addHTML(
				Html::successBox(
					Html::element(
						'p',
						[],
						$this->msg( 'requestwiki-error-patient' )->plain()
					),
					'mw-notify-success'
				)
			);

			return false;
		}

		$idlink = MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( Title::newFromText( 'Special:RequestWikiQueue/' . $requestID ), "#{$requestID}" );

		$farmerLogEntry = new ManualLogEntry( 'farmer', 'requestwiki' );
		$farmerLogEntry->setPerformer( $this->getUser() );
		$farmerLogEntry->setTarget( $this->getPageTitle() );
		$farmerLogEntry->setComment( $formData['reason'] );
		$farmerLogEntry->setParameters(
			[
				'4::sitename' => $formData['sitename'],
				'5::language' => $formData['language'],
				'6::private' => (int)( $formData['private'] ?? 0 ),
				'7::id' => "#{$requestID}",
			]
		);

		$farmerLogID = $farmerLogEntry->insert();
		$farmerLogEntry->publish( $farmerLogID );

		$out->addHTML( Html::successBox( $this->msg( 'requestwiki-success', $idlink )->plain() ) );

		return true;
	}

	public function isValidReason( $reason, $allData ) {
		$regexes = CreateWikiRegexConstraint::regexesFromMessage( 'CreateWiki-disallowlist' );

		foreach ( $regexes as $regex ) {
			preg_match( '/' . $regex . '/i', $reason, $output );

			if ( is_array( $output ) && count( $output ) >= 1 ) {
				return $this->msg( 'requestwiki-error-invalidcomment' )->escaped();
			}
		}

		if ( !$reason || ctype_space( $reason ) ) {
			return $this->msg( 'htmlform-required', 'parseinline' )->escaped();
		}

		return true;
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
