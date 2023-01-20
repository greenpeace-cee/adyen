<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from adyen/xml/schema/CRM/Adyen/AdyenReportLine.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:6fdddd4d6f1767b26fcc81618bf165c0)
 */
use CRM_Adyen_ExtensionUtil as E;

/**
 * Database access object for the AdyenReportLine entity.
 */
class CRM_Adyen_DAO_AdyenReportLine extends CRM_Core_DAO {
  const EXT = E::LONG_NAME;
  const TABLE_ADDED = '';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'civicrm_adyen_report_line';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = TRUE;

  /**
   * Unique AdyenReportLine ID
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $id;

  /**
   * FK to AdyenNotification
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $adyen_notification_id;

  /**
   * Line number in report file
   *
   * @var int|string
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $line_number;

  /**
   * Date of the line
   *
   * @var string
   *   (SQL type: datetime)
   *   Note that values will be retrieved from the database as a string.
   */
  public $line_date;

  /**
   * Adyen Report Line (JSON)
   *
   * @var string
   *   (SQL type: longtext)
   *   Note that values will be retrieved from the database as a string.
   */
  public $content;

  /**
   * ID of report line status
   *
   * @var int|string
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $status_id;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'civicrm_adyen_report_line';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? E::ts('Adyen Report Lines') : E::ts('Adyen Report Line');
  }

  /**
   * Returns foreign keys and entity references.
   *
   * @return array
   *   [CRM_Core_Reference_Interface]
   */
  public static function getReferenceColumns() {
    if (!isset(Civi::$statics[__CLASS__]['links'])) {
      Civi::$statics[__CLASS__]['links'] = static::createReferenceColumns(__CLASS__);
      Civi::$statics[__CLASS__]['links'][] = new CRM_Core_Reference_Basic(self::getTableName(), 'adyen_notification_id', 'civicrm_adyen_notification', 'id');
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'links_callback', Civi::$statics[__CLASS__]['links']);
    }
    return Civi::$statics[__CLASS__]['links'];
  }

  /**
   * Returns all the column names of this table
   *
   * @return array
   */
  public static function &fields() {
    if (!isset(Civi::$statics[__CLASS__]['fields'])) {
      Civi::$statics[__CLASS__]['fields'] = [
        'id' => [
          'name' => 'id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('ID'),
          'description' => E::ts('Unique AdyenReportLine ID'),
          'required' => TRUE,
          'where' => 'civicrm_adyen_report_line.id',
          'table_name' => 'civicrm_adyen_report_line',
          'entity' => 'AdyenReportLine',
          'bao' => 'CRM_Adyen_DAO_AdyenReportLine',
          'localizable' => 0,
          'readonly' => TRUE,
          'add' => NULL,
        ],
        'adyen_notification_id' => [
          'name' => 'adyen_notification_id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Adyen Notification ID'),
          'description' => E::ts('FK to AdyenNotification'),
          'where' => 'civicrm_adyen_report_line.adyen_notification_id',
          'table_name' => 'civicrm_adyen_report_line',
          'entity' => 'AdyenReportLine',
          'bao' => 'CRM_Adyen_DAO_AdyenReportLine',
          'localizable' => 0,
          'FKClassName' => 'CRM_Adyen_DAO_AdyenNotification',
          'add' => NULL,
        ],
        'line_number' => [
          'name' => 'line_number',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Line Number'),
          'description' => E::ts('Line number in report file'),
          'required' => TRUE,
          'where' => 'civicrm_adyen_report_line.line_number',
          'table_name' => 'civicrm_adyen_report_line',
          'entity' => 'AdyenReportLine',
          'bao' => 'CRM_Adyen_DAO_AdyenReportLine',
          'localizable' => 0,
          'add' => NULL,
        ],
        'line_date' => [
          'name' => 'line_date',
          'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
          'title' => E::ts('Line Date'),
          'description' => E::ts('Date of the line'),
          'required' => FALSE,
          'where' => 'civicrm_adyen_report_line.line_date',
          'table_name' => 'civicrm_adyen_report_line',
          'entity' => 'AdyenReportLine',
          'bao' => 'CRM_Adyen_DAO_AdyenReportLine',
          'localizable' => 0,
          'html' => [
            'type' => 'Select Date',
          ],
          'add' => NULL,
        ],
        'content' => [
          'name' => 'content',
          'type' => CRM_Utils_Type::T_LONGTEXT,
          'title' => E::ts('Content'),
          'description' => E::ts('Adyen Report Line (JSON)'),
          'required' => TRUE,
          'where' => 'civicrm_adyen_report_line.content',
          'table_name' => 'civicrm_adyen_report_line',
          'entity' => 'AdyenReportLine',
          'bao' => 'CRM_Adyen_DAO_AdyenReportLine',
          'localizable' => 0,
          'serialize' => self::SERIALIZE_JSON,
          'html' => [
            'type' => 'TextArea',
          ],
          'add' => NULL,
        ],
        'status_id' => [
          'name' => 'status_id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Report Line Status'),
          'description' => E::ts('ID of report line status'),
          'required' => TRUE,
          'where' => 'civicrm_adyen_report_line.status_id',
          'default' => '1',
          'table_name' => 'civicrm_adyen_report_line',
          'entity' => 'AdyenReportLine',
          'bao' => 'CRM_Adyen_DAO_AdyenReportLine',
          'localizable' => 0,
          'html' => [
            'type' => 'Select',
          ],
          'pseudoconstant' => [
            'optionGroupName' => 'adyen_report_line_status',
            'optionEditPath' => 'civicrm/admin/options/adyen_report_line_status',
          ],
          'add' => NULL,
        ],
      ];
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'fields_callback', Civi::$statics[__CLASS__]['fields']);
    }
    return Civi::$statics[__CLASS__]['fields'];
  }

  /**
   * Return a mapping from field-name to the corresponding key (as used in fields()).
   *
   * @return array
   *   Array(string $name => string $uniqueName).
   */
  public static function &fieldKeys() {
    if (!isset(Civi::$statics[__CLASS__]['fieldKeys'])) {
      Civi::$statics[__CLASS__]['fieldKeys'] = array_flip(CRM_Utils_Array::collect('name', self::fields()));
    }
    return Civi::$statics[__CLASS__]['fieldKeys'];
  }

  /**
   * Returns the names of this table
   *
   * @return string
   */
  public static function getTableName() {
    return self::$_tableName;
  }

  /**
   * Returns if this table needs to be logged
   *
   * @return bool
   */
  public function getLog() {
    return self::$_log;
  }

  /**
   * Returns the list of fields that can be imported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &import($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, 'adyen_report_line', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of fields that can be exported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &export($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, 'adyen_report_line', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of indices
   *
   * @param bool $localize
   *
   * @return array
   */
  public static function indices($localize = TRUE) {
    $indices = [
      'UI_adyen_notification_line_number' => [
        'name' => 'UI_adyen_notification_line_number',
        'field' => [
          0 => 'adyen_notification_id',
          1 => 'line_number',
        ],
        'localizable' => FALSE,
        'unique' => TRUE,
        'sig' => 'civicrm_adyen_report_line::1::adyen_notification_id::line_number',
      ],
    ];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}