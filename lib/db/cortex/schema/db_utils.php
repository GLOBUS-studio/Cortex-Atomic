<?php

/**
 *  Cortex Schema Builder - DB_Utils trait
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.0
 *  @date 29.03.2026
 */

namespace DB\Cortex\Schema;

use DB\SQL;

trait DB_Utils {

	/** @var SQL */
	protected $db;

	/**
	 * @return SQL
	 */
	public function getDb() {
		return $this->db;
	}

	/**
	 * parse command array and return backend specific query
	 * @param $cmd
	 * @param $cmd array
	 * @return bool|string
	 */
	public function findQuery($cmd) {
		foreach ($cmd as $backend=>$val)
			if (preg_match('/'.$backend.'/',$this->db->driver()))
				return $val;
		trigger_error(sprintf('DB Engine `%s` is not supported for this action.',
			$this->db->driver()),E_USER_ERROR);
	}
}
