<?php

namespace Firebird;

use Firebird\Query\Builder as QueryBuilder;
use Firebird\Query\Grammars\Firebird15Grammar as QueryGrammar10;
use Firebird\Query\Grammars\Firebird25Grammar as QueryGrammar20;
use Firebird\Query\Grammars\Firebird30Grammar as QueryGrammar30;
use Firebird\Query\Processors\FirebirdProcessor as Processor;
use Firebird\Schema\Builder as SchemaBuilder;
use Firebird\Schema\Grammars\FirebirdGrammar as SchemaGrammar;
use PDO;
use Throwable;

class Connection extends \Illuminate\Database\Connection
{

  /**
   * Firebird messages that mean the attachment is dead. Laravel's LostConnectionDetector does not
   * know them (of the Firebird messages it only has "Error writing data to the connection"), so
   * without this no reconnect + retry happens. Typical case: a long-lived process (Horizon worker,
   * Octane) holds an attachment that a nightly `gfix -shut -force` killed; the first statement in
   * the morning returns -902 "database <name> shutdown" and every next one on the same handle
   * -901 "invalid request handle".
   *
   * @var list<string> regexes matched against the PDOException message (which carries no SQL)
   */
  protected const LOST_CONNECTION_PATTERNS = [
    '/\bdatabase\b.*\bshutdown\b/',                // isc_shutdown: database @1 shutdown
    '/\bconnection shutdown\b/',                   // isc_att_shutdown
    '/\binvalid request handle\b/',                // isc_bad_req_handle
    '/\binvalid database handle\b/',               // isc_bad_db_handle
    '/\bconnection lost to database\b/',           // isc_lost_db_connection
    '/\bUnable to complete network request\b/',    // isc_network_error
    '/\bError reading data from the connection\b/', // isc_net_read_err
  ];

  /**
   * Firebird Engine version
   *
   * @var string
   */
  private $engine_version = null;

  /**
   * Whether the exception was caused by a lost connection (Laravel then reconnects and retries
   * the query, outside a transaction). Recognises the Firebird messages on top of Laravel's list.
   *
   * @param \Throwable $e
   * @return bool
   */
  protected function causedByLostConnection(Throwable $e)
  {
    if (parent::causedByLostConnection($e)) {
      return true;
    }

    $message = $e->getMessage();
    foreach (self::LOST_CONNECTION_PATTERNS as $pattern) {
      if (preg_match($pattern, $message)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get engine version
   *
   * @return string
   */
  protected function getEngineVersion()
  {
    if (!$this->engine_version) {
      // 'engine_version' je izvorni ključ paketa; 'version' je ključ koji koriste
      // aplikacione konfiguracije (config/database.php). Bez njih ide upit na server.
      $this->engine_version = $this->config['engine_version'] ?? $this->config['version'] ?? null;
    }
    if (!$this->engine_version) {
      $sql = "SELECT RDB\$GET_CONTEXT(?, ?) FROM RDB\$DATABASE";
      $sth = $this->getPdo()->prepare($sql);
      $sth->execute(['SYSTEM', 'ENGINE_VERSION']);
      $this->engine_version = $sth->fetchColumn();
      $sth->closeCursor();
    }
    return $this->engine_version;
  }

  /**
   * Get major engine version
   * It allows you to determine the features of the engine.
   *
   * @return int
   */
  protected function getMajorEngineVersion()
  {
    $version = $this->getEngineVersion();
    $parts = explode('.', $version);
    return (int)$parts[0];
  }

  /**
   * Get the default query grammar instance
   *
   * @return QueryGrammar10|QueryGrammar20|QueryGrammar30
   */
  protected function getDefaultQueryGrammar()
  {
    switch ($this->getMajorEngineVersion()) {
      case 1:
        return new QueryGrammar10($this);
        break;
      case 3:
        return new QueryGrammar30($this);
        break;
      default:
        return new QueryGrammar20($this);
        break;
    }
  }

  /**
   * Get the default post processor instance.
   *
   * @return \Firebird\Query\Processors\FirebirdProcessor
   */
  protected function getDefaultPostProcessor()
  {
    return new Processor;
  }

  /**
   * Get a schema builder instance for this connection.
   *
   * @return \Firebird\Schema\Builder
   */
  public function getSchemaBuilder()
  {
    if (is_null($this->schemaGrammar)) {
      $this->useDefaultSchemaGrammar();
    }

    return new SchemaBuilder($this);
  }

  /**
   * Get the default schema grammar instance.
   *
   * @return \Illuminate\Database\Grammar
   */
  protected function getDefaultSchemaGrammar()
  {
    // Laravel 12+: Grammar prima Connection u konstruktoru (prefiks tabele ide preko konekcije).
    return new SchemaGrammar($this);
  }

  /**
   * Get query builder
   *
   * @return \Firebird\Query\Builder
   */
  protected function getQueryBuilder()
  {
    $processor = $this->getPostProcessor();
    $grammar = $this->getQueryGrammar();

    return new QueryBuilder($this, $grammar, $processor);
  }

  /**
   * Get a new query builder instance.
   *
   * @return \Firebird\Query\Builder
   */
  public function query()
  {
    return $this->getQueryBuilder();
  }

  /**
   * Execute stored function
   *
   * @param string $function
   * @param array $values
   * @return mixed
   */
  public function executeFunction($function, ?array $values = null)
  {
    $query = $this->getQueryBuilder();

    return $query->executeFunction($function, $values);
  }

  /**
   * Execute stored procedure
   *
   * @param string $procedure
   * @param array $values
   */
  public function executeProcedure($procedure, ?array $values = null)
  {
    $query = $this->getQueryBuilder();

    $query->executeProcedure($procedure, $values);
  }

  /**
   * Start a new database transaction.
   *
   * @return void
   * @throws \Exception
   */
  public function beginTransaction()
  {
    // getPdo(), ne $this->pdo: Laravel otvara konekciju lenjo i dok prvi upit ne ode
    // $this->pdo je Closure (transakcija kao prva operacija u zahtevu).
    if ($this->transactions == 0 && $this->getPdo()->getAttribute(PDO::ATTR_AUTOCOMMIT) == 1) {
      $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
    }
    parent::beginTransaction();
  }

  /**
   * Commit the active database transaction.
   *
   * @return void
   */
  public function commit()
  {
    parent::commit();
    if ($this->transactions == 0 && $this->getPdo()->getAttribute(PDO::ATTR_AUTOCOMMIT) == 0) {
      $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
    }
  }

  /**
   * Rollback the active database transaction.
   *
   * @param int|null $toLevel
   * @return void
   * @throws \Exception
   */
  public function rollBack($toLevel = null)
  {
    parent::rollBack($toLevel);
    if ($this->transactions == 0 && $this->getPdo()->getAttribute(PDO::ATTR_AUTOCOMMIT) == 0) {
      $this->getPdo()->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
    }
  }
}
