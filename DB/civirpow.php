<?php

require_once 'DB/common.php';
require_once 'DB/mysqli.php';

/**
 * This is a wrapper for DB_mysqli which swaps the underlying connection
 * in accordance with the "replay-on-write" strategy.
 *
 * NOTE: PEAR DB is designed to pass around a single DSN string, but civirpow
 * needs to choose among many different/possible DSNs -- and it would be
 * a bit ugly to cram them all into one string. Instead, we  use a dummy
 * DSN ('civirpow://`) and then read the actual data from
 * a global variable $civirpow.
 */
class DB_civirpow extends DB_mysqli {

  var $phptype = 'civirpow';

  /**
   * @var \MysqlRpow\StateMachine
   */
  var $stateMachine;

  public function __construct() {
    $this->stateMachine = new \MysqlRpow\StateMachine();
    parent::__construct();
  }

  public function connect($dsn, $persistent = FALSE) {
    $config = $GLOBALS['civirpow'];

    switch ($this->stateMachine->getState()) {
      case \MysqlRpow\StateMachine::READ_ONLY:
        $dsns = $config['slaves'];
        break;

      case \MysqlRpow\StateMachine::READ_WRITE:
        $dsns = $config['masters'];
    }

    if (count($dsns) > 1) {
      shuffle($dsns);
    }

    return parent::connect(DB::parseDSN($dsns[0]), $persistent);
  }

  public function simpleQuery($query) {
    // echo "<pre>[[[$query]]]</pre> <br>";
    switch ($this->stateMachine->handle($query)) {
      case \MysqlRpow\StateMachine::READ_ONLY:
      case \MysqlRpow\StateMachine::READ_WRITE:
        return parent::simpleQuery($query);

      case \MysqlRpow\StateMachine::REPLAY:
        return $this->reconnectAndReplay();
    }
  }

  /**
   * Break the connection to the read-only slave; open a connection
   * to the read-write master; replay any buffered steps.
   *
   * @return mixed|null
   *   The result of executing the last replay-query.
   */
  protected function reconnectAndReplay() {
    $this->disconnect();
    $this->connect([]);

    $result = NULL;
    // dpm(['replaying' => $this->stateMachine->getBuffer()]);
    foreach ($this->stateMachine->getBuffer() as $bufferedSql) {
      $result = parent::simpleQuery($bufferedSql);
    }
    return $result;
  }

  /**
   * Force the system to use the read-write master connection.
   * If we're not using it already, then switch over to it.
   */
  protected function forceWriteMode() {
    if ($this->stateMachine->getState() === \MysqlRpow\StateMachine::READ_WRITE) {
      return;
    }

    $this->stateMachine->forceWriteMode();
    $this->reconnectAndReplay();
  }

  public function commit() {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::commit();
  }

  public function rollback() {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::rollback();
  }

  public function getSequenceName($sqn) {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::getSequenceName($sqn);
  }

  public function tableInfo($result, $mode = NULL) {
    // Not sure if this is needed, but it's a fair precaution.
    $this->forceWriteMode();
    return parent::tableInfo();
  }

}