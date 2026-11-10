<?php

/**
 *  Cortex Schema Builder - live column type descriptors
 *
 *  Maps the column types a live database reports to Cortex DT_* tokens plus
 *  the real storage limits (length, bytes, integer width, precision/scale,
 *  fractional seconds, enum values). Never generates DDL.
 *
 *  Every engine has its own rules. A type that the engine does not have, or
 *  whose limits cannot be known from the type string alone, throws instead of
 *  being approximated.
 *
 *  @package DB\Cortex\Schema
 *  @version 2.0.31
 *  @date 09.11.2026
 */

namespace DB\Cortex\Schema;

final class ColumnType {

	/** Driver families describe() understands. */
	const DRIVERS = ['mysql','mariadb','pgsql','sqlite','sqlsrv'];

	/**
	 * Describe one raw column type.
	 *
	 * Returned keys: type (DT_* token), column_kind (semantic subtype) and,
	 * when known: max_length (characters), max_bytes, integer_bits, unsigned,
	 * precision, scale, fractional_precision, clock_time, timezone,
	 * enum_values / set_values, uuid, scaled_bounds.
	 *
	 * On PostgreSQL and SQL Server, \DB\SQL::schema() drops lengths and
	 * precision; pass its output through withDeclaredTypes() first, or use
	 * describeTable(). A type still missing them throws.
	 *
	 * @param string $raw type as reported by the database, e.g. "varchar(64)"
	 * @param string $driver PDO driver name (mysql, mariadb, pgsql, sqlite, sqlsrv or an alias)
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException for unsupported drivers, unsupported
	 *         types and types whose limits are unknown
	 */
	public static function describe(string $raw, string $table, string $field, string $driver): array {
		$family = self::driver($driver);
		if (!in_array($family, self::DRIVERS, true))
			throw new \InvalidArgumentException(sprintf('Unsupported driver "%s" for column type "%s" in table "%s" field "%s".',
				$driver, $raw, $table, $field));
		$type = strtolower(preg_replace('/\s+/', ' ', trim($raw)));
		$type = preg_replace('/\s*\(\s*/', '(', preg_replace('/\s*\)/', ')', preg_replace('/\s*,\s*/', ',', $type)));
		$result = match ($family) {
			'mysql', 'mariadb' => self::mysql($type, $raw),
			'pgsql' => self::pgsql($type),
			'sqlsrv' => self::sqlsrv($type),
			'sqlite' => self::sqlite($type),
		};
		if (is_array($result))
			return $result;
		throw new \InvalidArgumentException(sprintf('Unsupported column type "%s" in table "%s" field "%s"%s.',
			$raw, $table, $field, is_string($result) ? ': '.$result : ''));
	}

	/**
	 * Describe every column of a table, restoring declared types first.
	 *
	 * @return array<string, array<string, mixed>> field name => descriptor
	 */
	public static function describeTable(\DB\SQL $db, string $table): array {
		$driver = $db->driver();
		$result = [];
		foreach (self::withDeclaredTypes($db, $table, $db->schema($table)) as $field => $column)
			$result[$field] = self::describe((string)$column['type'], $table, (string)$field, $driver);
		return $result;
	}

	/**
	 * Restore full declared types in \DB\SQL::schema() output.
	 *
	 * On PostgreSQL and SQL Server, F3 reads INFORMATION_SCHEMA.DATA_TYPE, which
	 * drops length, precision and scale: NVARCHAR(64) comes back as "nvarchar",
	 * DECIMAL(10,2) as "decimal". This re-reads those parts and rewrites each
	 * column's "type". An unbounded PostgreSQL VARCHAR is rewritten as "text",
	 * which PostgreSQL treats identically.
	 *
	 * MariaDB reports JSON columns as LONGTEXT guarded by a json_valid() check;
	 * when a LONGTEXT column exists, one query reads those checks and rewrites
	 * the type as "json". MySQL and SQLite already report full types and are
	 * returned unchanged, without a query.
	 *
	 * @param array<string, array> $columns result of $db->schema($table)
	 * @return array<string, array>
	 * @throws \RuntimeException when the table is ambiguous or a column cannot be restored
	 */
	public static function withDeclaredTypes(\DB\SQL $db, string $table, array $columns): array {
		$driver = self::driver($db->driver());
		if ($columns && $driver === 'mysql')
			return self::withMariaDbJson($db, $table, $columns);
		if (!$columns || !in_array($driver, ['pgsql','sqlsrv'], true))
			return $columns;
		$schema = null;
		if (strpos($table, '.'))
			[$schema, $table] = explode('.', $table, 2);
		$rows = $db->exec(
			'SELECT TABLE_SCHEMA AS schema_name, COLUMN_NAME AS field, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS len, '.
			'NUMERIC_PRECISION AS prec, NUMERIC_SCALE AS scale, DATETIME_PRECISION AS dtp '.
			'FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=?'.($schema !== null ? ' AND TABLE_SCHEMA=?' : ''),
			$schema !== null ? [1=>$table, 2=>$schema] : [1=>$table]
		);
		$rows = array_map(static fn($row) => array_change_key_case($row, CASE_LOWER), $rows);
		if ($schema === null) {
			$schemas = array_values(array_unique(array_column($rows, 'schema_name')));
			if (count($schemas) === 1)
				$schema = $schemas[0];
			elseif (count($schemas) > 1) {
				// F3 queries every schema for bare names. Prefer the engine's own
				// search_path / default-schema resolution, never row order.
				$resolved = $driver === 'pgsql'
					? $db->exec('SELECT n.nspname AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=to_regclass(?)',
						[1=>'"'.str_replace('"', '""', $table).'"'])
					: $db->exec('SELECT OBJECT_SCHEMA_NAME(OBJECT_ID(?)) AS name',
						[1=>'['.str_replace(']', ']]', $table).']']);
				$resolved = $resolved ? array_change_key_case($resolved[0], CASE_LOWER) : [];
				$schema = $resolved['name'] ?? null;
				if (!in_array($schema, $schemas, true))
					throw new \RuntimeException('Ambiguous table "'.$table.'": qualify its schema to restore declared types.');
			}
		}
		if (!$rows)
			throw new \RuntimeException('Cannot restore declared types for table "'.$table.'".');
		$restored = [];
		foreach ($rows as $row) {
			if ($row['schema_name'] === $schema && isset($columns[$row['field']])) {
				$columns[$row['field']]['type'] = self::declaredType($row, $driver);
				$restored[$row['field']] = true;
			}
		}
		$missing = array_diff(array_map('strval', array_keys($columns)), array_map('strval', array_keys($restored)));
		if ($missing)
			throw new \RuntimeException('Cannot restore declared types for table "'.$table.'" field(s) "'
				.implode('", "', $missing).'": not found in the catalog.');
		return $columns;
	}

	/** Rewrite MariaDB LONGTEXT columns guarded by json_valid() as "json". */
	private static function withMariaDbJson(\DB\SQL $db, string $table, array $columns): array {
		$longtext = array_filter($columns, static fn($c) => strtolower(trim((string)$c['type'])) === 'longtext');
		if (!$longtext || stripos((string)$db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'mariadb') === false)
			return $columns;
		$schema = null;
		if (strpos($table, '.'))
			[$schema, $table] = explode('.', $table, 2);
		$rows = $db->exec(
			'SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS '.
			'WHERE CONSTRAINT_SCHEMA='.($schema !== null ? '?' : 'DATABASE()').' AND TABLE_NAME=? AND CHECK_CLAUSE LIKE ?',
			$schema !== null ? [1=>$schema, 2=>$table, 3=>'json_valid(%'] : [1=>$table, 2=>'json_valid(%']
		);
		foreach ($rows as $row) {
			$row = array_change_key_case($row, CASE_LOWER);
			if (preg_match('/^json_valid\(`((?:[^`]|``)+)`\)$/i', trim((string)$row['clause']), $m)) {
				$field = str_replace('``', '`', $m[1]);
				if (isset($longtext[$field]))
					$columns[$field]['type'] = 'json';
			}
		}
		return $columns;
	}

	/** Normalized driver family: mysql, pgsql, sqlite, sqlsrv or the given name. */
	public static function driver(?string $driver): string {
		$driver = strtolower((string)$driver);
		return ['mssql'=>'sqlsrv','dblib'=>'sqlsrv','sybase'=>'sqlsrv','sqlite2'=>'sqlite'][$driver] ?? $driver;
	}

	/** Rebuild "nvarchar(64)", "numeric(10,2)", "timestamp(3) without time zone", ... */
	private static function declaredType(array $row, string $driver): string {
		$type = strtolower(trim((string)$row['type']));
		if (in_array($type, ['char','varchar','nchar','nvarchar','binary','varbinary',
				'character','character varying','bit','bit varying'], true)) {
			if ($row['len'] !== null)
				return $type.'('.((int)$row['len'] === -1 ? 'max' : (int)$row['len']).')';
			if ($driver === 'pgsql' && $type === 'character varying')
				return 'text';
			return $type;
		}
		if ($row['prec'] !== null && in_array($type, ['decimal','numeric'], true))
			return $type.'('.(int)$row['prec'].','.(int)$row['scale'].')';
		if ($row['dtp'] !== null && preg_match('/^(time|timestamp|datetime|smalldatetime|datetime2|datetimeoffset)( with(?:out)? time zone)?$/', $type, $m))
			return $m[1].'('.(int)$row['dtp'].')'.($m[2] ?? '');
		return $type;
	}

	/**
	 * MySQL / MariaDB: SHOW COLUMNS reports full types, so a missing length
	 * means a malformed type. REAL is rejected: its meaning depends on sql_mode.
	 */
	private static function mysql(string $type, string $raw): array|string|null {
		if (preg_match('/^(enum|set)\(/', $type))
			return self::literalList($raw);
		$unsigned = (bool)preg_match('/ (unsigned|zerofill)\b/', $type);
		$type = trim(preg_replace('/ (unsigned|zerofill)\b/', '', $type));
		if ($type === 'uuid')
			return self::uuid();
		if (in_array($type, ['bool','boolean','bit','bit(1)'], true))
			return self::boolean();
		if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)(?:\((\d+)\))?$/', $type, $m)) {
			if ($m[1] === 'tinyint' && ($m[2] ?? '') === '1')
				return ['type'=>'BOOLEAN','column_kind'=>'boolean','integer_bits'=>8,'unsigned'=>$unsigned];
			return self::integer(['tinyint'=>8,'smallint'=>16,'mediumint'=>24,'int'=>32,'integer'=>32,'bigint'=>64][$m[1]], $unsigned);
		}
		if (preg_match('/^(decimal|numeric)(?:\((\d+)(?:,(\d+))?\))?$/', $type, $m))
			// MySQL documents DECIMAL as DECIMAL(10,0) and DECIMAL(M) as DECIMAL(M,0).
			return self::decimal((int)($m[2] ?? 10), (int)($m[3] ?? 0), 65, 30, $unsigned);
		if (preg_match('/^(float|double|double precision)(?:\((\d+)(?:,(\d+))?\))?$/', $type, $m)) {
			if (isset($m[3])) {
				// FLOAT(M,D) / DOUBLE(M,D): M up to 255 digits, D up to 30.
				$d = self::decimal((int)$m[2], (int)$m[3], 255, 30, $unsigned);
				return is_array($d) ? ['type'=>$m[1] === 'float' ? 'FLOAT' : 'DOUBLE'] + $d : $d;
			}
			if (isset($m[2]))
				return $m[1] === 'float' ? self::floating((int)$m[2], $unsigned) : 'precision without scale';
			return ['type'=>$m[1] === 'float' ? 'FLOAT' : 'DOUBLE','column_kind'=>'floating','unsigned'=>$unsigned];
		}
		if ($unsigned)
			return 'UNSIGNED/ZEROFILL applies only to numeric types';
		if (preg_match('/^(?:n?varchar|n?char|varchar2)(?:\((\d+)\))?$/', $type, $m))
			return isset($m[1]) ? self::string((int)$m[1]) : 'missing length';
		if (preg_match('/^(?:binary|varbinary)(?:\((\d+)\))?$/', $type, $m))
			return isset($m[1]) ? ['type'=>'BLOB','column_kind'=>'binary','max_bytes'=>(int)$m[1]] : 'missing length';
		if (preg_match('/^(tiny|medium|long)?(text|blob)$/', $type, $m)) {
			$blob = $m[2] === 'blob';
			return ['type'=>$blob ? 'BLOB' : (in_array($m[1], ['medium','long'], true) ? 'LONGTEXT' : 'TEXT'),
				'column_kind'=>$blob ? 'binary' : 'text',
				'max_bytes'=>['tiny'=>255,''=>65535,'medium'=>16777215,'long'=>4294967295][$m[1]]];
		}
		if ($type === 'date')
			return self::date();
		if (preg_match('/^(datetime|timestamp|time)(?:\(([0-6])\))?$/', $type, $m))
			return self::temporal($m[1], (int)($m[2] ?? 0), false, false);
		if ($type === 'year' || $type === 'year(4)')
			return ['type'=>'INT2','column_kind'=>'year'];
		if ($type === 'json')
			return ['type'=>'JSON','column_kind'=>'json'];
		return null;
	}

	/**
	 * PostgreSQL: lengths, precision and fractional seconds must be present
	 * (restore them with withDeclaredTypes()). MONEY is rejected: its scale
	 * follows lc_monetary.
	 */
	private static function pgsql(string $type): array|string|null {
		if ($type === 'uuid')
			return self::uuid();
		if (in_array($type, ['bool','boolean','bit(1)'], true))
			return self::boolean();
		if ($type === 'bit')
			return 'missing length; restore it with withDeclaredTypes()';
		if (preg_match('/^(?:character varying|varchar|character|char|bpchar)(?:\((\d+)\))?$/', $type, $m))
			return isset($m[1]) ? self::string((int)$m[1])
				: 'missing length; restore it with withDeclaredTypes()';
		if ($type === 'text')
			return self::text();
		if ($type === 'bytea')
			return ['type'=>'BLOB','column_kind'=>'binary'];
		$ints = ['smallint'=>16,'int2'=>16,'smallserial'=>16,'serial2'=>16,'integer'=>32,'int'=>32,'int4'=>32,'serial'=>32,
			'serial4'=>32,'bigint'=>64,'int8'=>64,'bigserial'=>64,'serial8'=>64];
		if (isset($ints[$type]))
			return self::integer($ints[$type], false);
		if (in_array($type, ['real','float4'], true))
			return self::floating(24, false);
		if (in_array($type, ['double precision','float8','float'], true))
			return self::floating(53, false);
		if (preg_match('/^float\((\d+)\)$/', $type, $m))
			return self::floating((int)$m[1], false);
		if (preg_match('/^(?:numeric|decimal)(?:\((\d+)(?:,(\d+))?\))?$/', $type, $m))
			return isset($m[1]) ? self::decimal((int)$m[1], (int)($m[2] ?? 0), 1000, 1000, false)
				: 'missing precision; an unconstrained NUMERIC has no fixed limits, or restore it with withDeclaredTypes()';
		if ($type === 'money')
			return 'MONEY scale depends on lc_monetary';
		if ($type === 'date')
			return self::date();
		if (preg_match('/^(timestamp|time)(?:\(([0-6])\))?(?: (with|without) time zone)?$/', $type, $m)
				|| preg_match('/^(timestamptz|timetz)(?:\(([0-6])\))?$/', $type, $m)) {
			if (!isset($m[2]) || $m[2] === '')
				return 'missing fractional precision; restore it with withDeclaredTypes()';
			$tz = ($m[3] ?? '') === 'with' || in_array($m[1], ['timestamptz','timetz'], true);
			return self::temporal(str_starts_with($m[1], 'timestamp') ? 'timestamp' : 'time', (int)$m[2], true, $tz);
		}
		if ($type === 'json' || $type === 'jsonb')
			return ['type'=>'JSON','column_kind'=>'json'];
		return null;
	}

	/**
	 * SQL Server: lengths, precision and fractional seconds of variable types
	 * must be present (restore them with withDeclaredTypes()).
	 */
	private static function sqlsrv(string $type): array|string|null {
		if ($type === 'uniqueidentifier')
			return self::uuid();
		if ($type === 'bit')
			return self::boolean();
		if (in_array($type, ['timestamp','rowversion'], true))
			return ['type'=>'BLOB','column_kind'=>'binary','max_bytes'=>8];
		if (preg_match('/^n?(?:var)?char(?:\((max|\d+)\))?$/', $type, $m)) {
			if (!isset($m[1]))
				return 'missing length; restore it with withDeclaredTypes()';
			return $m[1] === 'max' ? self::text() : self::string((int)$m[1]);
		}
		if (in_array($type, ['text','ntext'], true))
			return self::text();
		if (preg_match('/^(?:var)?binary(?:\((max|\d+)\))?$/', $type, $m)) {
			if (!isset($m[1]))
				return 'missing length; restore it with withDeclaredTypes()';
			return ['type'=>'BLOB','column_kind'=>'binary'] + ($m[1] === 'max' ? [] : ['max_bytes'=>(int)$m[1]]);
		}
		if ($type === 'image')
			return ['type'=>'BLOB','column_kind'=>'binary'];
		$ints = ['tinyint'=>8,'smallint'=>16,'int'=>32,'bigint'=>64];
		if (isset($ints[$type]))
			return self::integer($ints[$type], $type === 'tinyint');
		if ($type === 'real')
			return self::floating(24, false);
		if ($type === 'float')
			return self::floating(53, false);
		if (preg_match('/^float\((\d+)\)$/', $type, $m))
			return self::floating((int)$m[1], false);
		if (preg_match('/^(?:decimal|numeric)(?:\((\d+)(?:,(\d+))?\))?$/', $type, $m))
			return isset($m[1]) ? self::decimal((int)$m[1], (int)($m[2] ?? 0), 38, 38, false)
				: 'missing precision; restore it with withDeclaredTypes()';
		if ($type === 'money' || $type === 'smallmoney') {
			$money = $type === 'money';
			return ['type'=>'DOUBLE','column_kind'=>'decimal','precision'=>$money ? 19 : 10,'scale'=>4,'unsigned'=>false,
				'scaled_bounds'=>$money ? ['9223372036854775808','9223372036854775807'] : ['2147483648','2147483647']];
		}
		if ($type === 'date')
			return self::date();
		// DATETIME and SMALLDATETIME have fixed precision.
		$fixed = ['datetime'=>3,'smalldatetime'=>0];
		if (preg_match('/^(datetime|smalldatetime)(?:\((\d)\))?$/', $type, $m)) {
			if (isset($m[2]) && (int)$m[2] !== $fixed[$m[1]])
				return 'unexpected fractional precision';
			return self::temporal('datetime', $fixed[$m[1]], true, false);
		}
		if (preg_match('/^(datetime2|datetimeoffset|time)(?:\(([0-7])\))?$/', $type, $m)) {
			if (!isset($m[2]))
				return 'missing fractional precision; restore it with withDeclaredTypes()';
			return self::temporal($m[1] === 'time' ? 'time' : 'datetime', (int)$m[2], true, $m[1] === 'datetimeoffset');
		}
		return null;
	}

	/**
	 * SQLite follows type affinity, so declared lengths, precision and
	 * fractional seconds impose no limit and are not reported. Names reaching NUMERIC affinity are accepted
	 * only when their meaning is clear (numeric, decimal, boolean, date/time).
	 */
	private static function sqlite(string $type): array|string|null {
		if (str_contains($type, 'int'))
			return self::integer(64, false);
		if (preg_match('/char|clob|text/', $type))
			return self::text();
		if ($type === '' || str_contains($type, 'blob'))
			return ['type'=>'BLOB','column_kind'=>'binary'];
		if (preg_match('/real|floa|doub/', $type))
			return ['type'=>'DOUBLE','column_kind'=>'floating','unsigned'=>false];
		if (in_array($type, ['bool','boolean'], true))
			return self::boolean();
		if ($type === 'date')
			return self::date();
		if (in_array($type, ['datetime','timestamp'], true))
			return ['type'=>strtoupper($type),'column_kind'=>$type,'clock_time'=>true,'timezone'=>false];
		if (preg_match('/^(numeric|decimal)(\(\d+(,\d+)?\))?$/', $type))
			return ['type'=>'DOUBLE','column_kind'=>'decimal','unsigned'=>false];
		return null;
	}

	/** MySQL/MariaDB ENUM(...) and SET(...) value lists. */
	private static function literalList(string $raw): ?array {
		if (!preg_match('/^(enum|set)\s*\((.*)\)$/is', trim($raw), $m))
			return null;
		$values = [];
		$rest = trim($m[2]);
		while (preg_match("/^'((?:[^'\\\\]|\\\\.|'')*)'\s*(,|$)/s", $rest, $v)) {
			$values[] = preg_replace_callback('/\\\\(.)/s',
				static fn($e) => ['0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'Z'=>"\x1a"][$e[1]] ?? $e[1],
				str_replace("''", "'", $v[1]));
			$rest = ltrim(substr($rest, strlen($v[0])));
			if ($v[2] === '')
				break;
			if ($rest === '')
				$rest = ',';
		}
		if (!$values || $rest !== '')
			return null;
		$lengths = array_map(static fn($v) => (int)preg_match_all('/./us', $v), $values);
		$set = strtolower($m[1]) === 'set';
		$length = $set ? array_sum($lengths) + count($values) - 1 : max($lengths);
		return ['type'=>self::stringToken($length),'column_kind'=>'enum',
			($set ? 'set_values' : 'enum_values')=>$values,'max_length'=>$length];
	}

	private static function uuid(): array {
		return ['type'=>'VARCHAR128','column_kind'=>'string','max_length'=>36,'uuid'=>true];
	}

	private static function boolean(): array {
		return ['type'=>'BOOLEAN','column_kind'=>'boolean'];
	}

	private static function text(): array {
		return ['type'=>'TEXT','column_kind'=>'text'];
	}

	private static function date(): array {
		return ['type'=>'DATE','column_kind'=>'date'];
	}

	private static function string(int $length): array {
		return ['type'=>self::stringToken($length),'column_kind'=>'string','max_length'=>$length];
	}

	private static function integer(int $bits, bool $unsigned): array {
		return ['type'=>[8=>'INT1',16=>'INT2',24=>'INT4',32=>'INT4',64=>'INT8'][$bits],'column_kind'=>'integer',
			'integer_bits'=>$bits,'unsigned'=>$unsigned];
	}

	/** FLOAT(p): 1-24 is single precision, 25-53 double; anything else throws. */
	private static function floating(int $bits, bool $unsigned): array|string {
		if ($bits < 1 || $bits > 53)
			return 'float precision out of range';
		return ['type'=>$bits <= 24 ? 'FLOAT' : 'DOUBLE','column_kind'=>'floating','unsigned'=>$unsigned];
	}

	private static function decimal(int $precision, int $scale, int $maxPrecision, int $maxScale, bool $unsigned): array|string {
		if ($precision < 1 || $precision > $maxPrecision || $scale < 0 || $scale > $maxScale || $scale > $precision)
			return 'precision or scale out of range';
		return ['type'=>'DOUBLE','column_kind'=>'decimal','precision'=>$precision,'scale'=>$scale,'unsigned'=>$unsigned];
	}

	/** $kind: datetime, timestamp or time (Cortex has no TIME token: VARCHAR128). */
	private static function temporal(string $kind, int $fraction, bool $clockTime, bool $timezone): array {
		return ['type'=>$kind === 'time' ? 'VARCHAR128' : strtoupper($kind),'column_kind'=>$kind,
			'fractional_precision'=>$fraction,'clock_time'=>$clockTime,'timezone'=>$timezone];
	}

	private static function stringToken(int $length): string {
		return $length <= 128 ? 'VARCHAR128' : ($length <= 256 ? 'VARCHAR256' : ($length <= 512 ? 'VARCHAR512' : 'LONGTEXT'));
	}
}
