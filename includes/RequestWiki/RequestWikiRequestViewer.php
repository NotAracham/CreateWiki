<?php

namespace WikiForge\CreateWiki\RequestWiki;

use Config;
use Exception;
use ExtensionRegistry;
use Html;
use HTMLForm;
use HTMLFormField;
use IContextSource;
use Linker;
use MediaWiki\MediaWikiServices;
use WikiForge\CreateWiki\CreateWikiOOUIForm;
use WikiForge\CreateWiki\Hooks\CreateWikiHookRunner;
use WikiForge\CreateWiki\WikiManager;

class RequestWikiRequestViewer {

	/** @var Config */
	private $config;
	/** @var CreateWikiHookRunner */
	private $hookRunner;

	public function __construct( CreateWikiHookRunner $hookRunner = null ) {
		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$this->hookRunner = $hookRunner ?? MediaWikiServices::getInstance()->get( 'CreateWikiHookRunner' );
	}

	public function getFormDescriptor(
		WikiRequest $request,
		IContextSource $context
	) {
		$visibilityConds = [
			0 => 'read',
			1 => 'createwiki',
			2 => 'delete',
			3 => 'suppressrevision',
		];

		// Gets user from request
		$userR = $context->getUser();

		// if request isn't found, it doesn't exist
		// but if we can't view the request, it also doesn't exist
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();

		if ( !$permissionManager->userHasRight( $userR, $visibilityConds[$request->visibility] ) ) {
			$context->getOutput()->addHTML( Html::errorBox( wfMessage( 'requestwiki-unknown' )->escaped() ) );

			return [];
		}
		if ( $userR->getId() == $request->requester->getId() && $request->getStatus() === 'inreview' ) {
			$formDescriptor = [
				'requesterpendinginfo' => [
					'default' => wfMessage( 'requestwikiqueue-request-info-submission' )->text(),
					'type' => 'info',
					'section' => 'request',
				],
			];
		}

		$formDescriptor = [
			'sitename' => [
				'label-message' => 'requestwikiqueue-request-label-sitename',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->sitename,
			],
			'url' => [
				'label-message' => 'requestwikiqueue-request-label-url',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->url,
			],
			'language' => [
				'label-message' => 'requestwikiqueue-request-label-language',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => (string)$request->language,
			],
			'requester' => [
				// @phan-suppress-next-line SecurityCheck-XSS
				'label-message' => 'requestwikiqueue-request-label-requester',
				'type' => 'info',
				'section' => 'request',
				'default' => $request->requester->getName() . Linker::userToolLinks( $request->requester->getId(), $request->requester->getName() ),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestwikiqueue-request-label-requested-date',
				'type' => 'info',
				'section' => 'request',
				'default' => $context->getLanguage()->timeanddate( $request->timestamp, true ),
			],
			'status' => [
				'label-message' => 'requestwikiqueue-request-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'request',
				'default' => wfMessage( 'requestwikiqueue-' . $request->getStatus() )->text(),
			],
			'description' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestwikiqueue-request-header-requestercomment',
				'section' => 'request',
				'default' => (string)$request->description,
				'raw' => true,
			],
		];

		foreach ( $request->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 4,
				// @phan-suppress-next-line SecurityCheck-XSS
				'label' => wfMessage( 'requestwikiqueue-request-header-wikicreatorcomment-withtimestamp' )->rawParams( $comment['user']->getName() )->params( $context->getLanguage()->timeanddate( $comment['timestamp'], true ) )->text(),
				'default' => $comment['comment'],
			];
		}

		if ( $permissionManager->userHasRight( $userR, 'createwiki' ) && !$userR->getBlock() || $userR->getId() == $request->requester->getId() ) {

			if ( $permissionManager->userHasRight( $userR, 'createwiki' ) && !$userR->getBlock() ) {
				$visibilityOptions = [
					0 => wfMessage( 'requestwikiqueue-request-label-visibility-all' )->text(),
					1 => wfMessage( 'requestwikiqueue-request-label-visibility-hide' )->text(),
				];

				if ( $permissionManager->userHasRight( $userR, 'delete' ) ) {
					$visibilityOptions[2] = wfMessage( 'requestwikiqueue-request-label-visibility-delete' )->text();
				}

				if ( $permissionManager->userHasRight( $userR, 'suppressrevision' ) ) {
					$visibilityOptions[3] = wfMessage( 'requestwikiqueue-request-label-visibility-oversight' )->text();
				}

				$wm = new WikiManager( $request->dbname, $this->hookRunner );

				$wmError = $wm->checkDatabaseName( $request->dbname );

				if ( $wmError ) {
					$context->getOutput()->addHTML( Html::errorBox( $wmError ) );
				}

				$formDescriptor += [
					'info-submission' => [
						'type' => 'info',
						'default' => wfMessage( 'requestwikiqueue-request-info-submission' )->text(),
						'section' => 'review',
					],
					'submission-action' => [
						'type' => 'radio',
						'label-message' => 'requestwikiqueue-request-label-action',
						'options-messages' => [
							'requestwikiqueue-approve' => 'approve',
							'requestwikiqueue-decline' => 'decline',
							'requestwikiqueue-onhold' => 'onhold',
						],
						'default' => $request->getStatus(),
						'cssclass' => 'createwiki-infuse',
						'section' => 'review',
					],
					'reason' => [
						'label-message' => 'createwiki-label-statuschangecomment',
						'cssclass' => 'createwiki-infuse',
						'section' => 'review',
					],
					'visibility' => [
						'type' => 'check',
						'label-message' => 'revdelete-legend',
						'options' => array_flip( $visibilityOptions ),
						'default' => ($request->visibility != 0) ? 1 : 0,
						'cssclass' => 'createwiki-infuse',
						'section' => 'review',
					],
					'visibility-options' => [
						'type' => 'radio',
						'label-message' => 'revdelete-suppress-text',
						'hide-if' => [ '!==', 'wpvisibility', '1' ],
						'options' => array_flip( $visibilityOptions ),
						'default' => $request->visibility,
						'cssclass' => 'createwiki-infuse',
						'section' => 'review',
					],
					'submit-handle' => [
						'type' => 'submit',
						'default' => wfMessage( 'htmlform-submit' )->text(),
						'section' => 'review',
						],
				];

				if ( $wmError ) {
					// We don't want to be able to approve it if the database is not valid
					unset( $formDescriptor['submission-action']['options-messages']['requestwikiqueue-approve'] );
				}

				if ( $this->config->get( 'CreateWikiCannedResponses' ) ) {
					$formDescriptor['reason']['type'] = 'selectorother';
					$formDescriptor['reason']['options'] = $this->config->get( 'CreateWikiCannedResponses' );

					$formDescriptor['reason']['default'] = HTMLFormField::flattenOptions(
						$this->config->get( 'CreateWikiCannedResponses' )
					)[0];
				} else {
					$formDescriptor['reason']['type'] = 'textarea';
					$formDescriptor['reason']['rows'] = 4;
				}

				$formDescriptor += [
					'info-submission' => [
						'type' => 'info',
						'default' => wfMessage( 'requestwikiqueue-request-info-review' )->text(),
						'section' => 'review',
					],
				];
			}

			// For wiki requesters
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestwikiqueue-request-label-comment',
					'section' => 'comments',
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => wfMessage( 'htmlform-submit' )->text(),
					'section' => 'comments',
				],
				'edit-sitename' => [
					'label-message' => 'requestwikiqueue-request-label-sitename',
					'type' => 'text',
					'section' => 'review',
					'required' => true,
					'default' => (string)$request->sitename,
				],
				'edit-url' => [
					'label-message' => 'requestwikiqueue-request-label-url',
					'type' => 'text',
					'section' => 'review',
					'required' => true,
					'default' => (string)$request->url,
				],
				'edit-language' => [
					'label-message' => 'requestwikiqueue-request-label-language',
					'type' => 'language',
					'default' => (string)$request->language,
					'cssclass' => 'createwiki-infuse',
					'section' => 'review',
				],
				'edit-description' => [
					'label-message' => 'requestwikiqueue-request-header-requestercomment',
					'type' => 'textarea',
					'section' => 'review',
					'rows' => 4,
					'required' => true,
					'default' => (string)$request->description,
					'raw' => true,
				],
			];

			if ( $this->config->get( 'CreateWikiCategories' ) ) {
				$formDescriptor['edit-category'] = [
					'type' => 'select',
					'label-message' => 'createwiki-label-category',
					'options' => $this->config->get( 'CreateWikiCategories' ),
					'default' => (string)$request->category,
					'cssclass' => 'createwiki-infuse',
					'section' => 'review',
				];
			}

			if ( $this->config->get( 'CreateWikiUsePrivateWikis' ) ) {
				$formDescriptor['edit-private'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-private',
					'default' => $request->private,
					'section' => 'review',
				];
			}

			if ( $this->config->get( 'CreateWikiShowBiographicalOption' ) ) {
				$formDescriptor['edit-bio'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-bio',
					'default' => $request->bio,
					'section' => 'review',
				];
			}

			if ( $this->config->get( 'RequestWikiMigrationInquire' ) ) {
				$formDescriptor['edit-migration'] = [
					'type' => 'check',
					'label-message' => 'requestwiki-label-migration',
					'default' => $request->migration,
					'section' => 'review',
				];

				$formDescriptor['edit-migration-location'] = [
					'type' => 'text',
					'label-message' => 'requestwiki-label-migration-location',
					'hide-if' => [ '!==', 'wpedit-migration', '1' ],
					'default' => $request->migrationlocation,
					'section' => 'review',
				];

				$formDescriptor['edit-migration-type'] = [
					'type' => 'radio',
					'label-message' => 'requestwiki-label-migration-type',
					'options-messages' => [
						'requestwiki-option-migration-fork' => 'fork',
						'requestwiki-option-migration-migrate' => 'migrate',
						'requestwiki-option-migration-servermigrate' => 'servermigrate',
					],
					'hide-if' => [ '!==', 'wpedit-migration', '1' ],
					'default' => $request->migrationtype,
					'section' => 'review',
				];

				$formDescriptor['edit-migration-details'] = [
					'type' => 'text',
					'label-message' => 'requestwiki-label-migration-details',
					'hide-if' => [ '!==', 'wpedit-migration', '1' ],
					'default' => $request->migrationdetails,
					'section' => 'review',
				];
			}

			if ( $this->config->get( 'CreateWikiPurposes' ) ) {
				$formDescriptor['edit-purpose'] = [
					'type' => 'select',
					'label-message' => 'requestwiki-label-purpose',
					'options' => $this->config->get( 'CreateWikiPurposes' ),
					'default' => trim( $request->purpose ),
					'cssclass' => 'createwiki-infuse',
					'section' => 'review',
				];
			}
			if ( ExtensionRegistry::getInstance()->isLoaded( 'WikiDiscover' ) && $this->config->get( 'WikiDiscoverUseDescriptions' ) && $this->config->get( 'RequestWikiUseDescriptions' ) ) {
				$formDescriptor['public-description'] = [
					'type' => 'textarea',
					'rows' => 4,
					'default' => trim( $request->publicdescription ),
					'maxlength' => $this->config->get( 'WikiDiscoverDescriptionMaxLength' ) ?? false,
					'label-message' => 'requestwiki-label-public-description',
					'help-message' => 'requestwiki-help-public-description',
					'cssclass' => 'createwiki-infuse',
					'section' => 'review',
				];
			}

			$formDescriptor['submit-edit'] = [
				'type' => 'submit',
				'default' => wfMessage( 'requestwikiqueue-request-label-edit-wiki' )->text(),
				'section' => 'review',
			];
		}

		return $formDescriptor;
	}

	/**
	 * @param string $id
	 * @param IContextSource $context
	 * @param string $formClass
	 */
	public function getForm(
		string $id,
		IContextSource $context,
		$formClass = CreateWikiOOUIForm::class
	) {
		$out = $context->getOutput();

		$out->addModules( [ 'ext.createwiki.oouiform' ] );

		$out->addModuleStyles( [ 'ext.createwiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		try {
			$request = new WikiRequest( (int)$id, $this->hookRunner );
		} catch ( Exception $e ) {
			$context->getOutput()->addHTML( Html::errorBox( wfMessage( 'requestwiki-unknown' )->escaped() ) );

			return [];
		}

		$formDescriptor = $this->getFormDescriptor( $request, $context );

		$htmlForm = new $formClass( $formDescriptor, $context, 'requestwikiqueue' );

		$htmlForm->setId( 'createwiki-form' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) use ( $request ) {
				return $this->submitForm( $formData, $form, $request );
			}
		);

		return $htmlForm;
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form,
		WikiRequest $request
	) {
		$out = $form->getContext()->getOutput();
		$user = $form->getUser();

		if ( !$user->isRegistered() ) {
			$out->addHTML(
				Html::warningBox(
					Html::element(
						'p',
						[],
						$this->msg( 'exception-nologin-text' )->parse()
					),
					'mw-notify-error'
				)
			);
			return false;
		} elseif ( isset( $formData['submit-comment'] ) ) {
			$request->addComment( $formData['comment'], $user );
		} elseif ( isset( $formData['submit-edit'] ) ) {
			$subdomain = $formData['edit-url'];
			$err = '';
			$status = $request->parseSubdomain( $subdomain, $err );

			if ( $status === false ) {
				if ( $err !== '' ) {
					$out->addHTML(
						Html::warningBox(
							Html::element(
								'p',
								[],
								wfMessage( 'createwiki-error-' . $err )->parse()
							),
							'mw-notify-error'
						)
					);
				}

				return false;
			}

			$request->sitename = $formData['edit-sitename'];
			$request->language = $formData['edit-language'];
			$request->purpose = $formData['edit-purpose'] ?? '';
			$request->description = $formData['edit-description'];
			$request->category = $formData['edit-category'] ?? '';
			$request->private = $formData['edit-private'] ?? 0;
			$request->bio = $formData['edit-bio'] ?? 0;

			$request->reopen( $form->getUser() );
		} elseif ( isset( $formData['submit-handle'] ) ) {
			$request->visibility = $formData['visibility'];

			if ( $formData['submission-action'] == 'approve' ) {
				$request->approve( $user, $formData['reason'] );
			} elseif ( $formData['submission-action'] == 'onhold' ) {
				$request->onhold( $formData['reason'], $user );
			} else {
				$request->decline( $formData['reason'], $user );
			}
		}

		$out->addHTML(
			Html::successBox(
				Html::element(
					'p',
					[],
					wfMessage( 'requestwiki-edit-success' )->escaped()
				),
				'mw-notify-success'
			)
		);

		return false;
	}
}
