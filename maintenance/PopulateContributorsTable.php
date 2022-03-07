<?php

use MediaWiki\Revision\RevisionRecord;

if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
    require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';
} else {
    require_once __DIR__ . '/../../../maintenance/Maintenance.php';
}

/**
 * Maintenance script that populates the Contributors table with Contributor's data
 *
 * @ingroup Maintenance
 */
class PopulateContributorsTable extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addDescription( "Populates the contributor's table with contributor's data" );
        $this->requireExtension( 'Contributors' );
    }

    public function execute() {
        $this->output( "Started processing..\n" );
        $dbw = $this->getDB( DB_PRIMARY );
        $dbr = $this->getDB( DB_REPLICA );

        $actorMigration = ActorMigration::newMigration();
        $actorSQL = $actorMigration->getJoin( 'rev_user' );

        // Hacky fix to add prefix to join for revision table from ActorMigration to avoid ambiguity
        $actorSQL[ 'joins' ][ 'temp_rev_user' ][ 1 ] =
            str_replace( 'rev_id', 'revision.rev_id', $actorSQL[ 'joins' ][ 'temp_rev_user' ][ 1 ] );

        $tables = [
                'revision' => 'revision',
                'revision_parent' => 'revision'
            ] + $actorSQL[ 'tables' ];

        $fields = [
                'rev_id' => 'revision.rev_id',
                'rev_page' => 'revision.rev_page',
                'rev_timestamp' => 'revision.rev_timestamp',
                'rev_deleted' => 'revision.rev_deleted',
                'rev_len' => 'revision.rev_len',
                'rev_parent_len' => 'revision_parent.rev_len'
            ] + $actorSQL[ 'fields' ];

        $conds = [];

        $options = [
            'ORDER BY' => 'rev_page, rev_timestamp'
        ];

        $join_conds = [
                'revision_parent' => [
                    'LEFT JOIN',
                    'revision.rev_parent_id = revision_parent.rev_id'
                ]
            ] + $actorSQL[ 'joins' ];

        $res = $dbr->select(
            $tables,
            $fields,
            $conds,
            __METHOD__,
            $options,
            $join_conds
        );

        if( !$res || !$res->numRows() ) {
            $this->output( "Nothing to do.\n" );

            return true;
        }

        $prevPageId = 0;
        $contributorRows = [];
        foreach( $res as $row ) {
            if( $row->rev_page != $prevPageId ) {
                if( $prevPageId ) {
                    // Finished processing a page, insert data
                    $contributorCount = 0;

                    foreach( $contributorRows as $contributorRow ) {
                        if( !$contributorRow[ 'cn_revision_count' ] ) {
                            continue;
                        }

                        $dbw->upsert(
                            'contributors',
                            $contributorRow,
                            [
                                [ 'cn_page_id', 'cn_user_id', 'cn_user_text' ]
                            ],
                            $contributorRow,
                            __METHOD__
                        );

                        $contributorCount++;
                    }

                    $this->output( "Processed page id `$prevPageId`: $contributorCount contributor(s).\n" );

                    // Reinitialize contributor rows
                    $contributorRows = [];
                }

                $prevPageId = $row->rev_page;
            }

            if( !$row->rev_user ) {
                continue;
            }

            if( !isset( $contributorRows[ $row->rev_user ] ) ) {
                // Initialize row
                $contributorRows[ $row->rev_user ] = [
                    'cn_page_id' => $row->rev_page,
                    'cn_user_id' => $row->rev_user,
                    'cn_user_text' => $row->rev_user_text,
                    'cn_revision_count' => 0,
                    'cn_characters_added' => 0,
                    'cn_first_edit' => 0,
                    'cn_last_edit' => 0
                ];
            }

            if( !( $row->rev_deleted & RevisionRecord::DELETED_USER ) ) {
                $contributorRows[ $row->rev_user ][ 'cn_revision_count' ]++;

                $contributorRows[ $row->rev_user ][ 'cn_first_edit' ] =
                    $contributorRows[ $row->rev_user ][ 'cn_first_edit' ] ?
                        min( $contributorRows[ $row->rev_user ][ 'cn_first_edit' ], $row->rev_timestamp ) :
                        $row->rev_timestamp;

                $contributorRows[ $row->rev_user ][ 'cn_last_edit' ] =
                    $contributorRows[ $row->rev_user ][ 'cn_last_edit' ] ?
                        max( $contributorRows[ $row->rev_user ][ 'cn_last_edit' ], $row->rev_timestamp ) :
                        $row->rev_timestamp;

                if( !( $row->rev_deleted & RevisionRecord::DELETED_TEXT ) ) {
                    $charactersAdded = $row->rev_len - $row->rev_parent_len;

                    if( $charactersAdded > 0 ) {
                        $contributorRows[ $row->rev_user ][ 'cn_characters_added' ] += $charactersAdded;
                    }
                }
            }
        }

        $this->output( "Process finished.\n" );
        return true;
    }
}

$maintClass = "PopulateContributorsTable";
require_once RUN_MAINTENANCE_IF_MAIN;
