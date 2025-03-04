<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb\db\driver;

/**
* Unified ODBC functions
* Unified ODBC functions support any database having ODBC driver, for example Adabas D, IBM DB2, iODBC, Solid, Sybase SQL Anywhere...
* Here we only support MSSQL Server 2000+ because of the provided schema
*
* @note number of bytes returned for returning data depends on odbc.defaultlrl php.ini setting.
* If it is limited to 4K for example only 4K of data is returned max, resulting in incomplete theme data for example.
* @note odbc.defaultbinmode may affect UTF8 characters
*/
class mssql_odbc extends \phpbb\db\driver\mssql_base
{
	var $connect_error = '';

	/**
	* {@inheritDoc}
	*/
	function sql_connect($sqlserver, $sqluser, $sqlpassword, $database, $port = false, $persistency = false, $new_link = false)
	{
		$this->persistency = $persistency;
		$this->user = $sqluser;
		$this->dbname = $database;

		$port_delimiter = (defined('PHP_OS') && substr(PHP_OS, 0, 3) === 'WIN') ? ',' : ':';
		$this->server = $sqlserver . (($port) ? $port_delimiter . $port : '');

		$max_size = @ini_get('odbc.defaultlrl');
		if (!empty($max_size))
		{
			$unit = strtolower(substr($max_size, -1, 1));
			$max_size = (int) $max_size;

			if ($unit == 'k')
			{
				$max_size = floor($max_size / 1024);
			}
			else if ($unit == 'g')
			{
				$max_size *= 1024;
			}
			else if (is_numeric($unit))
			{
				$max_size = floor((int) ($max_size . $unit) / 1048576);
			}
			$max_size = max(8, $max_size) . 'M';

			@ini_set('odbc.defaultlrl', $max_size);
		}

		if ($this->persistency)
		{
			if (!function_exists('odbc_pconnect'))
			{
				$this->connect_error = 'odbc_pconnect function does not exist, is odbc extension installed?';
				return $this->sql_error('');
			}
			$this->db_connect_id = @odbc_pconnect($this->server, $this->user, $sqlpassword);
		}
		else
		{
			if (!function_exists('odbc_connect'))
			{
				$this->connect_error = 'odbc_connect function does not exist, is odbc extension installed?';
				return $this->sql_error('');
			}
			$this->db_connect_id = @odbc_connect($this->server, $this->user, $sqlpassword);
		}

		return ($this->db_connect_id) ? $this->db_connect_id : $this->sql_error('');
	}

	/**
	* {@inheritDoc}
	*/
	function sql_server_info($raw = false, $use_cache = true)
	{
		global $cache;

		if (!$use_cache || empty($cache) || ($this->sql_server_version = $cache->get('mssqlodbc_version')) === false)
		{
			$result_id = @odbc_exec($this->db_connect_id, "SELECT SERVERPROPERTY('productversion'), SERVERPROPERTY('productlevel'), SERVERPROPERTY('edition')");

			$row = false;
			if ($result_id)
			{
				$row = odbc_fetch_array($result_id);
				odbc_free_result($result_id);
			}

			$this->sql_server_version = ($row) ? trim(implode(' ', $row)) : 0;

			if (!empty($cache) && $use_cache)
			{
				$cache->put('mssqlodbc_version', $this->sql_server_version);
			}
		}

		if ($raw)
		{
			return (string) $this->sql_server_version;
		}

		return ($this->sql_server_version) ? 'MSSQL (ODBC)<br />' . $this->sql_server_version : 'MSSQL (ODBC)';
	}

	/**
	* {@inheritDoc}
	*/
	protected function _sql_transaction(string $status = 'begin'): bool
	{
		switch ($status)
		{
			case 'begin':
				return (bool) @odbc_exec($this->db_connect_id, 'BEGIN TRANSACTION');

			case 'commit':
				return (bool) @odbc_exec($this->db_connect_id, 'COMMIT TRANSACTION');

			case 'rollback':
				return (bool) @odbc_exec($this->db_connect_id, 'ROLLBACK TRANSACTION');
		}

		return true;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_query($query = '', $cache_ttl = 0)
	{
		if ($query != '')
		{
			global $cache;

			if ($this->debug_sql_explain)
			{
				$this->sql_report('start', $query);
			}
			else if ($this->debug_load_time)
			{
				$this->curtime = microtime(true);
			}

			$this->last_query_text = $query;
			$this->query_result = ($cache && $cache_ttl) ? $cache->sql_load($query) : false;
			$this->sql_add_num_queries($this->query_result);

			if ($this->query_result === false)
			{
				try
				{
					$this->query_result = @odbc_exec($this->db_connect_id, $query);
				}
				catch (\Error $e)
				{
					// Do nothing as SQL driver will report the error
				}

				if ($this->query_result === false)
				{
					$this->sql_error($query);
				}

				if ($this->debug_sql_explain)
				{
					$this->sql_report('stop', $query);
				}
				else if ($this->debug_load_time)
				{
					$this->sql_time += microtime(true) - $this->curtime;
				}

				if (!$this->query_result)
				{
					return false;
				}

				$safe_query_id = $this->clean_query_id($this->query_result);

				if ($cache && $cache_ttl)
				{
					$this->open_queries[$safe_query_id] = $this->query_result;
					$this->query_result = $cache->sql_save($this, $query, $this->query_result, $cache_ttl);
				}
				else if (strpos($query, 'SELECT') === 0)
				{
					$this->open_queries[$safe_query_id] = $this->query_result;
				}
			}
			else if ($this->debug_sql_explain)
			{
				$this->sql_report('fromcache', $query);
			}
		}
		else
		{
			return false;
		}

		return $this->query_result;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function _sql_query_limit(string $query, int $total, int $offset = 0, int $cache_ttl = 0)
	{
		$this->query_result = false;

		// Since TOP is only returning a set number of rows we won't need it if total is set to 0 (return all rows)
		if ($total)
		{
			// We need to grab the total number of rows + the offset number of rows to get the correct result
			if (strpos($query, 'SELECT DISTINCT') === 0)
			{
				$query = 'SELECT DISTINCT TOP ' . ($total + $offset) . ' ' . substr($query, 15);
			}
			else
			{
				$query = 'SELECT TOP ' . ($total + $offset) . ' ' . substr($query, 6);
			}
		}

		$result = $this->sql_query($query, $cache_ttl);

		// Seek by $offset rows
		if ($offset)
		{
			$this->sql_rowseek($offset, $result);
		}

		return $result;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_affectedrows()
	{
		return ($this->db_connect_id) ? @odbc_num_rows($this->query_result) : false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_fetchrow($query_id = false)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		$safe_query_id = $this->clean_query_id($query_id);
		if ($cache && $cache->sql_exists($safe_query_id))
		{
			return $cache->sql_fetchrow($safe_query_id);
		}

		return ($query_id) ? odbc_fetch_array($query_id) : false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function sql_last_inserted_id()
	{
		$result_id = @odbc_exec($this->db_connect_id, 'SELECT @@IDENTITY');

		if ($result_id)
		{
			if (odbc_fetch_array($result_id))
			{
				$id = odbc_result($result_id, 1);
				odbc_free_result($result_id);
				return $id;
			}
			odbc_free_result($result_id);
		}

		return false;
	}

	/**
	* {@inheritDoc}
	*/
	function sql_freeresult($query_id = false)
	{
		global $cache;

		if ($query_id === false)
		{
			$query_id = $this->query_result;
		}

		$safe_query_id = $this->clean_query_id($query_id);
		if ($cache && $cache->sql_exists($safe_query_id))
		{
			$cache->sql_freeresult($safe_query_id);
		}
		else if (isset($this->open_queries[$safe_query_id]))
		{
			unset($this->open_queries[$safe_query_id]);
			odbc_free_result($query_id);
		}
	}

	/**
	* {@inheritDoc}
	*/
	protected function _sql_error(): array
	{
		if (function_exists('odbc_errormsg'))
		{
			$error = array(
				'message'	=> @odbc_errormsg(),
				'code'		=> @odbc_error(),
			);
		}
		else
		{
			$error = array(
				'message'	=> $this->connect_error,
				'code'		=> '',
			);
		}

		return $error;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function _sql_close(): bool
	{
		@odbc_close($this->db_connect_id);
		return true;
	}

	/**
	* {@inheritDoc}
	*/
	protected function _sql_report(string $mode, string $query = ''): void
	{
		switch ($mode)
		{
			case 'start':
			break;

			case 'fromcache':
				$endtime = explode(' ', microtime());
				$endtime = $endtime[0] + $endtime[1];

				$result = @odbc_exec($this->db_connect_id, $query);
				if ($result)
				{
					while ($void = odbc_fetch_array($result))
					{
						// Take the time spent on parsing rows into account
					}
					odbc_free_result($result);
				}

				$splittime = explode(' ', microtime());
				$splittime = $splittime[0] + $splittime[1];

				$this->sql_report('record_fromcache', $query, $endtime, $splittime);

			break;
		}
	}
}
