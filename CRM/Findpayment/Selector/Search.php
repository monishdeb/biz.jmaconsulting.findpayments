<?php

/**
 * Class to render payment search results.
 */
class CRM_Findpayment_Selector_Search extends CRM_Core_Selector_Base implements CRM_Core_Selector_API {

  /**
   * Array of action links.
   *
   * @var array
   */
  static $_links = NULL;

  /**
   * We use desc to remind us what that column is, name is used in the tpl
   *
   * @var array
   */
  static $_columnHeaders;

  /**
   * Properties of contact we're interested in displaying
   * @var array
   */
  static $_properties = array(
    'id',
    'sort_name',
    'invoice_number',
    'contact_id',
    'contribution_id',
    'financialtrxn_trxn_date',
    'financialtrxn_total_amount',
    'financialtrxn_currency',
    'financialtrxn_trxn_id',
    'financialtrxn_status_id',
    'financialtrxn_payment_processor_id',
    'financialtrxn_payment_instrument_id',
    'financialtrxn_card_type_id',
    'financialtrxn_check_number',
    'financialtrxn_pan_truncation',
  );

  /**
   * Are we restricting ourselves to a single contact
   *
   * @var boolean
   */
  protected $_single = FALSE;

  /**
   * Are we restricting ourselves to a single contact
   *
   * @var boolean
   */
  protected $_limit = NULL;

  /**
   * What context are we being invoked from
   *
   * @var string
   */
  protected $_context = NULL;

  /**
   * What component context are we being invoked from
   *
   * @var string
   */
  protected $_compContext = NULL;

  /**
   * QueryParams is the array returned by exportValues called on
   * the HTML_QuickForm_Controller for that page.
   *
   * @var array
   */
  public $_queryParams;

  /**
   * Represent the type of selector
   *
   * @var int
   */
  protected $_action;


  /**
   * The query object
   *
   * @var string
   */
  protected $_query;


  /**
   * Class constructor.
   *
   * @param array $queryParams
   *   Array of parameters for query.
   * @param \const|int $action - action of search basic or advanced.
   * @param string $contributionClause
   *   If the caller wants to further restrict the search (used in contributions).
   * @param bool $single
   *   Are we dealing only with one contact?.
   * @param int $limit
   *   How many contributions do we want returned.
   *
   * @param string $context
   * @param null $compContext
   *
   * @return \CRM_Contribute_Selector_Search
   */
  public function __construct(
    &$queryParams,
    $action = CRM_Core_Action::NONE,
    $contributionClause = NULL,
    $single = FALSE,
    $limit = NULL,
    $context = 'search',
    $compContext = NULL
  ) {

    // submitted form values
    $this->_queryParams = &$queryParams;

    $this->_single = $single;
    $this->_limit = $limit;
    $this->_context = $context;
    $this->_compContext = $compContext;

    $this->_financialTrxnClause = " civicrm_financial_trxn.is_payment = 1 ";

    // type of selector
    $this->_action = $action;
    $this->_query = new CRM_Contact_BAO_Query(
      $this->_queryParams,
      CRM_Findpayment_BAO_Query::selectorReturnProperties(),
      NULL, FALSE, FALSE
    );

    $this->_query->_tables['civicrm_financial_trxn'] = $this->_query->_whereTables['civicrm_financial_trxn'] = 1;
    $this->_query->_tables['civicrm_contribution'] = $this->_query->_whereTables['civicrm_contribution'] = 1;
    $this->_query->_distinctComponentClause = " civicrm_financial_trxn.id ";
    $this->_query->_groupByComponentClause = " GROUP BY civicrm_financial_trxn.id ";
  }

  /**
   * This method returns the links that are given for each search row.
   * currently the links added for each row are
   *
   * - Edit
   *
   * @return array
   */
  public static function &links() {
    if (!(self::$_links)) {
      self::$_links = array(
        CRM_Core_Action::UPDATE => array(
          'name' => ts('Edit'),
          'url' => 'civicrm/payment/edit',
          'qs' => "reset=1&id=%%id%%",
          'title' => ts('Edit Payment'),
        ),
      );
    }
    return self::$_links;
  }

  /**
   * Getter for array of the parameters required for creating pager.
   *
   * @param $action
   * @param array $params
   */
  public function getPagerParams($action, &$params) {
    $params['status'] = ts('Payments') . ' %%StatusMessage%%';
    $params['csvString'] = NULL;
    if ($this->_limit) {
      $params['rowCount'] = $this->_limit;
    }
    else {
      $params['rowCount'] = CRM_Utils_Pager::ROWCOUNT;
    }

    $params['buttonTop'] = 'PagerTopButton';
    $params['buttonBottom'] = 'PagerBottomButton';
  }

  /**
   * Returns total number of rows for the query.
   *
   * @param string $action
   *
   * @return int
   *   Total number of rows
   */
  public function getTotalCount($action) {
    return $this->_query->searchQuery(0, 0, NULL,
      TRUE, FALSE,
      FALSE, FALSE,
      FALSE,
      $this->_financialTrxnClause
    );
  }

  /**
   * Returns all the rows in the given offset and rowCount.
   *
   * @param string $action
   *   The action being performed.
   * @param int $offset
   *   The row number to start from.
   * @param int $rowCount
   *   The number of rows to return.
   * @param string $sort
   *   The sql string that describes the sort order.
   * @param string $output
   *   What should the result set include (web/email/csv).
   *
   * @return int
   *   the total number of rows for this action
   */
  public function &getRows($action, $offset, $rowCount, $sort, $output = NULL) {
    $result = $this->_query->searchQuery($offset, $rowCount, $sort,
      FALSE, FALSE,
      FALSE, FALSE,
      FALSE,
      $this->_financialTrxnClause
    );
    // process the result of the query
    $rows = array();

    $permissions = array(CRM_Core_Permission::VIEW);
    if (CRM_Core_Permission::check('edit contributions')) {
      $permissions[] = CRM_Core_Permission::EDIT;
    }
    if (CRM_Core_Permission::check('delete in CiviContribute')) {
      $permissions[] = CRM_Core_Permission::DELETE;
    }
    $mask = CRM_Core_Action::mask($permissions);

    // get all contribution status
    $contributionStatuses = CRM_Core_OptionGroup::values('contribution_status',
      FALSE, FALSE, FALSE, NULL, 'name', FALSE
    );

    while ($result->fetch()) {
      $links = self::links();
      $checkLineItem = FALSE;
      $row = array();

      // the columns we are interested in
      foreach (self::$_properties as $property) {
        if (property_exists($result, $property)) {
          $row[$property] = $result->$property;
        }
      }

      $paidByLabel = CRM_Core_PseudoConstant::getLabel('CRM_Core_BAO_FinancialTrxn', 'payment_instrument_id', $row['financialtrxn_payment_instrument_id']);
      if (!empty($row['financialtrxn_card_type_id'])) {
        $creditCardType = CRM_Core_PseudoConstant::getLabel('CRM_Core_BAO_FinancialTrxn', 'card_type_id', $row['financialtrxn_card_type_id']);
        $pantruncation = '';
        if ($row['financialtrxn_pan_truncation']) {
          $pantruncation = ": " . $row['financialtrxn_pan_truncation'];
        }
        $paidByLabel .= " ({$creditCardType}{$pantruncation})";
      }
      elseif (!empty($row['financialtrxn_check_number'])) {
        $paidByLabel .= sprintf(" (#%s)", $row['financialtrxn_check_number']);
      }
      $row['financialtrxn_payment_instrument_id'] = $paidByLabel;

      // add contribution status name
      $row['status_name'] = CRM_Utils_Array::value($row['financialtrxn_status_id'],
        $contributionStatuses
      );

      $row['checkbox'] = CRM_Core_Form::CB_PREFIX . $result->id;

      $actions = array(
        'id' => $result->id,
      );

      $row['action'] = CRM_Core_Action::formLink(
        $links,
        $mask, $actions,
        ts('more'),
        FALSE
      );

      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * @inheritDoc
   */
  public function getQILL() {
    return $this->_query->qill();
  }

  /**
   * Returns the column headers as an array of tuples:
   * (name, sortName (key to the sort array))
   *
   * @param string $action
   *   The action being performed.
   * @param string $output
   *   What should the result set include (web/email/csv).
   *
   * @return array
   *   the column headers that need to be displayed
   */
  public function &getColumnHeaders($action = NULL, $output = NULL) {
    $pre = array();
    self::$_columnHeaders = array(
      array(
        'name' => ts('Name'),
        'sort' => 'sort_name',
        'direction' => CRM_Utils_Sort::DONTCARE,
      ),
      array(
        'name' => ts('Invoice Number'),
        'sort' => 'invoice_number',
        'direction' => CRM_Utils_Sort::DONTCARE,
        'field_name' => 'invoice_number',
      ),
      array(
        'name' => ts('Payment Amount'),
        'sort' => 'financialtrxn_total_amount',
        'direction' => CRM_Utils_Sort::DONTCARE,
        'field_name' => 'financialtrxn_total_amount',
      ),
      array(
        'name' => ts('Payment Method'),
        'sort' => 'financialtrxn_payment_instrument_id',
        'direction' => CRM_Utils_Sort::DONTCARE,
        'field_name' => 'financialtrxn_payment_instrument_id',
      ),
      array(
        'name' => ts('Transaction Date'),
        'sort' => 'financialtrxn_trxn_date',
        'type' => 'date',
        'direction' => CRM_Utils_Sort::DONTCARE,
        'field_name' => 'financialtrxn_trxn_date',
      ),
      array(
        'name' => ts('Transaction ID'),
        'sort' => 'financialtrxn_trxn_id',
        'direction' => CRM_Utils_Sort::DONTCARE,
        'field_name' => 'financialtrxn_trxn_id',
      ),
      array(
        'name' => ts('Status'),
        'sort' => 'status_name',
        'direction' => CRM_Utils_Sort::DONTCARE,
        'field_name' => 'status_name',
      ),
      array(
        'desc' => ts('Actions'),
        'type' => 'actions'
      ),
    );

    foreach (array_keys(self::$_columnHeaders) as $index) {
      // Add weight & space it out a bit to allow headers to be inserted.
      self::$_columnHeaders[$index]['weight'] = $index * 10;
    }

    return self::$_columnHeaders;
  }

  /**
   * @return mixed
   */
  public function alphabetQuery() {
    return $this->_query->searchQuery(NULL, NULL, NULL, FALSE, FALSE, TRUE);
  }

  /**
   * @return string
   */
  public function &getQuery() {
    return $this->_query;
  }

  /**
   * Name of export file.
   *
   * @param string $output
   *   Type of output.
   *
   * @return string
   *   name of the file
   */
  public function getExportFileName($output = 'csv') {
    return ts('CiviCRM Payment Search');
  }

}
