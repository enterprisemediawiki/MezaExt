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

		$this->getOutput()->setPageTitle( 'Transfer pages' );  // FIXME i18n

		$this->getOutput()->addModules( 'ext.mezaext.specialtransferpages' );

		// Only allow users with 'transferpages' right (sysop by default) to access
		$user = $this->getUser();
		if ( !$this->userCanExecute( $user ) ) {
			$this->displayRestrictionError();
			return;
		}

		$webRequest = $this->getRequest();

		// show list of transferable pages
		if ( $webRequest->getVal( 'ext-meza-transferpages-setup-submit' ) !== null ) {

			// still show the pages/wiki selection form in addition to list of pages
			$this->renderTransferPagesSetupForm();

			// table of pages to transfer
			$this->queryTransferablePages();

		// perform transfer operation
		} elseif ( $webRequest->getVal( 'ext-meza-transferpages-dotransfer-submit' ) !== null ) {

			$this->doTransfer();

		} else {
			// show form to select which namespaces/categories to transfer, and
			// what wiki to transfer them to. This will
			$this->renderTransferPagesSetupForm();
		}

	}

	public function renderTransferPagesSetupForm () {

		$this->getOutput()->addHTML(
			Xml::element(
				'h2',
				[],
				$this->msg( 'ext-meza-transferpages-setupheader' )->parse()
			)
		);

		$wikis = $this->getValidDestinationWikis();

		$formDescriptor = [
			'destinationwiki' => [
				'name' => 'destinationwiki',
				'type' => 'select',
				'id' => 'ext-meza-destinationwiki-select',
				'label-message' => 'ext-meza-destinationwiki-selectlabel',
				'default' => array_keys( $wikis )[0],
				'options' => $wikis,
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
			->setSubmitID( 'ext-meza-transferpages-setup-submit' )
			->setSubmitName( 'ext-meza-transferpages-setup-submit' )
			->setSubmitTextMsg( 'ext-meza-transferpages-setup-submit' )

			// why do this versus have logic in execute() ???
			// ->setSubmitCallback( [ $this, 'queryTransferablePages' ] )
			->setSubmitCallback( [ $this, 'dummy' ] )

			->prepareForm()
			// ->getHTML( '' ); RETURNS RAW HTML OF FORM...
			->show();

	}

	// HTMLForm used the way it's done here appears to require a setSubmitCallback
	// function, but it seems cleaner to just let execute() route everything. Use
	// dummy() to do a pointless callback.
	public function dummy () {}

	public function queryTransferablePages() {
		$output = $this->getOutput();

		$output->addHTML(
			Xml::element(
				'h2',
				[],
				$this->msg( 'ext-meza-transferpages-transferrable-pages' )->parse()
			)
		);

		$query = $this->buildTransferablePagesQuery();

		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->query( $query, __METHOD__ );

		$destWiki = $this->getRequest()->getVal( 'destinationwiki' );

		$formid = 'ext-meza-transferpages-form2';
		$action = $this->getPageTitle()->getLocalURL();

		$pagesConflict = [];
		$pagesIdentical = [];
		$pagesSourceOnly = [];

		$totalPagesQueried = $dbr->numRows();

		$numRows = 0;
		while( $row = $dbr->fetchRow( $res ) ) {

			list($ns, $titleText, $srcId, $wikis, $numContentUniques, $numNameDupes) = [
				$row['ns'],
				$row['title'],
				$row['src_id'],
				$row['wikis'],
				$row['num_content_uniques'],
				$row['num_name_dupes']
			];

			$isOnDest = $numNameDupes > 1 ? true : false;
			if ( $isOnDest ) {
				$conflictWithDest = $numContentUniques > 1 ? true : false;
			}
			else {
				$conflictWithDest = false;
			}

			if ( $conflictWithDest ) {
				$transferRisk = 'danger';
				$pageTable = 'conflicting';
			}
			elseif ( $isOnDest ) {
				$transferRisk = 'okay';
				$pageTable = 'identical';
			}
			else {
				$transferRisk = 'good';
				$pageTable = 'unique';
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

			$transferPage = $this->queryTableCheckbox( 'dotransfer', $srcId, $pageTable );

			$srcAction = $this->queryTableRadio( 'srcaction', 'deletesrc', $srcId, $pageTable )
				. ' ' .  $this->queryTableRadio( 'srcaction', 'redirectsrc', $srcId, $pageTable )
				. ' ' .  $this->queryTableRadio( 'srcaction', 'donothingsrc', $srcId, $pageTable, true );

			// removed: <input type='hidden' name='transferids[]' value='$srcId' />
			// removed: $transferRiskTd (FIXME: remove logic generating this)
			$rowHtml = "<tr>
					<td>$links</td>
					<td style='white-space: nowrap;'>$transferPage</td>
					<td style='white-space: nowrap;'>$srcAction</td>
				</tr>";

			if ( $transferRisk === 'danger' ) {
				$pagesConflict[] = $rowHtml;
			} elseif ( $transferRisk === 'okay' ) {
				$pagesIdentical[] = $rowHtml;
			} else {
				$pagesSourceOnly[] = $rowHtml;
			}

			$numRows++;
		}

		#
		#
		# FIXME i18n
		#
		#
		$html = "<form id='$formid' action='$action' method='post' enctype='application/x-www-form-urlencoded'>
			<input type='hidden' name='destinationwiki' value='$destWiki' />";


		$pageTables = [
			'conflicting' => $pagesConflict,
			'identical' => $pagesIdentical,
			'unique' => $pagesSourceOnly
		];
		foreach ( $pageTables as $msgPart => $pages ) {

			$html .= Xml::element(
				'h3',
				[],
				$this->msg( 'ext-meza-transferpages-' . $msgPart . '-header' )
					->params( count( $pages ) )
					->parse()
			);

			$table = $this->getPageTable( $msgPart, $pages );

			if ( $totalPagesQueried <= $egMezaExtTransferPagesMaxPages ) {
				$html .= $this->getAllPagesButtons( $msgPart, true );
				$html .= $table;
			} else {
				$html .= $this->getAllPagesButtons( $msgPart, false );
				$html .= Xml::element(
					'p', [],
					$this->msg( 'ext-meza-transferpages-hide-many-pages' )
						->params( $totalPagesQueried, $egMezaExtTransferPagesMaxPages )->text()
				);
				$html .= '<div style="display:none;">' . $table . '</div>';
			}

		}

		$html .= '<button
			type="submit"
			tabindex="0"
			aria-disabled="false"
			name="ext-meza-transferpages-dotransfer-submit"
			value="Get transferable pages"
			>Do transfer</button>';
		$html .= "</form>";

		$output->addHTML( $html );
	}

	public function getPageTable( $msgPart, $pages ) {
		$tableStart = $this->getTableStart( $msgPart );
		$collapse = $msgPart === 'unique' ? '' : ' mw-collapsed';

		$html .= '<div class="mw-collapsible' . $collapse . '">';
		// $html .= '<span class="mw-collapsible-toggle" style="float:none;">Expand</span>';
		$html .=
			'<div class="mw-collapsible-content">'
				. '<div class="ext-meza-transferpages-overflow">'
					. $tableStart . implode( '', $pages ) . '</table>'
				. '</div>'
			. '</div>';
		$html .= '</div>';
		return $html;
	}

	public function getAllPagesButtons( $msgPart, $linksNotRadios = true ) {

		// at some point add to "identical" only:
		//    don't transfer but still perform source actions

		if ( $linksNotRadios ) {
			$glue = ' | ';
			$type = 'link';
		} else {
			$glue = '<br />';
			$type = 'radio';
		}

		$doTransferButtons = [
			$this->checkAllButton( 'dotransfer', '', $msgPart, true, $type ),
			$this->checkAllButton( 'dotransfer', '', $msgPart, false, $type ),
		];

		$srcActionButtons = [
			$this->checkAllButton( 'srcaction', 'donothingsrc', $msgPart, true, $type ),
			$this->checkAllButton( 'srcaction', 'deletesrc', $msgPart, true, $type ),
			$this->checkAllButton( 'srcaction', 'redirectsrc', $msgPart, true, $type ),
		];

		$output = $this->msg( 'ext-meza-transferpages-dotransfer-all-' . $msgPart )->text()
			. '<br />'
			. implode( $glue, $doTransferButtons )
			. '<br />'
			. $this->msg( 'ext-meza-transferpages-srcaction-all-' . $msgPart )->text()
			. '<br />'
			. implode( $glue, $srcActionButtons );

		return $output;
	}

	/**
	 * $id =
	 * 		dotransfer-conflicting-check-all
	 * 		dotransfer-identical-check-all
	 * 		dotransfer-unique-check-all
	 *
	 * 		dotransfer-conflicting-uncheck-all
	 * 		dotransfer-identical-uncheck-all
	 * 		dotransfer-unique-uncheck-all
	 *
	 * 		srcaction-donothingsrc-conflicting-check-all
	 * 		srcaction-donothingsrc-identical-check-all
	 * 		srcaction-donothingsrc-unique-check-all
	 *
	 * 		srcaction-deletesrc-conflicting-check-all
	 * 		srcaction-deletesrc-identical-check-all
	 * 		srcaction-deletesrc-unique-check-all
	 *
	 * 		srcaction-redirectsrc-conflicting-check-all
	 * 		srcaction-redirectsrc-identical-check-all
	 * 		srcaction-redirectsrc-unique-check-all
	 */
	public function checkAllButton( $action, $subaction, $contentCompareType, $check=true, $type='link' ) {

		if ( $subaction ) {
			$prefix = "$action-$subaction";
		} else {
			$prefix = $action;
		}

		if ( $check ) {
			$check = 'check';
		} else {
			$check = 'uncheck';
		}

		// examples:
		// dotransfer-unique-check-all or srcaction-deletesrc-identical-check-all
		$id = "$prefix-$contentCompareType-$check-all";

		// examples:
		// dotransfer-unique-all or srcaction-identical-all
		// note no "deletesrc" in second example and no check/uncheck from either
		$class = "$action-$contentCompareType-all";

		$label = $this->msg( $id )->text();
		if ( $type === 'radio' ) {
			$button = "<input type='radio' name='$id' id='$id' class='$class' value='1'>"
				. "<label for='$id'>$label</label>";
		} else {
			$button = "<a href='#' id='$id'>$label</a>";
		}

		return $button;
	}

	public function getTableStart( $msgPart ) {
		// removed: <th>Transfer risk</th>
		return "<table class='sortable wikitable jquery-tablesorter' style='width:100%;'>
			<tr>
				<th>Page</th>
				<th>
					Do transfer
				</th>
				<th>
					Action on source wiki
				</th>
			</tr>";
	}

	public function queryTableCheckbox( $type, $num, $table ) {
		$textMsgs = [
			'dotransfer-conflicting' => 'transfer page (danger!)',
			'dotransfer-identical' => 'transfer page (no change)',
			'dotransfer-unique' => 'transfer page',
		];
		$text = $textMsgs["$type-$table"];

		$name = $type . '[' . $num . ']';
		return "<input type='checkbox' name='$name' id='$type$num' class='$type $type-$table' value='1'>
			<label for='$type$num'>$text</label>";
	}

	public function queryTableRadio( $groupname, $value, $num, $pageTable, $checked=false ) {

		$textMsgs = [
			'donothingsrc' => 'do nothing',
		];

		// don't allow destructive actions when pages conflict
		if ( $pageTable !== 'conflicting' ) {
			$textMsgs['deletesrc'] = 'delete';
			$textMsgs['redirectsrc'] = 'redirect';
		}

		if ( ! isset( $textMsgs[$value] ) ) {
			return '';
		}

		$text = $textMsgs[$value];

		if ( $checked ) {
			$checked = 'checked="checked"';
		}
		else {
			$checked = '';
		}

		$name = $groupname . '[' . $num . ']';

		return "<input type='radio' name='$name' id='$groupname-$value-$num' class='$groupname $groupname-$value $groupname-$value-$pageTable $groupname-$num' value='$value' $checked>
			<label for='$groupname-$value-$num'>$text</label>";
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

		$srcQuery = $this->getWikiQueryPart( $srcWiki, $category, $namespace, true );
		$destQuery = $this->getWikiQueryPart( $destWiki, $category, $namespace, false );

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
					SUM( id ) AS src_id,
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

	public function getWikiQueryPart ( $wiki, $category, $namespace, $getPageId ) {

		// for destination wiki give an ID of zero. This can be summed with the
		// source wiki's ID to easily/quickly get the source wiki's page ID.
		if ( $getPageId ) {
			$pageIdQuery = "wiki_$wiki.page.page_id AS id";
		} else {
			$pageIdQuery = "0 as ID";
		}

		$query =
			"SELECT
				'$wiki' AS wiki,
				wiki_$wiki.page.page_namespace AS ns,
				wiki_$wiki.page.page_title AS title,
				$pageIdQuery,
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

	public function doTransfer () {
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$vars = $this->getRequest()->getValues();
		// $transferPages = isset( $vars['dotransfer'] ) ? $vars['dotransfer'] : [];

		global $wikiId;
		$destWiki = $vars['destinationwiki'];
		$titles = Title::newFromIDs( array_keys( $vars['dotransfer'] ) );

		$jobs = [];

		$output = $this->msg( 'ext-meza-transferpages-transferring-summary' )
			->params( $destWiki )
			->text();
		$output .= "<ul>";

		foreach ( $titles as $title ) {
			$id = $title->getArticleID();
			$srcAction = $vars['srcaction'][$id];

			$output .= '<li>';
			$output .= $this->msg( 'ext-meza-transferpages-transferring-' . $srcAction )
					->params( $title->getFullText(), $destWiki )
					->parse();
			$output .= '</li>';

			// prep the jobs
			$jobs[] = new MezaTransferPageJob(
				$title,
				[
					'src' => $wikiId,
					'dest' => $destWiki,
					'srcAction' => $srcAction,
				]
			);

		}
		$output .= '</ul>';

		JobQueueGroup::singleton()->push( $jobs );

		$this->getOutput()->addHTML( $output );


		// $this->getOutput()->addHTML( '<pre>' . print_r( $titles, true ) . '</pre>' );
	}

}
