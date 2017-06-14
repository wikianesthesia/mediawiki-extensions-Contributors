<?php

/**
 * Hooks for Contributors
 *
 * @author Ike Hecht
 */
class ContributorsHooks {

	/**
	 * Set up the #contributors parser function
	 *
	 * @param Parser $parser
	 *
	 * @return bool
	 */
	public static function setupParserFunction( Parser &$parser ) {
		$parser->setFunctionHook( 'contributors', __CLASS__ . '::contributorsParserFunction',
			Parser::SFH_OBJECT_ARGS );

		return true;
	}

	/**
	 * Get a simple list of contributors from a given title and (optionally) sort options.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args The first element is the title. The remaining arguments are optional and
	 * 	correspond to sort options.
	 * @return string
	 */
	public static function contributorsParserFunction( Parser $parser, PPFrame $frame, array $args ) {
		$title = Title::newFromText( $frame->expand( array_shift( $args ) ) );
		if ( !$title ) {
			/** @todo This should perhaps return a <strong class="error"> message */
			return wfMessage( 'contributors-badtitle' )->inContentLanguage()->parseAsBlock();
		}
		if ( !$title->exists() ) {
			return wfMessage( 'contributors-nosuchpage', $title->getText() )->
					inContentLanguage()->parseAsBlock();
		}

		$options = array();
		foreach ( $args as $arg ) {
			$argString = trim( $frame->expand( $arg ) );
			if ( in_array( $argString, Contributors::getValidOptions() ) ) {
				$options[$argString] = true;
			}
		}

		$contributors = new Contributors( $title, $options );
		$list = $contributors->getSimpleList( $parser->getFunctionLang() );
		return array( $list, 'noparse' => true, 'isHTML' => true );
	}

	/**
	 * Prepare the toolbox link
	 *
	 * @var $skintemplate SkinTemplate
	 *
	 * @return bool
	 */
	public static function onSkinTemplateBuildNavUrlsNav_urlsAfterPermalink(
		&$skintemplate,
		&$nav_urls,
		&$oldid,
		&$revid
	) {
		if ( $skintemplate->getTitle()->getNamespace() === NS_MAIN && $revid !== 0 ) {
			$nav_urls['contributors'] = array(
				'text' => $skintemplate->msg( 'contributors-toolbox' ),
				'href' => $skintemplate->makeSpecialUrl(
					'Contributors/' . wfUrlencode( $skintemplate->thispage )
				),
			);
		}
		return true;
	}

	/**
	 * Output the toolbox link
	 *
	 * @return bool
	 */
	public static function onSkinTemplateToolboxEnd( BaseTemplate &$monobook ) {
		if ( isset( $monobook->data['nav_urls']['contributors'] ) ) {
			if ( $monobook->data['nav_urls']['contributors']['href'] == '' ) {
				?><li id="t-iscontributors"><?php echo $monobook->msg( 'contributors-toolbox' ); ?></li><?php
			} else {
					?><li id="t-contributors"><?php ?><a href="<?php echo htmlspecialchars(
					$monobook->data['nav_urls']['contributors']['href'] )
						?>"><?php
				echo $monobook->msg( 'contributors-toolbox' );
						?></a><?php ?></li><?php
			}
		}
		return true;
	}

	/**
	 * Add tables to Database
	 *
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'contributors', __DIR__ . '/sql/contributors.sql' );
		$updater->addExtensionField( 'contributors', 'cn_first_edit',
			__DIR__ . '/sql/contributors-add-timestamps.sql' );
		$updater->addExtensionField( 'contributors', 'cn_last_edit',
			__DIR__ . '/sql/contributors-add-timestamps.sql' );
		return true;
	}

	/**
	 * Updates the contributors table with each edit made by a user to a page
	 * @param WikiPage $article
	 * @param User $user
	 * @param $content
	 * @param $summary
	 * @param $isMinor
	 * @param $isWatch
	 * @param $section
	 * @param $flags
	 * @param $revision
	 * @param $status
	 * @param $baseRevId
	 * @throws Exception
	 */
	public static function onPageContentSaveComplete(
		$article,
		$user,
		$content,
		$summary,
		$isMinor,
		$isWatch,
		$section,
		$flags,
		$revision,
		$status,
		$baseRevId
	) {
		$dbw = wfGetDB( DB_MASTER );
		$dbr = wfGetDB( DB_SLAVE );
		$pageId = $article->getId();
		$userId = $user->getId();
		$text = $user->getName();
		$timestamp = $article->getTimestamp();

		$cond = array( 'cn_page_id' => $pageId, 'cn_user_id' => $userId, 'cn_user_text' => $text );

		$res = $dbr->select(
			'contributors',
			'cn_revision_count',
			$cond,
			__METHOD__
		);
		if ( $res->numRows() == 0 ) {
			$dbw->insert( 'contributors',
				array(
					'cn_page_id' => $pageId,
					'cn_user_id' => $userId,
					'cn_user_text' => $text,
					'cn_revision_count' => 1,
					'cn_first_edit' => $timestamp
				),
				__METHOD__
			);
		} else {
			foreach ( $res as $row ) {

			$dbw->upsert( 'contributors',
					array(
						'cn_page_id' => $pageId,
						'cn_user_id' => $userId,
						'cn_user_text' => $text,
						'cn_revision_count' => 1,
						'cn_first_edit' => $timestamp
					),
					array(
						'cn_page_id',
						'cn_user_id',
						'cn_user_text'
					),
					array(
						'cn_revision_count' => $row->cn_revision_count + 1,
						'cn_last_edit' => $timestamp
					),
					__METHOD__
				);
			}
		}
	}

	public static function onArticleRevisionVisibilitySet(
		Title $title,
		array $ids,
		array $visibilityChangeMap
	) {
		foreach ( $ids as $id ) {
			// TODO defer updates & transactions
			$revision = Revision::newFromId( $id );
			$conds = array(
				'cn_page_id' => $title->getArticleID(),
				'cn_user_id' => $revision->getUser( Revision::RAW ),
				'cn_user_text' => $revision->getUserText( Revision::RAW )
			);

			if (
				!( $visibilityChangeMap[$id]['oldBits'] & Revision::DELETED_USER ) &&
				( $visibilityChangeMap[$id]['newBits'] & Revision::DELETED_USER )
			) {
				$dbw = wfGetDB( DB_MASTER );
				$row = $dbw->selectRow(
					'contributors',
					'cn_revision_count',
					$conds,
					__METHOD__
				);
				if ( $row ) {
					if ( $row->cn_revision_count == 1 ) {
						$dbw->delete(
							'contributors',
							$conds,
							__METHOD__
						);
					} else {
						$dbw->update(
							'contributors',
							array(
								'cn_revision_count' => $row->cn_revision_count - 1
							),
							$conds,
							__METHOD__
						);
					}
				}
			} elseif (
				( $visibilityChangeMap[$id]['oldBits'] & Revision::DELETED_USER ) &&
				!( $visibilityChangeMap[$id]['newBits'] & Revision::DELETED_USER )
			) {
				$dbw = wfGetDB( DB_MASTER );
				$row = $dbw->selectRow(
					'contributors',
					'cn_revision_count',
					$conds,
					__METHOD__
				);
				$dbw = wfGetDB( DB_MASTER );
				if ( !$row ) {
					$dbw->insert(
						'contributors',
						array_merge( $conds, array( 'cn_revision_count' => 1 ) ),
						__METHOD__
					);
				} else {
					$dbw->update(
						'contributors',
						array(
							'cn_revision_count' => $row->cn_revision_count + 1
						),
						$conds,
						__METHOD__
					);
				}
			}
		}
	}

}
