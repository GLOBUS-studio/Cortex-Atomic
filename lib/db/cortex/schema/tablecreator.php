<?php

/**
 *  Cortex Schema Builder - TableCreator
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.0
 *  @date 29.03.2026
 */

namespace DB\Cortex\Schema;

class TableCreator extends TableBuilder {

	const
		TEXT_TableAlreadyExists="Table `%s` already exists. Cannot create it.";

	protected $charset='utf8';
	protected $collation='unicode';

	public function setCharset($charset,$collation='unicode') {
		$this->charset=$charset;
		$this->collation=$collation;
	}

	/**
	 * generate SQL query for creating a basic table, containing an ID serial field
	 * and execute it if $exec is true, otherwise just return the generated query string
	 * @param bool $exec
	 * @return bool|TableModifier|string
	 */
	public function build($exec=TRUE) {
		// check if already existing
		if ($exec && in_array($this->name,$this->schema->getTables())) {
			trigger_error(sprintf(self::TEXT_TableAlreadyExists,$this->name),
				E_USER_ERROR);
			return FALSE;
		}
		$cols='';
		if (!empty($this->columns))
			foreach ($this->columns as $cname=>$column) {
				// no defaults for TEXT type
				if ($column->default!==FALSE &&
					is_int(strpos(strtoupper($column->type),'TEXT'))) {
					trigger_error(sprintf(self::TEXT_NoDefaultForTEXT,$column->name),
						E_USER_ERROR);
					return FALSE;
				}
				$cols.=', '.$column->getColumnQuery();
			}
		$table=$this->db->quotekey($this->name);
		$id=$this->db->quotekey($this->increments);
		$cmd=[
			'sqlite2?|sybase|dblib'=>
				"CREATE TABLE $table ($id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT".
				$cols.");",
			'mysql'=>
				"CREATE TABLE $table ($id INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT".
				$cols.") DEFAULT CHARSET=$this->charset COLLATE ".$this->charset."_".
				$this->collation."_ci;",
			'pgsql'=>
				"CREATE TABLE $table ($id SERIAL PRIMARY KEY".$cols.");",
			'mssql|odbc|sqlsrv'=>
				"CREATE TABLE $table ($id INT IDENTITY CONSTRAINT PK_".$this->name.
				"_ID PRIMARY KEY".$cols.");",
			'ibm'=>
				"CREATE TABLE $table ($id INTEGER AS IDENTITY NOT NULL $cols, PRIMARY KEY($id));",
		];
		$query=$this->findQuery($cmd);
		// composite key for sqlite
		if (count($this->pkeys)>1 && strpos($this->db->driver(),'sqlite')!==FALSE) {
			$pk_string=implode(', ',$this->pkeys);
			$query="CREATE TABLE $table ($id INTEGER NULL".$cols.
				", PRIMARY KEY ($pk_string) );";
			$newTable=new TableModifier($this->name,$this->schema);
			// auto-incrementation in composite primary keys
			$pk_queries=$newTable->_sqlite_increment_trigger($this->increments);
			$this->queries=array_merge($this->queries,$pk_queries);
		}
		array_unshift($this->queries,$query);
		// indexes
		foreach ($this->columns as $cname=>$column)
			if ($column->index)
				$this->addIndex($cname,$column->unique);
		if (!$exec)
			return $this->queries;
		$this->db->exec($this->queries);
		return isset($newTable)?$newTable:new TableModifier($this->name,$this->schema);
	}

	/**
	 * create index on one or more columns
	 * @param string|array $columns Column(s) to be indexed
	 * @param bool $unique Unique index
	 * @param int $length index length for text fields in mysql
	 */
	public function addIndex($columns,$unique=FALSE,$length=20) {
		if (!is_array($columns))
			$columns=[$columns];
		$cols=$this->columns;
		foreach ($cols as &$col)
			$col=$col->getColumnArray();
		parent::_addIndex($columns,$cols,$unique,$length);
	}

}
