<?php

class Db
{
  protected static $_instance;
  private $conn;
  private $stats;
  private $emode;
  private $exname;

  private $defaults = array(
    'host'      => DB_SERVER,
    'user'      => DB_USER,
    'pass'      => DB_PASSWORD,
    'db'        => DB_DATABASE,
    'port'      => NULL,
    'socket'    => NULL,
    'pconnect'  => FALSE,
    'charset'   => 'utf8',
    'errmode'   => 'error', //or exception
    'exception' => 'Exception', //Exception class name,
    'tables'    => array()
  );

  const RESULT_ASSOC = MYSQLI_ASSOC;
  const RESULT_NUM   = MYSQLI_NUM;

  public static function getInstance() 
  {
    if (self::$_instance === null)
      self::$_instance = new self; 
    
    return self::$_instance;
  }
  
  private function __clone() 
  { 

  }
        
  private function __wakeup() 
  {
    
  }

  function __construct($opt = array())
  {
    $opt = array_merge($this->defaults,$opt);

    $this->emode  = $opt['errmode'];
    $this->exname = $opt['exception'];

    if ($opt['pconnect'])
    {
      $opt['host'] = "p:".$opt['host'];
    }

    @$this->conn = mysqli_connect($opt['host'], $opt['user'], $opt['pass'], $opt['db'], $opt['port'], $opt['socket']);
    if ( !$this->conn )
    {
      $this->error(mysqli_connect_errno()." ".mysqli_connect_error());
    }

    mysqli_set_charset($this->conn, $opt['charset']) or $this->error(mysqli_error($this->conn));
    unset($opt); // I am paranoid
  }

  public function connect($opt = array())
  {
    if(empty($opt))
     $opt = $this->defaults;
     
    @$this->conn = mysqli_connect($opt['host'], $opt['user'], $opt['pass'], $opt['db'], $opt['port'], $opt['socket']);
    if ( !$this->conn )
    {
      $this->error(mysqli_connect_errno()." ".mysqli_connect_error());
    }

    mysqli_set_charset($this->conn, $opt['charset']) or $this->error(mysqli_error($this->conn));
    unset($opt);
  }

  public function disconnect()
  {
    mysqli_close($this->conn);
  }  

  public function query()
  { 
    return $this->rawQuery($this->prepareQuery(func_get_args()));
  }

  public function fetch($result,$mode=self::RESULT_ASSOC)
  {
    return mysqli_fetch_array($result, $mode);
  }

  public function affectedRows()
  {
    return mysqli_affected_rows ($this->conn);
  }

  public function insertId()
  {
    return mysqli_insert_id($this->conn);
  }

  public function numRows($result)
  {
    return mysqli_num_rows($result);
  }

  public function free($result)
  {
    mysqli_free_result($result);
  }

  public function getOne()
  {
    $query = $this->prepareQuery(func_get_args());
    if ($res = $this->rawQuery($query))
    {
      $row = $this->fetch($res);
      if (is_array($row)) {
        return reset($row);
      }
      $this->free($res);
    }
    return FALSE;
  }

  public function getRow()
  {
    $query = $this->prepareQuery(func_get_args());
    if ($res = $this->rawQuery($query)) {
      $ret = $this->fetch($res);
      $this->free($res);
      return $ret;
    }
    return FALSE;
  }

  public function getCol()
  {
    $ret   = array();
    $query = $this->prepareQuery(func_get_args());
    if ( $res = $this->rawQuery($query) )
    {
      while($row = $this->fetch($res))
      {
        $ret[] = reset($row);
      }
      $this->free($res);
    }
    return $ret;
  }

  public function getAll()
  {
    $ret   = array();
    $query = $this->prepareQuery(func_get_args());
    if ( $res = $this->rawQuery($query) )
    {
      while($row = $this->fetch($res))
      {
        $ret[] = $row;
      }
      $this->free($res);
    }
    return $ret;
  }

  public function getInd()
  {
    $args  = func_get_args();
    $index = array_shift($args);
    $query = $this->prepareQuery($args);

    $ret = array();
    if ( $res = $this->rawQuery($query) )
    {
      while($row = $this->fetch($res))
      {
        $ret[$row[$index]] = $row;
      }
      $this->free($res);
    }
    return $ret;
  }

  public function getIndCol()
  {
    $args  = func_get_args();
    $index = array_shift($args);
    $query = $this->prepareQuery($args);

    $ret = array();
    if ( $res = $this->rawQuery($query) )
    {
      while($row = $this->fetch($res))
      {
        $key = $row[$index];
        unset($row[$index]);
        $ret[$key] = reset($row);
      }
      $this->free($res);
    }
    return $ret;
  }

  public function parse()
  {
    return $this->prepareQuery(func_get_args());
  }

  public function whiteList($input,$allowed,$default=FALSE)
  {
    $found = array_search($input,$allowed);
    return ($found === FALSE) ? $default : $allowed[$found];
  }

  public function filterArray($input,$allowed)
  {
    foreach(array_keys($input) as $key )
    {
      if ( !in_array($key,$allowed) )
      {
        unset($input[$key]);
      }
    }
    return $input;
  }

  public function lastQuery()
  {
    $last = end($this->stats);
    return $last['query'];
  }

  public function getStats()
  {
    return $this->stats;
  }

  private function rawQuery($query)
  {
    $start = microtime(TRUE);
    $res   = mysqli_query($this->conn, $query);
    $timer = microtime(TRUE) - $start;

    $this->stats[] = array(
      'query' => $query,
      'start' => $start,
      'timer' => $timer,
    );
    if (!$res)
    {
      $error = mysqli_error($this->conn);
      
      end($this->stats);
      $key = key($this->stats);
      $this->stats[$key]['error'] = $error;
      $this->cutStats();
      
      $this->error("$error. Full query: [$query]");
    }
    $this->cutStats();
    return $res;
  }

  private function prepareQuery($args)
  {
    $query = '';
    $raw   = array_shift($args);
    $array = preg_split('~(\?[nsiuap])~u',$raw,null,PREG_SPLIT_DELIM_CAPTURE);
    $anum  = count($args);
    $pnum  = floor(count($array) / 2);
    if ( $pnum != $anum )
    {
      $this->error("Number of args ($anum) doesn't match number of placeholders ($pnum) in [$raw]");
    }

    foreach ($array as $i => $part)
    {
      if ( ($i % 2) == 0 )
      {
        $query .= $part;
        continue;
      }

      $value = array_shift($args);
      switch ($part)
      {
        case '?n':
          $part = $this->escapeIdent($value);
          break;
        case '?s':
          $part = $this->escapeString($value);
          break;
        case '?i':
          $part = $this->escapeInt($value);
          break;
        case '?a':
          $part = $this->createIN($value);
          break;
        case '?u':
          $part = $this->createSET($value);
          break;
        case '?p':
          $part = $value;
          break;
      }
      $query .= $part;
    }
    return $query;
  }

  private function escapeInt($value)
  {
    if ($value === NULL)
    {
      return 'NULL';
    }
    if(!is_numeric($value))
    {
      $this->error("Integer (?i) placeholder expects numeric value, ".gettype($value)." given");
      return FALSE;
    }
    if (is_float($value))
    {
      $value = number_format($value, 0, '.', ''); // may lose precision on big numbers
    } 
    return $value;
  }

  private function escapeString($value)
  {
    if ($value === NULL)
    {
      return 'NULL';
    }
    return  "'".mysqli_real_escape_string($this->conn,$value)."'";
  }

  private function escapeIdent($value)
  {
    if ($value)
    {
      return "`".str_replace("`","``",$value)."`";
    } else {
      $this->error("Empty value for identifier (?n) placeholder");
    }
  }

  private function createIN($data)
  {
    if (!is_array($data))
    {
      $this->error("Value for IN (?a) placeholder should be array");
      return;
    }
    if (!$data)
    {
      return 'NULL';
    }
    $query = $comma = '';
    foreach ($data as $value)
    {
      $query .= $comma.$this->escapeString($value);
      $comma  = ",";
    }
    return $query;
  }

  private function createSET($data)
  {
    if (!is_array($data))
    {
      $this->error("SET (?u) placeholder expects array, ".gettype($data)." given");
      return;
    }
    if (!$data)
    {
      $this->error("Empty array for SET (?u) placeholder");
      return;
    }
    $query = $comma = '';
    foreach ($data as $key => $value)
    {
      $query .= $comma.$this->escapeIdent($key).'='.$this->escapeString($value);
      $comma  = ",";
    }
    return $query;
  }

  private function error($err)
  {
    $err  = __CLASS__.": ".$err;

    if ( $this->emode == 'error' )
    {
      $err .= ". Error initiated in ".$this->caller().", thrown";
      trigger_error($err,E_USER_ERROR);
    } else {
      throw new $this->exname($err);
    }
  }

  private function caller()
  {
    $trace  = debug_backtrace();
    $caller = '';
    foreach ($trace as $t)
    {
      if ( isset($t['class']) && $t['class'] == __CLASS__ )
      {
        $caller = $t['file']." on line ".$t['line'];
      } else {
        break;
      }
    }
    return $caller;
  }

  private function cutStats()
  {
    if ( count($this->stats) > 100 )
    {
      reset($this->stats);
      $first = key($this->stats);
      unset($this->stats[$first]);
    }
  }


//USER METHODS BEGIN
  function getTableFields($table)
  {
    if(isset($this->tables[$table]))
      return $this->tables[$table];
    
    $fields = array();
    $tableFields = $this->getAll("SHOW COLUMNS FROM ?n", $table);
    if($tableFields && !empty($tableFields))
    {
      foreach($tableFields as $field)
        $fields[] = $field['Field'];

      $this->tables[$table] = $fields;
      
      return ($fields);
    }
    else
      return false;
  }

  function insert($table, $data)
  {
    $tablefields = $this->getTableFields($table);
    if(!$tablefields)
      return false;

    $fields = "";
    $values = "";
    
    foreach($data as $field => &$value)
    {
      if(in_array($field, $tablefields))    
      {
        $value = trim($value);

        if(gettype($value) == "integer")
          $parsedValue = $this->parse("?i", $value);
        elseif(gettype($value) == "string" || gettype($value) == "double")
          $parsedValue = $this->parse("?s", trim($value));//$productName = iconv("UTF-8","UTF-8//IGNORE", trim($productName));
        else
          continue;
        
        if(!empty($fields)) 
        { 
          $fields .= ", "; 
          $values .= ", "; 
        }

        $fields .= $this->parse("?n", $field);
        $values .= $parsedValue;
      }      
    }

    if(!$fields || !$values)
      return false;
    
    if($this->query("INSERT INTO ?n (?p) VALUES (?p)", $table, $fields, $values))
      return $this->insertId();
    else
      return false;
  }

  function update($table, $id, $data)
  {
    
    $tablefields = $this->getTableFields($table);
    if(!$tablefields)
      return false;
    
    $values = "";

    foreach ($data as $field => &$value)
    {
      if(in_array($field, $tablefields))
      {
        $value = trim($value);

        if(gettype($value) == "integer")
          $parsedValue = $this->parse("?i", $value);
        elseif(gettype($value) == "string" || gettype($value) == "double")
          $parsedValue = $this->parse("?s", trim($value));
        else
          continue;
        
        if(!empty($values)) $values .= ", ";
        $values .= $this->parse("?n", $field)." = $parsedValue";
      }
    }

    if(!$values)
      return false;

    if($this->query("UPDATE ?n SET ?p WHERE `id` = ?i", $table, $values, $id))
      return true;
    else
      return false;
  }

  function getRowById($table, $id)
  {
    return($this->getRow("SELECT * FROM ?n WHERE `id` = ?i", $table, $id));
  }

  function deleteById($table, $id)
  {
    $this->query("DELETE FROM ?n WHERE `id` = ?i", $table, $id);
  }

  function setVar($name, $value)
  {
    if(gettype($value) == "integer")
      $parsedValue = $this->parse("?i", $value);
    elseif(gettype($value) == "string" || gettype($value) == "double")
      $parsedValue = $this->parse("?s", $value);
    else
      return false;

    $this->query("UPDATE `updates` SET `value` = $value, `datetime` = NOW() WHERE `name` = ?s", $name);   
  }

  function getVar($name)
  {
    return($this->getRow("SELECT value, datetime, TIME_TO_SEC(TIMEDIFF(NOW(), datetime)) as elapsed FROM `updates` WHERE name =?s", $name));    
  }

  function getVars()
  {
    return($this->getInd("name", "SELECT *, TIME_TO_SEC(TIMEDIFF(NOW(), datetime)) as elapsed FROM `updates` ORDER BY `id`"));
  }
//USER METHODS END
}
?>
