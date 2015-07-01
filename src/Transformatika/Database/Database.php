<?php
namespace Transformatika\Database; 

use Transformatika\Config\Config;

class Database
{
    protected $dsn; 
    protected $databaseHost; 
    protected $databasePort; 
    protected $databaseUser; 
    protected $databaseName; 
    protected $databasePassword; 
    protected $databaseType; 
    protected $pdo;
    protected $pdoStmt;
    protected $sql;
    protected $bind;
    protected $transactionStart;
    protected $resultFormat = 'object';
    
    public $error;
    
    public function __construct()
    {
        if($this->dsn === null){
            $config = new Config();
            $dbConfig = $config->getConfig('database');
            $this->databaseType     = $dbConfig['type'];
            $this->databaseHost     = $dbConfig['host'];
            $this->databaseUser     = $dbConfig['user'];
            $this->databasePassword = $dbConfig['password'];
            $this->databasePort     = $dbConfig['port'];
            $this->databaseName     = $dbConfig['name'];
            $this->dsn              = $this->databaseType.":host=".$this->databaseHost.";port=".$this->databasePort.";dbname=".$this->databaseName;
        }
        if($this->pdo === null){
            $this->connect();
        }
    }
    
    /**
     * Database Connection
     */   
    private function connect()
    {
        try{
            $this->pdo = new \PDO($this->dsn, $this->databaseUser, $this->databasePassword);
            return $this->pdo;
        }
        catch(PDOException $e){
            die($e->getMessage());
        }
    }
    
    /**
     * Set Result Format as Object
     * 
     * @return \Transformatika\Database\Database
     */
    public function setResultAsObject()
    {
        $this->resultFormat = 'object'; 
        return $this;
    }
    
    /**
     * Set Result Format as Array
     * 
     * @return \Transformatika\Database\Database
     */
    public function setResultAsArray()
    {
        $this->resultFormat = 'array';
        return $this;
    }
    
    /**
     * Begin Transaction
     * 
     * @return \Transformatika\ORM\ORM
     */
    public function beginTransaction()
    {
        $this->transactionStart = $this->pdo->beginTransaction();
        return $this;
    }
    
    /**
     * Manual Commit Transaction
     * 
     * @return \Transformatika\ORM\ORM
     */
    public function commit()
    {
        $this->pdo->commit();
        $this->transactionStart = null;
        return $this;
    }
    
    /**
     * Rollback Transaction
     * 
     * @return \Transformatika\ORM\ORM
     */
    public function rollback()
    {
        $this->pdo->rollBack();
        $this->transactionStart = null;
        return $this;
    }
    
    /**
     * Query SQL
     * 
     * @param string $statement
     * @param string $bind
     * @return \Transformatika\ORM\ORM
     */
    public function query($statement='',$bind='')
    {
        if($this->pdo !== null){
            $this->sql = trim($statement); 
            $this->bind = $this->cleanup($bind);
            $this->pdoStmt = $this->pdo->prepare($this->sql); 
            $this->pdoStmt->execute($this->bind);
            return $this;        
        }
    }
    
    /**
     * Cleanup bind params
     * 
     * @param unknown $bind
     */
    private function cleanup($bind) 
    {
        if (!is_array($bind)) {
            if (!empty($bind)) {
                $bind = array($bind);
            } else {
                $bind = array();
            }
        }
        return $bind;
    }
    
    /**
     * Filter table fields
     * 
     * @param string $table
     * @param array $info
     */
    private function filter($table, $info) 
    {
        if ($this->databaseType == 'sqlite') {
            $sql = "PRAGMA table_info('" . $table . "');";
            $key = "name";
        } elseif ($this->databaseType == 'mysql') {
            $sql = "DESCRIBE " . $table . ";";
            $key = "Field";
        } else {
            $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = '" . $table . "';";
            $key = "column_name";
        }
        
        if ($this->query($sql)) {
            $fields = array();
            $records = $this->pdoStmt->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($records as $record){
                $fields[] = $record[$key];
            }
                
            return array_values(array_intersect($fields, array_keys($info)));
        }
        return array();
    }
    
    /**
     * Fetch Records
     * 
     */
    public function fetchAll()
    {
        if($this->pdoStmt !== null){
            if($this->resultFormat === 'array'){
                $records = $this->pdoStmt->fetchAll(\PDO::FETCH_ASSOC);
            }else{
                $records = $this->pdoStmt->fetchAll(\PDO::FETCH_OBJ);
            }
            return $records;
        }
    }
    
    /**
     * Fetch Records
     * @return mixed
     */
    public function fetch()
    {
        if($this->pdoStmt !== null){
            if($this->resultFormat === 'array'){
                $records = $this->pdoStmt->fetch(\PDO::FETCH_ASSOC);
            }else{
                $records = $this->pdoStmt->fetch(\PDO::FETCH_OBJ);
            }
            return $records;
        }
    }
    
    /**
     * Count Records
     * @return number
     */
    public function counts()
    {
        if($this->pdoStmt !== null){
            $records = $this->pdoStmt->rowCount();
            return $records;
        }
    }
    
    /**
     * Insert into database
     * 
     * @param string $table
     * @param array $datas
     * @param array $bind
     * @return boolean
     */
    public function insert($table='',$datas='',$bind='')
    {
        if($this->transactionStart === null){
            $this->pdo->beginTransaction();
        }
        $fields = $this->filter($table, $datas);	
        
        $sql    = "INSERT INTO " . $table . " (" . implode($fields, ", ") . ") VALUES (:" . implode($fields, ", :") . ")";
        $bind = $this->cleanup($bind);
        foreach ($fields as $field){
            $bind[":$field"] = $datas[$field];
        }
        
        try{
            $this->query($sql, $bind);
            if($this->transactionStart === null){
                $this->pdo->commit();
            }
            return true;
        }
        catch(PDOException $e){
            $this->error = $e->getMessage(); 
            if($this->transactionStart === null){
                $this->pdo->commit();
            }
            return false;
        }
    }
    
    /**
     * Update Records
     * 
     * @param string $table
     * @param array $datas
     * @param string $parameter
     * @param array $bind
     * @return boolean
     */
    public function update($table='',$datas='',$parameter='',$bind='')
    {
        if($this->transactionStart === null){
            $this->pdo->beginTransaction();
        }
        $fields = $this->filter($table, $datas);
        
        $fieldSize = sizeof($fields);
        
        $sql = "UPDATE " . $table . " SET ";
        for ($f = 0; $f < $fieldSize; ++$f) {
            if ($f > 0)
                $sql .= ", ";
                $sql .= $fields[$f] . " = :update_" . $fields[$f];
        }
        $sql .= " " . $parameter . "";
        
        $bind = $this->cleanup($bind);
        foreach ($fields as $field){
            $bind[":$field"] = $datas[$field];
        }
        
        try{
            $this->query($sql, $bind);
            if($this->transactionStart === null){
                $this->pdo->commit();
            }
            return true;
        }
        catch(PDOException $e){
            $this->error = $e->getMessage();
            if($this->transactionStart === null){
                $this->pdo->commit();
            }
            return false;
        }
    }
    
    public function delete($table='',$parameter='')
    {
        $sql = 'DELETE FROM '.$table.' '.$parameter; 
        try{
            $this->query($sql);
            if($this->transactionStart === null){
                $this->pdo->commit();
            }
            return true;
        }
        catch(PDOException $e){
            $this->error = $e->getMessage();
            if($this->transactionStart === null){
                $this->pdo->commit();
            }
            return false;
        }
    }
    
    /**
     * Get PDO Error Msg
     * 
     * @return string 
     */
    public function showError()
    {
        return $this->error;
    }
    
    /**
     * View Result Format
     */
    public function getResultFormat()
    {
        return $this->resultFormat;
    }
    
    /**
     * Set Result Format
     * @param string $resultFormat
     * @return \Transformatika\Database\Database
     */
    public function setResultFormat($resultFormat)
    {
        $this->resultFormat = $resultFormat;
        return $this;
    }
    
    /**
     * Check if table exists
     * @param  string $table Table Name
     * @return boolean        [description]
     */
    public function table_exists($table){
        if($this->databaseType == 'pgsql'){
            $cekSQL = "SELECT relname FROM pg_class WHERE relname = '".$table."'";
            $this->query($cekSQL);
            $resCek = $this->counts();
        }else{
            $cekSQL = "SELECT *
						FROM information_schema.tables
						WHERE table_schema = '".Config::$database['dbname']."'
						    AND table_name = '".$table."'
						LIMIT 1;";
            $this->query($cekSQL);
            $resCek = $this->counts();
        }
         
        if((int) $resCek > 0){
            return true;
        }else{
            return false;
        }
    }
}