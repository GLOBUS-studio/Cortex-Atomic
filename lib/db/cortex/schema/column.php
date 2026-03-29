<?php

/**
 *  Cortex Schema Builder - Column definition
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.0
 *  @date 29.03.2026
 */

namespace DB\Cortex\Schema;

use Base;
use PDO;

/**
 * defines a table column configuration
 * Class Column
 * @package DB\Cortex\Schema
 */
class Column {

	use DB_Utils;

	protected $name,$type,$nullable,$default,$after,$index,$unique,$passThrough,$pkey;
	protected $table,$schema,$type_val;

	public function __get($prop) {
		if (property_exists($this, $prop))
			return $this->{$prop};
		return null;
	}

	const
		TEXT_NoDataType='The specified datatype %s is not defined in %s driver. Add passThrough option to enforce this datatype.',
		TEXT_CurrentStampDataType='Current timestamp as column default is only possible for TIMESTAMP datatype';

	/**
	 * @param string $name
	 * @param TableBuilder $table
	 */
	public function __construct($name,TableBuilder $table) {
		$this->name=$name;
		$this->nullable=TRUE;
		$this->default=FALSE;
		$this->after=FALSE;
		$this->index=FALSE;
		$this->unique=FALSE;
		$this->passThrough=FALSE;
		$this->pkey=FALSE;

		$this->table=$table;
		$this->schema=$table->getSchema();
		$this->db=$this->schema->getDb();
	}

	/**
	 * @param string $datatype
	 * @param bool $force don't match datatype against DT array
	 * @return $this
	 */
	public function type($datatype,$force=FALSE) {
		$this->type=$datatype;
		$this->passThrough=$force;
		return $this;
	}

	public function type_tinyint() {
		$this->type=Schema::DT_INT1;
		return $this;
	}

	public function type_smallint() {
		$this->type=Schema::DT_INT2;
		return $this;
	}

	public function type_int() {
		$this->type=Schema::DT_INT4;
		return $this;
	}

	public function type_bigint() {
		$this->type=Schema::DT_INT8;
		return $this;
	}

	public function type_float() {
		$this->type=Schema::DT_FLOAT;
		return $this;
	}

	public function type_decimal() {
		$this->type=Schema::DT_DOUBLE;
		return $this;
	}

	public function type_text() {
		$this->type=Schema::DT_TEXT;
		return $this;
	}

	public function type_longtext() {
		$this->type=Schema::DT_LONGTEXT;
		return $this;
	}

	public function type_varchar($length=255) {
		$this->type="varchar($length)";
		$this->passThrough=TRUE;
		return $this;
	}

	public function type_date() {
		$this->type=Schema::DT_DATE;
		return $this;
	}

	public function type_datetime() {
		$this->type=Schema::DT_DATETIME;
		return $this;
	}

	public function type_timestamp($asDefault=FALSE) {
		$this->type=Schema::DT_TIMESTAMP;
		if ($asDefault)
			$this->default=Schema::DF_CURRENT_TIMESTAMP;
		return $this;
	}

	public function type_blob() {
		$this->type=Schema::DT_BLOB;
		return $this;
	}

	public function type_bool() {
		$this->type=Schema::DT_BOOLEAN;
		return $this;
	}

	public function passThrough($state=TRUE) {
		$this->passThrough=$state;
		return $this;
	}

	public function nullable($nullable) {
		$this->nullable=$nullable;
		return $this;
	}

	public function defaults($default) {
		$this->default=$default;
		return $this;
	}

	public function after($name) {
		$this->after=$name;
		return $this;
	}

	public function index($unique=FALSE) {
		$this->index=TRUE;
		$this->unique=$unique;
		return $this;
	}

	/**
	 * feed column from array or hive key
	 * @param string|array $args
	 */
	public function copyfrom($args) {
		if (($args || Base::instance()->exists($args,$args))
			&& is_array($args))
			foreach ($args as $arg=>$val) {
				if (property_exists($this, $arg))
					$this->{$arg}=$val;
			}
	}

	/**
	 * returns an array of this column configuration
	 * @return array
	 */
	public function getColumnArray() {
		$fields=['name','type','passThrough','default','nullable',
			'index','unique','after','pkey'];
		$fields=array_flip($fields);
		foreach ($fields as $key=>&$val)
			$val=$this->{$key};
		unset($val);
		return $fields;
	}

	/**
	 * return resolved column datatype
	 * @return bool|string
	 */
	public function getTypeVal() {
		if (!$this->type)
			trigger_error(sprintf('Cannot build a column query for `%s`: no column type set',
				$this->name),E_USER_ERROR);
		if ($this->passThrough)
			$this->type_val=$this->type;
		else {
			$this->type_val=
				$this->findQuery($this->schema->getDataTypes()[strtoupper($this->type)]);
			if (!$this->type_val) {
				if (Schema::$strict) {
					trigger_error(sprintf(self::TEXT_NoDataType,strtoupper($this->type),
						$this->db->driver()),E_USER_ERROR);
					return FALSE;
				} else {
					// auto pass-through if not found
					$this->type_val=$this->type;
				}
			}
		}
		return $this->type_val;
	}

	/**
	 * generate SQL column definition query
	 * @return bool|string
	 */
	public function getColumnQuery() {
		// prepare column types
		$type_val=$this->getTypeVal();
		// build query
		$query=$this->db->quotekey($this->name).' '.$type_val.' '.
			$this->getNullable();
		// unify default for booleans
		if (preg_match('/bool/i',$type_val) && $this->default!==NULL)
			$this->default=(int)$this->default;
		// default value
		if ($this->default!==FALSE) {
			$def_cmds=[
				'sqlite2?|mysql|pgsql'=>'DEFAULT',
				'mssql|sybase|dblib|odbc|sqlsrv'=>'constraint DF_'.$this->table->getName().'_'.
					$this->name.' DEFAULT',
				'ibm'=>'WITH DEFAULT',
			];
			$def_cmd=$this->findQuery($def_cmds).' '.$this->getDefault();
			$query.=' '.$def_cmd;
		}
		if (!empty($this->after) && $this->table instanceof TableModifier) {
			// `after` feature only works for mysql
			if (strpos($this->db->driver(),'mysql')!==FALSE) {
				$after_cmd='AFTER '.$this->db->quotekey($this->after);
				$query.=' '.$after_cmd;
			}
		}
		return $query;
	}

	/**
	 * return query part for nullable
	 * @return string
	 */
	public function getNullable() {
		return $this->nullable?'NULL':'NOT NULL';
	}

	/**
	 * return query part for default value
	 * @return string
	 */
	public function getDefault() {
		// timestamp default
		if ($this->default===Schema::DF_CURRENT_TIMESTAMP) {
			// check for right datatpye
			$stamp_type=$this->findQuery($this->schema->getDataTypes()['TIMESTAMP']);
			if ($this->type!='TIMESTAMP' &&
				($this->passThrough && strtoupper($this->type)!=strtoupper($stamp_type))
			)
				trigger_error(self::TEXT_CurrentStampDataType,E_USER_ERROR);
			return $this->findQuery($this->schema->getDefaultTypes()[strtoupper($this->default)]);
		} else {
			// static defaults
			$type_val=$this->getTypeVal();
			$pdo_type=preg_match('/int|bool/i',$type_val,$parts)?
				constant('\PDO::PARAM_'.strtoupper($parts[0])):PDO::PARAM_STR;
			return ($this->default===NULL?'NULL':
				$this->db->quote(htmlspecialchars($this->default,ENT_QUOTES,
					Base::instance()->get('ENCODING')),$pdo_type));
		}
	}
}
