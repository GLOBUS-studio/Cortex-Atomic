<?php

/**
 * Tests for DB\Cortex\Schema\ColumnType.
 *
 * 1. Raw type strings map to DT_* tokens and real limits (driver-independent).
 * 2. Round trip on the live engine: create every DT_* column with Cortex,
 *    read it back through $db->schema() + withDeclaredTypes(), describe it.
 * 3. Round trip of native DDL with explicit limits (length, precision,
 *    fractional seconds, binary bytes): they must survive the read-back.
 */
class Test_ColumnType {

	function run($db, $type)
	{
		$test = new \Test();
		$this->strings($test, $type);
		$this->cortexTokens($test, $db, $type);
		$this->nativeLimits($test, $db, $type);
		$this->regressions($test, $db, $type);
		$this->live($test, $db, $type);
		return $test->results();
	}

	/**
	 * Native columns per engine, read back from the live database: every
	 * descriptor limit is compared with a fixed value or the engine catalog.
	 */
	private function live(\Test $test, $db, string $type): void
	{
		$ct = \DB\Cortex\Schema\ColumnType::class;
		$test->expect(
			$ct::driver('MySQL') === 'mysql' && $ct::driver('mssql') === 'sqlsrv' && $ct::driver('dblib') === 'sqlsrv'
			&& $ct::driver('sybase') === 'sqlsrv' && $ct::driver('sqlite2') === 'sqlite' && $ct::driver(null) === '',
			$type.': driver names normalize to their family'
		);
		$driver = $ct::driver($db->driver());
		$mariadb = $driver === 'mysql' && stripos((string)$db->exec('SELECT VERSION() AS v')[0]['v'], 'mariadb') !== false;
		$ddl = [
			'sqlite'=>'vc VARCHAR(64), dec DECIMAL(15,6), dtm DATETIME',
			'mysql'=>"i_uns INT UNSIGNED, i_med MEDIUMINT UNSIGNED, zf INT(11) ZEROFILL, d2 DECIMAL(65,30),
				v1000 VARCHAR(1000), tx TEXT, mtx MEDIUMTEXT, ltx LONGTEXT, ts3 TIMESTAMP(3), tm3 TIME(3), y YEAR,
				bin BINARY(16), bl BLOB, e1 ENUM('a,b','it''s','UPPER','é','a\\\\b','line\\nbreak'), e3 ENUM('éé','a'),
				s1 SET('a','bb','c d'), j JSON".($mariadb ? ', u UUID' : ''),
			'pgsql'=>'n0 NUMERIC(10), nun NUMERIC, vu VARCHAR, cu CHAR, b4 BIT(4), d DATE,
				tzu TIMESTAMPTZ, tm0 TIME(0), tmz TIME WITH TIME ZONE, u UUID, jb JSONB',
			'sqlsrv'=>'d2_3 DATETIME2(3), dto DATETIMEOFFSET, tm0 TIME(0), f FLOAT, f24 FLOAT(24), r REAL,
				n38 DECIMAL(38,10), n_bare NUMERIC, n_bare2 DECIMAL, vc VARCHAR(64), nc NCHAR(10), vmax NVARCHAR(MAX),
				vbmax VARBINARY(MAX), bin BINARY(16), ts TIMESTAMP, ti TINYINT, u UNIQUEIDENTIFIER',
		][$driver] ?? null;
		if ($ddl === null)
			return;
		$table = 'test_columntype_live';
		$this->drop($db, $table);
		$db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, $ddl)");
		try {
			$schema = $db->schema($table);
			$columns = $ct::withDeclaredTypes($db, $table, $schema);
			$d = function (string $field) use ($ct, $columns, $table, $driver): array {
				try { return $ct::describe((string)$columns[$field]['type'], $table, $field, $driver); }
				catch (\InvalidArgumentException $e) { return ['error'=>$e->getMessage()]; }
			};
			$catalog = [];
			if ($driver !== 'sqlite') {
				$rows = $db->exec('SELECT COLUMN_NAME AS f, DATA_TYPE AS t, CHARACTER_MAXIMUM_LENGTH AS l, NUMERIC_PRECISION AS p, '.
					'NUMERIC_SCALE AS s, DATETIME_PRECISION AS dp FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=?'.
					($driver === 'mysql' ? ' AND TABLE_SCHEMA=DATABASE()' : ''), [1=>$table]);
				foreach ($rows as $row) {
					$row = array_change_key_case($row, CASE_LOWER);
					$catalog[$row['f']] = $row;
				}
			}
			$same = function ($expected, $actual, string $label) use ($test, $type): void {
				$test->expect($expected === $actual, $type.': '.$label
					.($expected === $actual ? '' : ' | expected '.var_export($expected, true).' got '.var_export($actual, true)));
			};
			if ($driver === 'sqlite') {
				$same($schema, $columns, 'SQLite columns pass through unchanged');
				$long = str_repeat('x', 300);
				$db->exec("INSERT INTO $table (id, vc) VALUES (1, ?)", [1=>$long]);
				$same(300, strlen($db->exec("SELECT vc FROM $table")[0]['vc']), 'SQLite stores 300 characters in VARCHAR(64)');
				$same(null, $d('vc')['max_length'] ?? null, 'SQLite VARCHAR(64) reports no length, matching the engine');
				$same(null, $d('dec')['precision'] ?? null, 'SQLite DECIMAL reports no precision');
				// SQLite stores DATETIME as text, so fractional seconds are not limited.
				$db->exec("UPDATE $table SET dtm=? WHERE id=1", [1=>'2026-01-01 10:00:00.123456']);
				$same('2026-01-01 10:00:00.123456', $db->exec("SELECT dtm FROM $table")[0]['dtm'], 'SQLite keeps 6 fractional digits in DATETIME');
				$same(null, $d('dtm')['fractional_precision'] ?? null, 'SQLite DATETIME reports no fractional precision, matching the engine');
			}
			elseif ($driver === 'mysql') {
				$schema['j']['type'] = $mariadb ? 'json' : $schema['j']['type'];
				$same($schema, $columns, $mariadb ? 'MariaDB columns pass through unchanged except JSON' : 'MySQL columns pass through unchanged');
				$same(true, $d('i_uns')['unsigned'] ?? null, 'INT UNSIGNED is unsigned');
				$same(24, $d('i_med')['integer_bits'] ?? null, 'MEDIUMINT has 24 bits');
				$same(true, $d('zf')['unsigned'] ?? null, 'ZEROFILL implies unsigned');
				$same([65, 30], [$d('d2')['precision'] ?? null, $d('d2')['scale'] ?? null], 'DECIMAL(65,30) precision and scale');
				$same(['LONGTEXT', 1000], [$d('v1000')['type'] ?? null, $d('v1000')['max_length'] ?? null], 'VARCHAR(1000) is LONGTEXT with max_length 1000');
				foreach (['tx'=>['TEXT', 65535], 'mtx'=>['LONGTEXT', 16777215], 'ltx'=>['LONGTEXT', 4294967295]] as $f => $exp)
					$same($exp, [$d($f)['type'] ?? null, $d($f)['max_bytes'] ?? null], strtoupper($f).' token and max_bytes');
				foreach (['ts3', 'tm3'] as $f)
					$same((int)$catalog[$f]['dp'], $d($f)['fractional_precision'] ?? null, $f.' fractional precision matches the catalog');
				$same(['INT2', 'year'], [$d('y')['type'] ?? null, $d('y')['column_kind'] ?? null], 'YEAR is INT2 of kind year');
				$same([16, 65535], [$d('bin')['max_bytes'] ?? null, $d('bl')['max_bytes'] ?? null], 'BINARY(16) and BLOB max_bytes');
				$same(['a,b', "it's", 'UPPER', 'é', 'a\\b', "line\nbreak"], $d('e1')['enum_values'] ?? null, 'ENUM values survive quoting and escaping');
				$same(['a', 'bb', 'c d'], $d('s1')['set_values'] ?? null, 'SET values parsed');
				foreach (['e1', 'e3', 's1'] as $f)
					$same((int)$catalog[$f]['l'], $d($f)['max_length'] ?? null, $f.' max_length (characters) matches the catalog');
				$bad = [];
				foreach ($d('e1')['enum_values'] ?? [] as $i => $value) {
					$db->exec("INSERT INTO $table (id, e1) VALUES (?, ?)", [1=>$i + 1, 2=>$value]);
					if ($db->exec("SELECT e1 FROM $table WHERE id=?", [1=>$i + 1])[0]['e1'] !== $value)
						$bad[] = $value;
				}
				$same([], $bad, 'the engine accepts every parsed ENUM value unchanged');
				if ($mariadb) {
					$same(['VARCHAR128', 36, true], [$d('u')['type'] ?? null, $d('u')['max_length'] ?? null, $d('u')['uuid'] ?? null], 'MariaDB UUID');
					// MariaDB stores JSON as LONGTEXT with a json_valid() check; Doctrine DBAL reads it back as JSON.
					$same(['JSON', 'json'], [$d('j')['type'] ?? null, $d('j')['column_kind'] ?? null], 'MariaDB JSON reads back as JSON');
				} else
					$same(['JSON', 'json'], [$d('j')['type'] ?? null, $d('j')['column_kind'] ?? null], 'MySQL JSON');
			}
			elseif ($driver === 'pgsql') {
				$same([10, 0], [$d('n0')['precision'] ?? null, $d('n0')['scale'] ?? null], 'NUMERIC(10) precision and scale');
				$test->expect(isset($d('nun')['error']), $type.': unconstrained NUMERIC has no limits and throws');
				$same('TEXT', $d('vu')['type'] ?? null, 'unbounded VARCHAR is TEXT');
				$same(1, $d('cu')['max_length'] ?? null, 'bare CHAR is restored as char(1)');
				$test->expect(isset($d('b4')['error']), $type.': BIT(4) is unsupported and throws');
				$same('DATE', $d('d')['type'] ?? null, 'DATE');
				foreach (['tzu', 'tm0', 'tmz'] as $f)
					$same((int)$catalog[$f]['dp'], $d($f)['fractional_precision'] ?? null, $f.' fractional precision matches the catalog');
				$same([true, true], [$d('tzu')['timezone'] ?? null, $d('tmz')['timezone'] ?? null], 'TIMESTAMPTZ and TIME WITH TIME ZONE report a time zone');
				$same('time', $d('tmz')['column_kind'] ?? null, 'TIME WITH TIME ZONE is of kind time');
				$same(['VARCHAR128', 36, true], [$d('u')['type'] ?? null, $d('u')['max_length'] ?? null, $d('u')['uuid'] ?? null], 'UUID');
				$same('JSON', $d('jb')['type'] ?? null, 'JSONB is JSON');
				$threw = false;
				try { $ct::describe('money', $table, 'm', 'pgsql'); }
				catch (\InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'lc_monetary'); }
				$test->expect($threw, $type.': MONEY throws (its scale follows lc_monetary)');
				$db->exec('DROP TABLE IF EXISTS "MixedCase"');
				$db->exec('CREATE TABLE "MixedCase" (id INTEGER PRIMARY KEY, "Name" VARCHAR(40), plain VARCHAR(30))');
				try {
					$mixed = $ct::withDeclaredTypes($db, 'MixedCase', $db->schema('MixedCase'));
					$same([40, 30], [$ct::describe($mixed['Name']['type'], 'MixedCase', 'Name', 'pgsql')['max_length'] ?? null,
						$ct::describe($mixed['plain']['type'], 'MixedCase', 'plain', 'pgsql')['max_length'] ?? null], 'mixed-case table and column keep lengths');
				} finally { $db->exec('DROP TABLE "MixedCase"'); }
			}
			else {
				$same([3, 7, true, 0], [$d('d2_3')['fractional_precision'] ?? null, $d('dto')['fractional_precision'] ?? null,
					$d('dto')['timezone'] ?? null, $d('tm0')['fractional_precision'] ?? null], 'DATETIME2(3), DATETIMEOFFSET and TIME(0)');
				$same('real', $catalog['f24']['t'], 'the engine reports FLOAT(24) as real');
				$same(['DOUBLE', 'FLOAT', 'FLOAT'], [$d('f')['type'] ?? null, $d('f24')['type'] ?? null, $d('r')['type'] ?? null], 'FLOAT, FLOAT(24) and REAL');
				foreach (['n38', 'n_bare', 'n_bare2'] as $f)
					$same([(int)$catalog[$f]['p'], (int)$catalog[$f]['s']], [$d($f)['precision'] ?? null, $d($f)['scale'] ?? null], $f.' precision and scale match the catalog');
				$same([18, 0], [$d('n_bare2')['precision'] ?? null, $d('n_bare2')['scale'] ?? null], 'bare DECIMAL defaults to (18,0)');
				$same([64, 10], [$d('vc')['max_length'] ?? null, $d('nc')['max_length'] ?? null], 'VARCHAR(64) and NCHAR(10) lengths');
				$same(['TEXT', 'BLOB', 16], [$d('vmax')['type'] ?? null, $d('vbmax')['type'] ?? null, $d('bin')['max_bytes'] ?? null], 'MAX types and BINARY(16)');
				$same(['BLOB', 8], [$d('ts')['type'] ?? null, $d('ts')['max_bytes'] ?? null], 'TIMESTAMP (rowversion) is 8 bytes');
				$same(['INT1', true], [$d('ti')['type'] ?? null, $d('ti')['unsigned'] ?? null], 'TINYINT is unsigned');
				$same(['VARCHAR128', 36, true], [$d('u')['type'] ?? null, $d('u')['max_length'] ?? null, $d('u')['uuid'] ?? null], 'UNIQUEIDENTIFIER');
				$db->exec("IF OBJECT_ID('MixedCase') IS NOT NULL DROP TABLE [MixedCase]");
				$db->exec('CREATE TABLE [MixedCase] (id INT PRIMARY KEY, [Name] NVARCHAR(40), plain NVARCHAR(30))');
				try {
					$mixed = $ct::withDeclaredTypes($db, 'MixedCase', $db->schema('MixedCase'));
					$same([40, 30], [$ct::describe($mixed['Name']['type'], 'MixedCase', 'Name', 'sqlsrv')['max_length'] ?? null,
						$ct::describe($mixed['plain']['type'], 'MixedCase', 'plain', 'sqlsrv')['max_length'] ?? null], 'mixed-case table and column keep lengths');
				} finally { $db->exec('DROP TABLE [MixedCase]'); }
			}
		} finally { $this->drop($db, $table); }
	}

	private function strings(\Test $test, string $type): void
	{
		$ct = \DB\Cortex\Schema\ColumnType::class;
		$mysql = ['int(11)'=>'INT4','int'=>'INT4','integer'=>'INT4','tinyint(1)'=>'BOOLEAN','tinyint'=>'INT1',
			'smallint(6)'=>'INT2','mediumint'=>'INT4','bigint unsigned'=>'INT8','FLOAT'=>'FLOAT','double'=>'DOUBLE',
			'decimal(18,6)'=>'DOUBLE','numeric(9,2)'=>'DOUBLE','varchar(128)'=>'VARCHAR128','varchar(255)'=>'VARCHAR256',
			'varchar(256)'=>'VARCHAR256','varchar(512)'=>'VARCHAR512','varchar(64)'=>'VARCHAR128','char(36)'=>'VARCHAR128',
			'text'=>'TEXT','tinytext'=>'TEXT','mediumtext'=>'LONGTEXT','longtext'=>'LONGTEXT','date'=>'DATE',
			'datetime'=>'DATETIME','timestamp'=>'TIMESTAMP','time'=>'VARCHAR128','year'=>'INT2','json'=>'JSON',
			'blob'=>'BLOB','mediumblob'=>'BLOB','longblob'=>'BLOB',"enum('a','b')"=>'VARCHAR128',
			'binary(16)'=>'BLOB','bit(1)'=>'BOOLEAN','float(7,4)'=>'FLOAT','float(53)'=>'DOUBLE','float(24)'=>'FLOAT',
			'float(7,4) unsigned'=>'FLOAT',"set('a','b')"=>'VARCHAR128'];
		$families = [
			'mysql'=>$mysql,
			'mariadb'=>['uuid'=>'VARCHAR128','varchar2(90)'=>'VARCHAR128'],
			'pgsql'=>['character varying(42)'=>'VARCHAR128','int2'=>'INT2','int4'=>'INT4','int8'=>'INT8','bigserial'=>'INT8',
				'boolean'=>'BOOLEAN','double precision'=>'DOUBLE','smallserial'=>'INT2','serial'=>'INT4','float4'=>'FLOAT',
				'float8'=>'DOUBLE','real'=>'FLOAT','character(5)'=>'VARCHAR128','time(0) without time zone'=>'VARCHAR128',
				'bytea'=>'BLOB','jsonb'=>'JSON','uuid'=>'VARCHAR128','timestamp(6) with time zone'=>'TIMESTAMP',
				'text'=>'TEXT','numeric(10,2)'=>'DOUBLE','bit(1)'=>'BOOLEAN','float'=>'DOUBLE'],
			'sqlite'=>['INTEGER'=>'INT8','integer(4)'=>'INT8','custom text'=>'TEXT','FLOATER'=>'DOUBLE','numeric'=>'DOUBLE',
				''=>'BLOB','varchar(64)'=>'TEXT','BOOLEAN'=>'BOOLEAN','date'=>'DATE','datetime'=>'DATETIME',
				'decimal(15,6)'=>'DOUBLE','REAL'=>'DOUBLE','blob'=>'BLOB','text'=>'TEXT'],
			'sqlsrv'=>['nvarchar(max)'=>'TEXT','nchar(10)'=>'VARCHAR128','bit'=>'BOOLEAN','uniqueidentifier'=>'VARCHAR128',
				'datetime2(7)'=>'DATETIME','varbinary(max)'=>'BLOB','money'=>'DOUBLE','timestamp'=>'BLOB','varchar(10)'=>'VARCHAR128',
				'binary(8)'=>'BLOB','smallmoney'=>'DOUBLE','smalldatetime'=>'DATETIME','datetimeoffset(7)'=>'DATETIME',
				'float'=>'DOUBLE','real'=>'FLOAT','tinyint'=>'INT1'],
		];
		$bad = [];
		foreach ($families as $driver => $types)
			foreach ($types as $raw => $token) {
				$got = $ct::describe((string)$raw, 't', 'f', $driver)['type'];
				if ($got !== $token)
					$bad[] = "$driver '$raw' => $got (expected $token)";
			}
		$test->expect(!$bad, $type.': ColumnType maps raw type strings to DT tokens'.($bad ? ' | '.implode('; ', $bad) : ''));

		$test->expect(
			$ct::describe(' VaRcHaR ( 64 ) ', 't', 'f', 'mysql') === ['type'=>'VARCHAR128','column_kind'=>'string','max_length'=>64]
			&& $ct::describe('NuMeRiC(18,6) UNSIGNED', 't', 'f', 'mysql') === ['type'=>'DOUBLE','column_kind'=>'decimal','precision'=>18,'scale'=>6,'unsigned'=>true]
			&& $ct::describe('MEDIUMINT(8) unsigned', 't', 'f', 'mysql') === ['type'=>'INT4','column_kind'=>'integer','integer_bits'=>24,'unsigned'=>true]
			&& $ct::describe('mediumtext', 't', 'f', 'mysql')['max_bytes'] === 16777215
			&& $ct::describe('longblob', 't', 'f', 'mysql')['max_bytes'] === 4294967295,
			$type.': ColumnType keeps lengths, precision, integer width and byte limits'
		);
		$test->expect(
			$ct::describe("enum('a,b','it''s','a\\'b','UPPER')", 't', 'f', 'mysql')['enum_values'] === ['a,b', "it's", "a'b", 'UPPER']
			&& $ct::describe("enum('éé','a')", 't', 'f', 'mysql')['max_length'] === 2,
			$type.': ColumnType unescapes enum values and counts characters, not bytes'
		);
		$thrown = [];
		foreach (['integer[]','_int4','geometry','bit(8)'] as $raw) {
			try {
				$ct::describe($raw, 'items', 'value', 'pgsql');
			} catch (\InvalidArgumentException $e) {
				if (str_contains($e->getMessage(), '"'.$raw.'" in table "items" field "value"'))
					$thrown[] = $raw;
			}
		}
		$test->expect(count($thrown) === 4, $type.': ColumnType throws on unsupported types, naming table and field');

		// Types whose limits are unknown, or that the engine does not have, throw instead of being guessed.
		$unresolved = [
			'mysql'=>['varchar','char','binary','varbinary(max)','real','money','serial','int4','jsonb','text unsigned',
				'character varying(64)','datetime2(3)','float(60)','double(10)','decimal(66,2)','uniqueidentifier'],
			'pgsql'=>['numeric','character varying','varchar','char','bit','money','timestamp','timestamp without time zone',
				'time with time zone','timestamptz','decimal(10,2) unsigned','datetime','nvarchar(10)','tinyint','numeric(1001)'],
			'sqlsrv'=>['varchar','nvarchar','char','binary','varbinary','decimal','numeric','datetime2','datetimeoffset','time',
				'datetime(7)','int unsigned','boolean','jsonb','decimal(39,2)','bytea'],
			'sqlite'=>['custom','json','uuid','something weird','money'],
		];
		$guessed = [];
		foreach ($unresolved as $driver => $types)
			foreach ($types as $raw) {
				try { $guessed[] = "$driver '$raw' => ".$ct::describe($raw, 't', 'f', $driver)['type']; }
				catch (\InvalidArgumentException $e) {}
			}
		$test->expect(!$guessed, $type.': ColumnType throws instead of guessing unknown limits'.($guessed ? ' | '.implode('; ', $guessed) : ''));
		$threw = 0;
		foreach (['', 'oci', 'firebird', 'odbc'] as $driver) {
			try { $ct::describe('varchar(64)', 't', 'f', $driver); }
			catch (\InvalidArgumentException $e) { $threw += (int)str_contains($e->getMessage(), 'Unsupported driver'); }
		}
		$test->expect($threw === 4, $type.': ColumnType throws for unknown or missing drivers');
	}

	private function cortexTokens(\Test $test, $db, string $type): void
	{
		$driver = \DB\Cortex\Schema\ColumnType::driver($db->driver());
		$tokens = ['BOOLEAN','INT1','INT2','INT4','INT8','FLOAT','DOUBLE','VARCHAR128','VARCHAR256','VARCHAR512',
			'TEXT','LONGTEXT','DATE','DATETIME','TIMESTAMP','BLOB'];
		// Where an engine has no matching column type, Cortex creates the nearest one.
		$expected = array_combine($tokens, $tokens);
		$expected = array_replace($expected, [
			'sqlite'=>['INT1'=>'INT8','INT2'=>'INT8','INT4'=>'INT8','FLOAT'=>'DOUBLE','VARCHAR128'=>'TEXT','VARCHAR256'=>'TEXT',
				'VARCHAR512'=>'TEXT','LONGTEXT'=>'TEXT','TIMESTAMP'=>'DATETIME'],
			'pgsql'=>['INT1'=>'INT2','FLOAT'=>'DOUBLE','LONGTEXT'=>'TEXT','DATETIME'=>'TIMESTAMP'],
			'sqlsrv'=>['FLOAT'=>'DOUBLE','LONGTEXT'=>'TEXT','TIMESTAMP'=>'DATETIME'],
		][$driver] ?? []);
		$columns = $this->createWithCortex($db, $tokens);
		$bad = [];
		$described = [];
		foreach ($tokens as $token) {
			$name = 'c_'.strtolower($token);
			$raw = $columns[$name]['type'] ?? '(missing)';
			try {
				$described[$token] = \DB\Cortex\Schema\ColumnType::describe($raw, 'test_columntype', $name, $driver);
				if ($described[$token]['type'] !== $expected[$token])
					$bad[] = "$token -> '$raw' -> {$described[$token]['type']}";
			} catch (\InvalidArgumentException $e) {
				$bad[] = "$token -> '$raw' -> unsupported";
			}
		}
		$test->expect(!$bad, $type.': every Cortex DT_* column reads back as the matching type'.($bad ? ' | '.implode('; ', $bad) : ''));
		// SQLite enforces no declared length or precision, so none is reported there.
		if ($driver === 'sqlite') {
			$test->expect(
				!isset($described['VARCHAR128']['max_length']) && !isset($described['DOUBLE']['precision']),
				$type.': SQLite columns report no length or precision (SQLite does not enforce them)'
			);
			return;
		}
		$test->expect(
			($described['VARCHAR128']['max_length'] ?? null) === 128
			&& ($described['VARCHAR256']['max_length'] ?? null) === 255
			&& ($described['VARCHAR512']['max_length'] ?? null) === 512,
			$type.': Cortex VARCHAR columns read back with their real length'
		);
		$test->expect(
			($described['DOUBLE']['precision'] ?? null) === 18
			&& ($described['DOUBLE']['scale'] ?? null) === 6,
			$type.': Cortex DOUBLE (decimal) columns read back with precision and scale'
		);
		$test->expect(
			($described['INT2']['integer_bits'] ?? null) === 16,
			$type.': Cortex integer columns read back with their storage width'
		);
	}

	private function nativeLimits(\Test $test, $db, string $type): void
	{
		$driver = \DB\Cortex\Schema\ColumnType::driver($db->driver());
		$ddl = [
			'mysql'=>'s VARCHAR(64), d DECIMAL(10,2), t DATETIME(3), b VARBINARY(16)',
			'pgsql'=>'s VARCHAR(64), d NUMERIC(10,2), t TIMESTAMP(3), b BIT(1)',
			'sqlsrv'=>'s NVARCHAR(64), d DECIMAL(10,2), t DATETIME2(3), b VARBINARY(16)',
		][$driver] ?? null;
		if ($ddl === null)
			return;
		$table = 'test_columntype_native';
		$this->drop($db, $table);
		$db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, $ddl)");
		$columns = \DB\Cortex\Schema\ColumnType::withDeclaredTypes($db, $table, $db->schema($table));
		$described = \DB\Cortex\Schema\ColumnType::describeTable($db, $table);
		$this->drop($db, $table);
		$d = fn($f) => \DB\Cortex\Schema\ColumnType::describe($columns[$f]['type'], $table, $f, $driver);
		$test->expect(
			$described === array_combine(array_keys($columns), array_map($d, array_keys($columns))),
			$type.': describeTable() matches withDeclaredTypes() + describe()'
		);
		$test->expect(
			($d('s')['max_length'] ?? null) === 64,
			$type.': VARCHAR(64) keeps max_length 64 after read-back (got '.var_export($columns['s']['type'], true).')'
		);
		$test->expect(
			($d('d')['precision'] ?? null) === 10 && ($d('d')['scale'] ?? null) === 2,
			$type.': DECIMAL(10,2) keeps precision and scale after read-back (got '.var_export($columns['d']['type'], true).')'
		);
		if (isset($columns['t']))
			$test->expect(
				($d('t')['fractional_precision'] ?? null) === 3,
				$type.': fractional seconds (3) survive read-back (got '.var_export($columns['t']['type'], true).')'
			);
		if (isset($columns['b']))
			$test->expect(
				$driver === 'pgsql' ? $d('b')['type'] === 'BOOLEAN' : ($d('b')['max_bytes'] ?? null) === 16,
				$type.': '.($driver === 'pgsql' ? 'BIT(1) reads back as BOOLEAN' : 'VARBINARY(16) keeps max_bytes 16').' (got '.var_export($columns['b']['type'], true).')'
			);
	}

	private function regressions(\Test $test, $db, string $type): void
	{
		$ct = \DB\Cortex\Schema\ColumnType::class;
		foreach (['sqlsrv'=>['money'=>4, 'smallmoney'=>4]] as $driver => $types)
			foreach ($types as $raw => $scale) {
				$d = $ct::describe($raw, 't', 'f', $driver);
				$test->expect($d['scale'] === $scale && $d['precision'] === ($raw === 'money' ? 19 : 10)
					&& $d['scaled_bounds'] === ($raw === 'money' ? ['9223372036854775808','9223372036854775807'] : ['2147483648','2147483647']),
					$type.': regression money scale and bounds '.$driver.' '.$raw);
			}
		foreach (['datetime'=>3, 'smalldatetime'=>0, 'datetime(3)'=>3, 'datetime2(7)'=>7, 'datetimeoffset(7)'=>7, 'time(7)'=>7] as $raw => $precision)
			$test->expect($ct::describe($raw, 't', 'f', 'sqlsrv')['fractional_precision'] === $precision,
				$type.': regression SQL Server fractional precision '.$raw);
		foreach (['mysql','mariadb'] as $driver)
			foreach (['char','varchar','binary','varbinary'] as $raw) {
				$d = [];
				try { $d = $ct::describe($raw.'(0)', 't', 'f', $driver); }
				catch (\InvalidArgumentException $e) {}
				$binary = str_contains($raw, 'binary');
				$test->expect(($d['type'] ?? null) === ($binary ? 'BLOB' : 'VARCHAR128')
					&& ($d[$binary ? 'max_bytes' : 'max_length'] ?? null) === 0,
					$type.': regression zero length '.$driver.' '.$raw);
			}
		$driver = $ct::driver($db->driver());
		$table = 'test_columntype_regression';
		$ddl = ['mysql'=>'c CHAR(0), v VARCHAR(0), b BINARY(0), vb VARBINARY(0)',
			'pgsql'=>'m MONEY', 'sqlsrv'=>'m MONEY, sm SMALLMONEY, dt DATETIME, sdt SMALLDATETIME, d2 DATETIME2, dto DATETIMEOFFSET, tm TIME'][$driver] ?? null;
		if ($ddl !== null) {
			$this->drop($db, $table);
			$db->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, $ddl)");
			try {
				$columns = $ct::withDeclaredTypes($db, $table, $db->schema($table));
				foreach ($columns as $field => $column) {
					if ($field === 'id') continue;
					$d = [];
					$threw = false;
					try { $d = $ct::describe($column['type'], $table, $field, $driver); }
					catch (\InvalidArgumentException $e) { $threw = true; }
					if ($driver === 'pgsql')
						$ok = $threw;
					elseif ($driver === 'mysql')
						$ok = ($d[in_array($field, ['b','vb'], true) ? 'max_bytes' : 'max_length'] ?? null) === 0;
					elseif (in_array($field, ['m','sm'], true))
						$ok = ($d['scale'] ?? null) === 4;
					else {
						$row = array_change_key_case($db->exec('SELECT DATETIME_PRECISION AS p FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?', [1=>$table, 2=>$field])[0], CASE_LOWER);
						$ok = $d['fractional_precision'] === (int)$row['p'];
					}
					$test->expect($ok, $type.': regression live limits '.$field);
				}
				if (in_array($driver, ['pgsql','sqlsrv'], true)) {
					$threw = false;
					try { $ct::withDeclaredTypes($db, $table, $db->schema($table) + ['ghost'=>['type'=>'varchar']]); }
					catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'ghost'); }
					$test->expect($threw, $type.': withDeclaredTypes() throws for a column missing from the catalog');
				}
			} finally { $this->drop($db, $table); }
		}
		if (!in_array($driver, ['pgsql','sqlsrv'], true)) return;
		$a = 'ct_schema_a';
		$b = 'ct_schema_b';
		$name = 'ct_schema_events';
		$db->exec("CREATE SCHEMA $a");
		$db->exec("CREATE SCHEMA $b");
		try {
			$db->exec("CREATE TABLE $a.$name (id INTEGER PRIMARY KEY, name VARCHAR(64), note VARCHAR(200))");
			$check = function ($table, $length) use ($ct, $db, $driver) {
				$c = $ct::withDeclaredTypes($db, $table, $db->schema($table));
				return ($ct::describe($c['name']['type'], $table, 'name', $driver)['max_length'] ?? null) === $length;
			};
			$test->expect($check($name, 64), $type.': regression unqualified unique non-default schema keeps limits');
			$db->exec("CREATE TABLE $b.$name (id INTEGER PRIMARY KEY, name VARCHAR(17), note VARCHAR(31))");
			$test->expect($check("$a.$name", 64) && $check("$b.$name", 17), $type.': regression qualified duplicate tables stay separate');
			$threw = false;
			try { $check($name, 64); }
			catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), $name); }
			$test->expect($threw, $type.': regression ambiguous unresolved table throws');
			if ($driver === 'pgsql') {
				$path = $db->exec('SHOW search_path')[0]['search_path'];
				try {
					$db->exec("SET search_path TO $b, $a, public");
					$test->expect($check($name, 17), $type.': regression search_path resolves duplicate table');
				} finally { $db->exec('SET search_path TO '.$path); }
			} else {
				$db->exec("CREATE TABLE dbo.$name (id INTEGER PRIMARY KEY, name VARCHAR(23), note VARCHAR(37))");
				try { $test->expect($check($name, 23), $type.': regression dbo resolves duplicate table'); }
				finally { $db->exec("DROP TABLE dbo.$name"); }
			}
		} finally {
			$db->exec("DROP TABLE IF EXISTS $a.$name");
			$db->exec("DROP TABLE IF EXISTS $b.$name");
			$db->exec("DROP SCHEMA $a");
			$db->exec("DROP SCHEMA $b");
		}
	}

	/** @return array<string, array> withDeclaredTypes() output for the created table */
	private function createWithCortex($db, array $tokens): array
	{
		$schema = new \DB\Cortex\Schema\Schema($db);
		$table = 'test_columntype';
		if (in_array($table, $schema->getTables()))
			$schema->dropTable($table);
		$tc = $schema->createTable($table);
		foreach ($tokens as $token)
			$tc->addColumn('c_'.strtolower($token))->type(constant(\DB\Cortex\Schema\Schema::class.'::DT_'.$token));
		$tc->build();
		$columns = \DB\Cortex\Schema\ColumnType::withDeclaredTypes($db, $table, $db->schema($table));
		$schema->dropTable($table);
		return $columns;
	}

	private function drop($db, string $table): void
	{
		$schema = new \DB\Cortex\Schema\Schema($db);
		if (in_array($table, $schema->getTables()))
			$schema->dropTable($table);
	}
}
