<?php

namespace DB;

class CortexQueryParser extends \Prefab {

	const
		E_BRACKETS = 'Invalid query: unbalanced brackets found',
		E_INBINDVALUE = 'Bind value for IN operator must be a populated array',
		E_ENGINEERROR = 'Engine not supported',
		E_MISSINGBINDKEY = 'Named bind parameter `%s` does not exist in filter arguments';

	protected
		$queryCache = [];

	/**
	 * converts the given filter array to fit the used DBS
	 *
	 * example filter:
	 *   array('text = ? AND num = ?','bar',5)
	 *   array('num > ? AND num2 <= ?',5,10)
	 *   array('num1 > num2')
	 *   array('text like ?','%foo%')
	 *   array('(text like ? OR text like ?) AND num != ?','foo%','%bar',23)
	 *
	 * @param array $cond
	 * @param string $engine
	 * @param object $db
	 * @param null $fieldConf
	 * @param string $primary
	 * @return array|bool|null
	 */
	public function prepareFilter($cond, $engine, $db, $fieldConf=null, $primary='id') {
		if (is_null($cond)) return $cond;
		if (is_string($cond))
			$cond = [$cond];
		$f3 = \Base::instance();
		$cacheHash = $f3->hash($f3->stringify($cond)).'.'.$engine;
		if ($engine=='sql')
			$cacheHash.='-'.$db->driver();
		if (isset($this->queryCache[$cacheHash]))
			// load from memory
			return $this->queryCache[$cacheHash];
		elseif ($f3->exists('CORTEX.queryParserCache')
			&& ($ttl = (int) $f3->get('CORTEX.queryParserCache'))) {
			$cache = \Cache::instance();
			// load from cache
			if ($f3->get('CACHE') && $ttl && ($cached = $cache->exists($cacheHash, $ncond))
				&& $cached[0] + $ttl > microtime(TRUE)) {
				$this->queryCache[$cacheHash] = $ncond;
				return $ncond;
			}
		}
		$where = array_shift($cond);
		$args = $cond;
		$unify=[['&&'],['AND']];
		// TODO: OR support via || operator is deprecated
		if ($engine == 'jig' || ($engine=='sql' && $db->driver() == 'mysql')) {
			$unify[0][]='||';
			$unify[1][]='OR';
		}
		$where = str_replace($unify[0], $unify[1], $where);
		// prepare IN condition
		$where = preg_replace('/\bIN\b\s*\(\s*(\?|:\w+)?\s*\)/i', 'IN $1', $where);
		switch ($engine) {
			case 'jig':
				$ncond = $this->_jig_parse_filter($where, $args);
				break;
			case 'mongo':
				$parts = $this->splitLogical($where);
				if (str_contains($where, ':'))
					list($parts, $args) = $this->convertNamedParams($parts, $args);
				foreach ($parts as &$part) {
					$part = $this->_mongo_parse_relational_op($part, $args, $db, $fieldConf);
					unset($part);
				}
				$ncond = $this->_mongo_parse_logical_op($parts);
				break;
			case 'sql':
				if (!$f3->exists('CORTEX.quoteConditions',$qc) || $qc)
					$where = $this->sql_quoteCondition($where,$db);
				// preserve identifier
				$where = preg_replace('/(?!\B)_id/', $primary, $where);
				if ($db->driver() == 'pgsql')
					$where = preg_replace('/\s+like\s+/i', ' ILIKE ', $where);
				$parts = $this->splitLogical($where);
				// ensure positional bind params
				if (str_contains($where, ':'))
					list($parts, $args) = $this->convertNamedParams($parts, $args);
				$ncond = [];
				foreach ($parts as &$part) {
					// arg handling
					$argCount = substr_count($part, '?');
					if ($argCount > 1) {
						// function parameters like `foo(?,?,?)`
						$ncond = array_merge($ncond, array_splice($args, 0, $argCount));
					} elseif ($argCount === 1) {
						$val = array_shift($args);
						// enhanced IN operator args expansion
						if (($pos = strpos($part, ' IN ?')) !== false) {
							if ($val instanceof CortexCollection)
								$val = $val->getAll('_id',TRUE);
							if (!is_array($val) || empty($val))
                                throw new \Exception(self::E_INBINDVALUE);
							$bindMarks = str_repeat('?,',count($val) - 1).'?';
							$part = substr($part, 0, $pos).' IN ('.$bindMarks.') ';
							$ncond = array_merge($ncond, $val);
						}
						// comparison against NULL
						elseif($val === null &&
							preg_match('/(.+?)\s*(!?={1,2})\s*(?:\?|:\w+)/i',
								$part,$match)) {
							$part = ' '.$match[1].' IS '.($match[2][0]=='!'?'NOT ':'').'NULL ';
						} else
							$ncond[] = $val;
					}
					unset($part);
				}
				array_unshift($ncond, implode($parts));
				break;
			default:
                throw new \Exception(self::E_ENGINEERROR);
		}
		$this->queryCache[$cacheHash] = $ncond;
		if(isset($ttl) && $f3->get('CACHE')) {
			// save to cache
			$cache = \Cache::instance();
			$cache->set($cacheHash,$ncond,$ttl);
		}
		return $ncond;
	}

	/**
	 * split where criteria string into logical chunks
	 * @param $cond
	 * @return array
	 */
	protected function splitLogical($cond) {
		return preg_split('/(\s*(?<!\()\)|\w*\((?!\))|\bAND\b|\bOR\b\s*)/i', $cond, -1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
	}

	/**
	 * converts named parameter filter to positional
	 * @param $parts
	 * @param $args
	 * @return array
	 */
	protected function convertNamedParams($parts, $args) {
		if (empty($args)) return [$parts, $args];
		$params = [];
		$pos = 0;
		foreach ($parts as &$part) {
			if (preg_match('/:\w+/i', $part, $match)) {
				if (!array_key_exists($match[0],$args))
                    throw new \Exception(sprintf(self::E_MISSINGBINDKEY,
						$match[0]));
				$part = str_replace($match[0], '?', $part);
				$params[] = $args[$match[0]];
			} elseif (str_contains($part, '?'))
				$params[] = $args[$pos++];
			unset($part);
		}
		return [$parts, $params];
	}

	/**
	 * quote identifiers in condition
	 * @param string $cond
	 * @param object $db
	 * @return string
	 */
	public function sql_quoteCondition($cond, $db) {
		// https://www.debuggex.com/r/6AXwJ1Y3Aac8aocQ/3
		// https://regex101.com/r/yM5vK4/1
		// this took me lots of sleepless nights
		$out = preg_replace_callback('/'.
			'\w+\((?:(?>[^()]+)|\((?:(?>[^()]+)|^(?R))*\))*\)|'. // exclude SQL function names "foo("
			'(?:(\b(?<!:)'. // exclude bind parameter ":foo"
				'[a-zA-Z_](?:[\w\-_.]+\.?))'. // match only identifier, exclude values
			'(?=[\s<>=!)]|$))/i', // only when part of condition or within brackets
			function($match) use($db) {
				if (!isset($match[1]))
					return $match[0];
				if (preg_match('/\b(AND|OR|IN|LIKE|NOT|IS|NULL|BETWEEN|EXISTS|TRUE|FALSE|ASC|DESC|HAVING|SELECT|FROM|WHERE|AS|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CASE|WHEN|THEN|ELSE|END|CAST|DISTINCT|LIMIT|OFFSET|GROUP|ORDER|BY)\b/i',$match[1]))
					return $match[1];
				return $db->quotekey($match[1]);
			}, $cond);
		return $out ?: $cond;
	}

	/**
	 * add table prefix to identifiers which do not have a table prefix yet
	 * @param string $cond
	 * @param string $table
	 * @return string
	 */
	public function sql_prependTableToFields($cond, $table) {
		$out = preg_replace_callback('/'.
			'(\w+\((?:[^)(]+|\((?:[^)(]+|(?R))*\))*\))|'.
			'(?:(\s)|^|(?<=[(]))'.
			'([a-zA-Z_](?:[\w\-_]+))'.
			'(?=[\s<>=!)]|$)/i',
			function($match) use($table) {
				if (!isset($match[3]))
					return $match[1];
				if (preg_match('/\b(AND|OR|IN|LIKE|NOT|IS|NULL|BETWEEN|EXISTS|TRUE|FALSE|ASC|DESC|HAVING|SELECT|FROM|WHERE|AS|ON|JOIN|LEFT|RIGHT|INNER|OUTER|CASE|WHEN|THEN|ELSE|END|CAST|COUNT|SUM|AVG|MIN|MAX|DISTINCT|LIMIT|OFFSET|GROUP|ORDER|BY)\b/i',$match[3]))
					return $match[0];
				return $match[2].$table.'.'.$match[3];
			}, $cond);
		return $out ?: $cond;
	}

	/**
	 * convert filter array to jig syntax
	 * @param $where
	 * @param $args
	 * @return array
	 */
	protected function _jig_parse_filter($where, $args) {
		$parts = $this->splitLogical($where);
		if (str_contains($where, ':'))
			list($parts, $args) = $this->convertNamedParams($parts, $args);
		$ncond = [];
		foreach ($parts as &$part) {
			if (preg_match('/\s*\b(AND|OR)\b\s*/i',$part))
				continue;
			// prefix field names
			$part = preg_replace('/([a-z_-]+(?:[\w-]+))/i', '@$1', $part, -1, $count);
			// value comparison
			if (str_contains($part, '?')) {
				$val = array_shift($args);
				preg_match('/(@\w+)/i', $part, $match);
				$skipVal=false;
				// find like operator
				if (str_contains($upart = strtoupper($part), ' @LIKE ')) {
					$not = str_contains($upart, '@NOT');
					$val = '/'.$this->_likeValueToRegEx($val).'/iu';
					$part = ($not ? '!' : '').'preg_match(?,'.$match[0].')';
				} // find IN operator
				elseif (($pos = strpos($upart, ' @IN ')) !== false) {
					if ($val instanceof CortexCollection)
						$val = $val->getAll('_id',TRUE);
					if ($not = (($npos = strpos($upart, '@NOT')) !== false))
						$pos = $npos;
					$part = ($not ? '!' : '').'in_array('.substr($part, 0, $pos).
						',array(\''.implode('\',\'', $val).'\'))';
					$skipVal=true;
				}
				elseif($val===null && preg_match('/(\w+)\s*([!=<>]+)\s*\?/i',$part,$nmatch)
					&& ($nmatch[2]=='=' || $nmatch[2]=='==' || $nmatch[2]=='!=' || $nmatch[2]=='!==')){
					$kval=ltrim($nmatch[1],'@');
					if ($nmatch[2][0] == '!')
						$part = '(array_key_exists(\''.$kval.'\',$_row) && $_row[\''.$kval.'\']!==NULL)';
					else
						$part = '(!array_key_exists(\''.$kval.'\',$_row) || '.
							'(array_key_exists(\''.$kval.'\',$_row) && $_row[\''.$kval.'\']===NULL))';
					unset($part);
					continue;
				}
				// add existence check
				$part = ($val===null && !$skipVal)
					? '(array_key_exists(\''.ltrim($match[0],'@').'\',$_row) && '.$part.')'
					: '(isset('.$match[0].') && '.$part.')';
				if (!$skipVal)
					$ncond[] = $val;
			} elseif ($count >= 1) {
				// field comparison
				preg_match_all('/(@\w+)/i', $part, $matches);
				$chks = [];
				foreach ($matches[0] as $field)
					$chks[] = 'isset('.$field.')';
				$part = '('.implode(' && ',$chks).' && ('.$part.'))';
			}
			unset($part);
		}
		array_unshift($ncond, implode(' ', $parts));
		return $ncond;
	}

	/**
	 * find and wrap logical operators AND, OR, (, )
	 * @param $parts
	 * @return array
	 */
	protected function _mongo_parse_logical_op($parts) {
		$b_offset = 0;
		$ncond = [];
		$child = [];
		for ($i = 0, $max = count($parts); $i < $max; $i++) {
			$part = $parts[$i];
			if (is_string($part))
				$part = trim($part);
			if ($part == '(') {
				// add sub-bracket to parse array
				if ($b_offset > 0)
					$child[] = $part;
				$b_offset++;
			} elseif ($part == ')') {
				$b_offset--;
				// found closing bracket
				if ($b_offset == 0) {
					$ncond[] = ($this->_mongo_parse_logical_op($child));
					$child = [];
				} elseif ($b_offset < 0)
                    throw new \Exception(self::E_BRACKETS);
				else
					// add sub-bracket to parse array
					$child[] = $part;
			}
			elseif ($b_offset > 0) {
				// add to parse array
				$child[]=$part;
				// condition type
			} elseif (!is_array($part)) {
				if (strtoupper($part) == 'AND')
					$add = true;
				elseif (strtoupper($part) == 'OR')
					$or = true;
			} else // skip
				$ncond[] = $part;
		}
		if ($b_offset > 0)
            throw new \Exception(self::E_BRACKETS);
		if (isset($add))
			return ['$and' => $ncond];
		elseif (isset($or))
			return ['$or' => $ncond];
		else
			return $ncond[0];
	}

	/**
	 * find and convert relational operators
	 * @param $part
	 * @param $args
	 * @param \DB\Mongo $db
	 * @param null $fieldConf
	 * @return array|null
	 */
	protected function _mongo_parse_relational_op($part, &$args, \DB\Mongo $db, $fieldConf=null) {
		if (is_null($part))
			return $part;
		if (preg_match('/\<\=|\>\=|\<\>|\<|\>|\!\=|\=\=|\=|like|not like|in|not in/i', $part, $match)) {
			$var = str_contains($part, '?') ? array_shift($args) : null;
			$exp = explode($match[0], $part);
			$key = trim($exp[0]);
			// unbound value
			if (is_numeric($exp[1]))
				$var = $exp[1];
			// field comparison
			elseif (!str_contains($exp[1], '?'))
				return ['$where' => 'this.'.$key.' '.$match[0].' this.'.trim($exp[1])];
			$upart = strtoupper($match[0]);
			// MongoID shorthand
			if ($key == '_id' || (isset($fieldConf[$key]) && isset($fieldConf[$key]['relType']))) {
				if (is_array($var))
					foreach ($var as &$id) {
						if ($db->legacy() && !$id instanceof \MongoId)
							$id = new \MongoId($id);
						elseif (!$db->legacy() && !$id instanceof \MongoDB\BSON\ObjectId)
							$id = new \MongoDB\BSON\ObjectId($id);
						unset($id);
					}
				elseif($db->legacy() && !$var instanceof \MongoId)
					$var = new \MongoId($var);
				elseif(!$db->legacy() && !$var instanceof \MongoDB\BSON\ObjectId)
					$var = new \MongoDB\BSON\ObjectId($var);
			}
			// find LIKE operator
			if (in_array($upart, ['LIKE','NOT LIKE'])) {
				$rgx = $this->_likeValueToRegEx($var);
				$var = $db->legacy() ? new \MongoRegex('/'.$rgx.'/iu') : new \MongoDB\BSON\Regex($rgx,'iu');
				if ($upart == 'NOT LIKE')
					$var = ['$not' => $var];
			} // find IN operator
			elseif (in_array($upart, ['IN','NOT IN'])) {
				if ($var instanceof CortexCollection)
					$var = $var->getAll('_id',true);
				$var = [($upart=='NOT IN')?'$nin':'$in' => array_values($var)];
			} // translate operators
			elseif (!in_array($match[0], ['==', '='])) {
				$opr = str_replace(['<>', '<', '>', '!', '='],
					['$ne', '$lt', '$gt', '$n', 'e'], $match[0]);
				$var = [$opr => (strtolower($var) == 'null') ? null :
						(is_object($var) ? $var : (is_numeric($var) ? $var + 0 : $var))];
			}
			return [$key => $var];
		}
		return $part;
	}

	/**
	 * @param string $var
	 * @return string
	 */
	protected function _likeValueToRegEx($var) {
		$lC = substr($var, -1, 1);
		// %var% -> /var/
		if ($var[0] == '%' && $lC == '%')
			$var = substr($var, 1, -1);
		// var%  -> /^var/
		elseif ($lC == '%')
			$var = '^'.substr($var, 0, -1);
		// %var  -> /var$/
		elseif ($var[0] == '%')
			$var = substr($var, 1).'$';
		return $var;
	}

	/**
	 * convert options array syntax to given engine type
	 *
	 * example:
	 *   array('order'=>'location') // default direction is ASC
	 *   array('order'=>'num1 desc, num2 asc')
	 *
	 * @param array $options
	 * @param string $engine
	 * @param object $db
	 * @return array|null
	 */
	public function prepareOptions($options, $engine, $db) {
		if (empty($options) || !is_array($options))
			return null;
		switch ($engine) {
			case 'jig':
				if (array_key_exists('order', $options))
					$options['order'] = preg_replace(
						['/(?<=\h)(ASC)(?=\W|$)/i','/(?<=\h)(DESC)(?=\W|$)/i'],
						['SORT_ASC','SORT_DESC'],$options['order']);
				break;
			case 'mongo':
				if (array_key_exists('order', $options)) {
					$sorts = explode(',', $options['order']);
					$sorting = [];
					foreach ($sorts as $sort) {
						$sp = explode(' ', trim($sort));
						$sorting[$sp[0]] = (array_key_exists(1, $sp) &&
							strtoupper($sp[1]) == 'DESC') ? -1 : 1;
					}
					$options['order'] = $sorting;
				}
				if (array_key_exists('group', $options) && is_string($options['group'])) {
					$keys = explode(',',$options['group']);
					$options['group']=['keys'=>[],'initial'=>[],
						'reduce'=>'function (obj, prev) {}','finalize'=>''];
					$keys = array_combine($keys,array_fill(0,count($keys),1));
					$options['group']['keys']=$keys;
					$options['group']['initial']=$keys;
				}
				break;
			case 'sql':
				$char=substr($db->quotekey(''),0,1);
				if (array_key_exists('order', $options) &&
					!str_contains($options['order'],$char))
					$options['order']=preg_replace_callback(
						'/(\w+\h?\(|'. // skip function names
						'\b(?!\w+)(?:\s+\w+)+|' . // skip field args
						'\)\s+\w+)|'. // skip function args
						'(\b\d?[a-zA-Z_](?:[\w\-.])*)/i', // match table/field keys
						function($match) use($db) {
							if (!isset($match[2]))
								return $match[1];
							return $db->quotekey($match[2]);
						}, $options['order']);
				break;
		}
		return $options;
	}
}
