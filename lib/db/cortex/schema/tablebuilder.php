<?php

/**
 *  Cortex Schema Builder - Abstract TableBuilder
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.0
 *  @date 29.03.2026
 */

namespace DB\Cortex\Schema;

use Base;

abstract class TableBuilder {

	use DB_Utils;

	protected $columns,$pkeys,$queries,$increments,$rebuild_cmd,$suppress;
	public $name;
	/** @var Schema */
	public $schema;

	const
		TEXT_NoDefaultForTEXT="Column `%s` of type TEXT can't have a default value.",
		TEXT_ColumnExists="Cannot add the column `%s`. It already exists.";

	/**
	 * @param string $name
	 * @param Schema $schema
	 */
	public function __construct($name,Schema $schema) {
		$this->name=$name;
		$this->schema=$schema;
		$this->columns=[];
		$this->queries=[];
		$this->pkeys=['id'];
		$this->increments='id';
		$this->db=$schema->db;
	}

	/**
	 * generate SQL query and execute it if $exec is true
	 * @param bool $exec
	 */
	abstract public function build($exec=TRUE);

	/**
	 * add a new column to this table
	 * @param string|Column $key column name or object
	 * @param null|array $args optional config array
	 * @return Column
	 */
	public function addColumn($key,$args=NULL) {
		if ($key instanceof Column) {
			$args=$key->getColumnArray();
			$key=$key->name;
		}
		if (array_key_exists($key,$this->columns))
			trigger_error(sprintf(self::TEXT_ColumnExists,$key),E_USER_ERROR);
		$column=new Column($key,$this);
		if ($args)
			foreach ($args as $arg=>$val)
			    if (property_exists($column, $arg))
				$column->{$arg}=$val;
		// skip default pkey field
		if (count($this->pkeys)==1 && in_array($key,$this->pkeys))
			return $column;
		return $this->columns[$key]=&$column;
	}

	/**
	 * create index on one or more columns
	 * @param string|array $index_cols Column(s) to be indexed
	 * @param              $search_cols
	 * @param bool $unique Unique index
	 * @param int $length index length for text fields in mysql
	 */
	protected function _addIndex($index_cols,$search_cols,$unique,$length) {
		if (!is_array($index_cols))
			$index_cols=[$index_cols];
		$quotedCols=array_map([$this->db,'quotekey'],$index_cols);
		if (strpos($this->db->driver(),'mysql')!==FALSE)
			foreach ($quotedCols as $i=>&$col)
				if (strtoupper($search_cols[$index_cols[$i]]['type'])=='TEXT')
					$col.='('.$length.')';
		$cols=implode(',',$quotedCols);
		$name=$this->assembleIndexKey($index_cols,$this->name);
		$name=$this->db->quotekey($name);
		$table=$this->db->quotekey($this->name);
		$index=$unique?'UNIQUE INDEX':'INDEX';
		$cmd=[
			'pgsql|sqlite2?|ibm|mssql|sybase|dblib|odbc|sqlsrv'=>
				"CREATE $index $name ON $table ($cols);",
			'mysql'=> //ALTER TABLE is used because of MySQL bug #48875
				"ALTER TABLE $table ADD $index $name ($cols);",
		];
		$query=$this->findQuery($cmd);
		$this->queries[]=$query;
	}

	/**
	 * create index name from one or more given column names, max. 64 char lengths
	 * @param string|array $index_cols
	 * @return string
	 */
	protected function assembleIndexKey($index_cols,$table_name) {
		if (!is_array($index_cols))
			$index_cols=[$index_cols];
		$name=$table_name.'___'.implode('__',$index_cols);
		if (strlen($name)>64)
			$name=$table_name.'___'.Base::instance()->hash(implode('__',$index_cols));
		if (strlen($name)>64)
			$name='___'.
				Base::instance()->hash($table_name.'___'.implode('__',$index_cols));
		return $name;
	}

	/**
	 * set primary / composite key to table
	 * @param string|array $pkeys
	 * @return bool
	 */
	public function primary($pkeys) {
		if (empty($pkeys))
			return FALSE;
		if (!is_array($pkeys))
			$pkeys=[$pkeys];
		// single pkey
		$this->increments=$pkeys[0];
		$this->pkeys=$pkeys;
		// drop duplicate pkey definition
		if (array_key_exists($this->increments,$this->columns))
			unset($this->columns[$this->increments]);
		// set flag on new fields
		foreach ($pkeys as $name)
			if (array_key_exists($name,$this->columns))
				$this->columns[$name]->pkey=TRUE;
		// composite key
		if (count($pkeys)>1) {
			$pkeys_quoted=array_map([$this->db,'quotekey'],$pkeys);
			$pk_string=implode(', ',$pkeys_quoted);
			if (strpos($this->db->driver(),'sqlite')!==FALSE) {
				// rebuild table with new primary keys
				$this->rebuild_cmd['pkeys']=$pkeys;
			} else {
				$table=$this->db->quotekey($this->name);
				$table_key=$this->db->quotekey($this->name.'_pkey');
				$cmd=[
					'odbc'=>
						"CREATE INDEX $table_key ON $table ( $pk_string );",
					'mysql'=>
						"ALTER TABLE $table DROP PRIMARY KEY, ADD PRIMARY KEY ( $pk_string );",
					'mssql|sybase|dblib|sqlsrv'=>[
						"ALTER TABLE $table DROP CONSTRAINT PK_".$this->name."_ID;",
						"ALTER TABLE $table ADD CONSTRAINT $table_key PRIMARY KEY ( $pk_string );",
					],
					'pgsql|ibm'=>[
						"ALTER TABLE $table DROP CONSTRAINT $table_key;",
						"ALTER TABLE $table ADD CONSTRAINT $table_key PRIMARY KEY ( $pk_string );",
					],
				];
				$query=$this->findQuery($cmd);
				if (!is_array($query))
					$query=[$query];
				foreach ($query as $q)
					$this->queries[]=$q;
			}
		}
		return true;
	}

	public function getIndexName($index_cols){
		return $this->assembleIndexKey($index_cols,$this->name);
	}
}
