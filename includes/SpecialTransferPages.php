<?php


/**
 * It probably wouldn't be _too_ hard to create a special page that allowed a
 * privileged user to select a category or namespace on a source wiki. Then also
 * select a destination wiki. Then check that the page names from the source
 * wiki don't already exist on the destination. Then, if no collisions are
 * detected, essentially run `dumpBackup.php` on the source wiki plus
 * `importDump.php` on the destination wiki, and optionally delete the pages or
 * replace them with interwiki redirects on the source wiki.
 */

# workflow:
#  step 1: setupTransferPages()
#     a. choose namespace and?/or? category
#     b. choose destination wiki (show source wiki as wiki you're on) ... dropdown of wikis?
#  step 2: queryTransferablePages() run query to check for conflicts in page transfer
#     a. do pages from source wiki exist on destination?
#     b. if they do, is the source and destination the same?
#  step 3: showTransferablePages() print table/list of pages
#     a. Page name
#     b. Status relative to dest (not exist, exist and same, exist but different)
#     c. Later: provide link to diff of pages
#     d. Checkbox for whether to include page in transfer (and check/uncheck all button)
#     e. radio buttons for source action: delete, redirect to dest, anything else?
#  step 4: do transfer...essentially:
#     a. run dumpBackup.php on source
#     b. run importDump.php on dest
#     c. perform delete or redirect on source
#     d. Consider doing A-C as individual jobs for each page, managed by job queue


#
# Pages can:
#   exist on source and dest and are the same
#   exist on source and dest and are different
#       keep source (overwrite dest)
#       keep dest
#       	skip this page, do nothing on source
#       	create redirect or whatever on source
#   exist only on source
#
#   CANNOT exist only on dest (this is about transfering pages...not going to transfer NULL pages)
#
use MediaWiki\MediaWikiServices;

class SpecialTransferPages extends SpecialPage {

	public $mMode;

	// public function __construct( $name = 'Transfer pages' ) {
	public function __construct( $name = 'TransferPages' ) {
		parent::__construct(
			$name, //
			"transferpages",  // rights required to view
			true // show in Special:SpecialPages
		);
	}

	function execute( $par ) {

		# from cross wiki diff
		// $this->getOutput()->addModules( 'ext.nasaspecifics.crosswikidiff' );
		// $this->duplicatedPages();
		# END example


		// Only allow users with 'transferpages' right (sysop by default) to access
		$user = $this->getUser();
		if ( !$this->userCanExecute( $user ) ) {
			$this->displayRestrictionError();
			return;
		}

		// $webRequest = $this->getRequest();

		$this->setupTransferForm();

	}



	public function setupTransferForm () {

		$formDescriptor = [
			'destinationwiki' => [
				'name' => 'destinationwiki',
				'type' => 'select',
				'id' => 'ext-meza-destinationwiki-select',
				'label-message' => 'ext-meza-destinationwiki-selectlabel',
				'options' => $this->getValidDestinationWikis(),
			],
			'namespace' => [
				'type' => 'namespaceselect',
				'name' => 'namespace',
				'id' => 'ext-meza-namespace-select',
				'label-message' => 'ext-meza-namespace-selectlabel',
				'all' => 'all',
				'default' => 'all',
			],
			'category' => [
				'type' => 'text',
				'name' => 'category',
				'id' => 'ext-meza-category-textbox',
				'label-message' => 'ext-meza-category-textboxlabel',
				'default' => '',
				'size' => 20,
			],
		];

		return HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() )

			->setId( 'ext-meza-transferpages-form' )
			->setMethod( 'get' )

			// ->setHeaderText( $this->msg( 'ext-meza-mergeitems-header' )->parse() )
			// ->setFooterText( $this->msg( 'ext-meza-mergeitems-footer' )->parse() )
			// ->setWrapperLegendMsg( 'ext-meza-transferpages-legend' )

			// ->suppressDefaultSubmit()
			->setSubmitID( 'ext-meza-transferpages-submit' )
			->setSubmitName( 'ext-meza-transferpages-submit' )
			->setSubmitTextMsg( 'ext-meza-transferpages-submit' )

			->setSubmitCallback( [ $this, 'queryTransferablePages' ] )

			->prepareForm()
			// ->getHTML( '' ); RETURNS RAW HTML OF FORM...
			->show();

	}

	public function queryTransferablePages() {
		$output = $this->getOutput();

		$output->setPageTitle( 'Transfer pages' );  // FIXME i18n

		$query = $this->buildTransferablePagesQuery();

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->query( $query, __METHOD__ );

		$destWiki = $this->getRequest()->getVal( 'destinationwiki' );

		$numRows = 0;
		#
		#
		# FIXME i18n
		#
		#
		$html = "<br />
			<table class='sortable wikitable jquery-tablesorter' style='width:100%;'>
			<tr>
				<th>Page</th>
				<th>Transfer risk</th>
				<th>Do transfer</th>
				<th>Action on source wiki</th>
			</tr>";

		while( $row = $dbr->fetchRow( $res ) ) {

			list($ns, $titleText, $wikis, $numContentUniques, $numNameDupes) = [
				$row['ns'],
				$row['title'],
				$row['wikis'],
				$row['num_content_uniques'],
				$row['num_name_dupes']
			];

			// todo: add logic here to check for change in $isOnDest and
			// $conflictWithDest, to break the table into three tables. Then can
			// easily perform actions just against the separate types.

			$isOnDest = $numNameDupes > 1 ? true : false;
			if ( $isOnDest ) {
				$conflictWithDest = $numContentUniques > 1 ? true : false;
			}
			else {
				$conflictWithDest = false;
			}

			if ( $conflictWithDest ) {
				$transferRisk = 'danger';
			}
			elseif ( $isOnDest ) {
				$transferRisk = 'okay';
			}
			else {
				$transferRisk = 'good';
			}

			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

			$srcLink = $linkRenderer->makeLink( Title::makeTitle( $ns, $titleText ) );
			if ( $isOnDest ) {
				$destLink = $linkRenderer->makeLink(
					Title::makeTitle( $ns, $titleText, '', $destWiki ),
					new HtmlArmor( $destWiki )
				);
				$destLink = $this->msg( 'ext-meza-transferpages-dest-link' )
					->rawParams( $destLink )
					->text();
			}
			else {
				$destLink = '';
			}

			$links = $srcLink . ' ' . $destLink;

			$transferRiskTd = Xml::tags(
				'td',
				[ 'class' => 'ext-meza-tranferpages-risk-' . $transferRisk ],
				$this->msg( 'ext-meza-transferpages-risk-' . $transferRisk )
			);


			// $diff = 'at some point create a good diff view between the pages on each wiki';

			$transferPage = "<input type='checkbox' name='dotransfer$numRows' value='1'><label for='dotransfer$numRows'>transfer page</label> ";

			$srcAction = "<input type='checkbox' name='deletesrc$numRows' value='1'><label for='deletesrc$numRows'>delete</label>
				<input type='checkbox' name='redirectsrc$numRows' value='1'><label for='redirectsrc$numRows'>redirect</label>
				<input type='checkbox' name='donothingsrc$numRows' value='1'><label for='donothingsrc$numRows'>do nothing</label> ";

			$html .= "<tr>
					<td>$links</td>
					$transferRiskTd
					<td>$transferPage</td>
					<td>$srcAction</td>
				</tr>";

			$numRows++;
		}
		$html .= "</table>";

		$output->prependHTML( $html );
	}


	public function buildTransferablePagesQuery() {

		global $wikiId;

		$request = $this->getRequest();

		$srcWiki = $wikiId;
		$destWiki = $request->getVal( 'destinationwiki' );
		$category = $request->getVal( 'category' );

		$namespace = $request->getVal( 'namespace', false );
		if ( $namespace === 'all' ) {
			$namespace = false;
		}

		$srcQuery = $this->getWikiQueryPart( $srcWiki, $category, $namespace );
		$destQuery = $this->getWikiQueryPart( $destWiki, $category, $namespace );

		// WHERE wikis != '$destWiki':
		//   Don't show pages that only exist on the destination wiki since we
		//   can't transfer them from this wiki.
		$query = "
			SELECT
				*
			FROM (

				SELECT
					ns,
					title,
					GROUP_CONCAT( wiki separator ',' ) AS wikis,
					COUNT( DISTINCT sha1 ) AS num_content_uniques,
					COUNT( * ) AS num_name_dupes
				FROM (

					$srcQuery
					UNION ALL
					$destQuery

				) AS tmp
				GROUP BY ns, title

			) AS tmp2
			WHERE wikis != '$destWiki'
			ORDER BY num_name_dupes DESC, num_content_uniques DESC";

		//echo "<pre>$query</pre>";
		return $query;

	}

	public function getWikiQueryPart ( $wiki, $category, $namespace ) {

		$query =
			"SELECT
				'$wiki' AS wiki,
				wiki_$wiki.page.page_namespace AS ns,
				wiki_$wiki.page.page_title AS title,
				wiki_$wiki.revision.rev_sha1 AS sha1
			FROM wiki_$wiki.page
			LEFT JOIN wiki_$wiki.revision ON wiki_$wiki.page.page_latest = wiki_$wiki.revision.rev_id";

		$where = [];

		if ( $category ) {
			$query .= "\nLEFT JOIN wiki_$wiki.categorylinks ON wiki_$wiki.page.page_id = wiki_$wiki.categorylinks.cl_from";
			$where[] = "wiki_$wiki.categorylinks.cl_to = '$category'";
		}

		if ( $namespace !== false ) {
			$where[] = "wiki_$wiki.page.page_namespace = $namespace";
		}

		if ( count( $where ) > 0 ) {
			$query .= "\nWHERE " . implode( ' AND ', $where );
		}

		return $query;
	}

	/**
	 * Get the list of wikis in this meza server besides the current wiki
	 *
	 * @return array
	 */
	// private function getInterwikiList() {
	private function getValidDestinationWikis() {
		global $wikiId, $m_htdocs;
		$wikis = array_slice( scandir( "$m_htdocs/wikis" ), 2 );
		$validDests = [];
		foreach( $wikis as $wiki ) {
			if ( $wiki !== $wikiId ) {
				$validDests[$wiki] = $wiki;
			}
		}
		return $validDests;
	}

}
