<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\core;

/**
 * Registro IP: una riga per indirizzo con contatori ed esito dell'ultima verifica
 */
class ip_log
{
	const EVENTS = [
		'challenged'	=> 'count_challenged',
		'passed'		=> 'count_passed',
		'failed'		=> 'count_failed',
		'blocked'		=> 'count_blocked',
	];

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var string */
	protected $table;

	public function __construct($db, $config, $table_prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->table = $table_prefix . 'hv_log';
	}

	public function get_table()
	{
		return $this->table;
	}

	/**
	 * Registra un evento per un indirizzo IP
	 *
	 * @param string $event challenged|passed|failed|blocked
	 */
	public function record($ip, $event, $mode, $ua, $force = false)
	{
		if ((!$force && empty($this->config['hv_log_enabled'])) || !isset(self::EVENTS[$event]) || $ip === '')
		{
			return;
		}

		$now = time();
		$col = self::EVENTS[$event];
		$ua = substr(preg_replace('/[^\x20-\x7E]/', '', (string) $ua), 0, 255);
		$mode = substr((string) $mode, 0, 20);

		$id = $this->get_id($ip);

		if (!$id)
		{
			$row = [
				'log_ip'			=> substr($ip, 0, 40),
				'first_seen'		=> $now,
				'last_seen'			=> $now,
				'count_challenged'	=> 0,
				'count_passed'		=> 0,
				'count_failed'		=> 0,
				'count_blocked'		=> 0,
				'last_result'		=> $event,
				'last_mode'			=> $mode,
				'last_ua'			=> $ua,
				'fail_streak'		=> ($event === 'failed') ? 1 : 0,
				'fail_last'			=> ($event === 'failed') ? $now : 0,
			];
			$row[$col] = 1;

			$this->db->sql_return_on_error(true);
			$inserted = $this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row));
			$this->db->sql_return_on_error(false);

			if ($inserted)
			{
				return;
			}

			// inserimento concorrente: la riga ora esiste, aggiorniamo
			$id = $this->get_id($ip);
			if (!$id)
			{
				return;
			}
		}

		$set = $col . ' = ' . $col . ' + 1, last_seen = ' . $now . ", last_result = '" . $this->db->sql_escape($event) . "'";

		if ($event !== 'challenged')
		{
			$set .= ", last_mode = '" . $this->db->sql_escape($mode) . "', last_ua = '" . $this->db->sql_escape($ua) . "'";
		}

		if ($event === 'failed')
		{
			// il conteggio riparte da 1 se l'ultimo fallimento è più vecchio della durata del blocco:
			// un IP sbloccato non torna bloccato al primo errore, ma solo dopo altri N fallimenti
			$window_start = $now - max(1, (int) $this->config['hv_lock_minutes']) * 60;
			$set .= ', fail_streak = CASE WHEN fail_last < ' . $window_start . ' THEN 1 ELSE fail_streak + 1 END, fail_last = ' . $now;
		}
		else if ($event === 'passed')
		{
			$set .= ', fail_streak = 0';
		}

		$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $set . ' WHERE log_id = ' . (int) $id);
	}

	protected function get_id($ip)
	{
		$sql = 'SELECT log_id FROM ' . $this->table . "
			WHERE log_ip = '" . $this->db->sql_escape($ip) . "'";
		$result = $this->db->sql_query_limit($sql, 1);
		$id = (int) $this->db->sql_fetchfield('log_id');
		$this->db->sql_freeresult($result);

		return $id;
	}

	/**
	 * Troppi tentativi falliti di fila nella finestra di blocco
	 */
	public function is_locked($ip, $max_failures, $lock_minutes)
	{
		if ($max_failures < 1 || empty($this->config['hv_log_enabled']))
		{
			return false;
		}

		$sql = 'SELECT fail_streak, fail_last FROM ' . $this->table . "
			WHERE log_ip = '" . $this->db->sql_escape($ip) . "'";
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row
			&& (int) $row['fail_streak'] >= $max_failures
			&& (int) $row['fail_last'] > time() - $lock_minutes * 60;
	}

	public function unlock($ids)
	{
		$ids = array_map('intval', (array) $ids);
		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('UPDATE ' . $this->table . ' SET fail_streak = 0 WHERE ' . $this->db->sql_in_set('log_id', $ids));
		return $this->db->sql_affectedrows();
	}

	/**
	 * Elenco per l'ACP
	 *
	 * @return array [righe, totale]
	 */
	public function get_entries($start, $limit, $search = '', $filter = '', $sort = 'last_seen', $dir = 'DESC')
	{
		$where = [];

		if ($search !== '')
		{
			$where[] = 'log_ip ' . $this->db->sql_like_expression($this->db->get_any_char() . $search . $this->db->get_any_char());
		}

		if (isset(self::EVENTS[$filter]))
		{
			$where[] = "last_result = '" . $this->db->sql_escape($filter) . "'";
		}
		else if ($filter === 'locked')
		{
			$where[] = 'fail_streak > 0';
		}

		$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

		$allowed_sort = ['last_seen', 'first_seen', 'log_ip', 'count_challenged', 'count_passed', 'count_failed', 'count_blocked'];
		$sort = in_array($sort, $allowed_sort, true) ? $sort : 'last_seen';
		$dir = ($dir === 'ASC') ? 'ASC' : 'DESC';

		$result = $this->db->sql_query('SELECT COUNT(log_id) AS total FROM ' . $this->table . $where_sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->table . $where_sql . ' ORDER BY ' . $sort . ' ' . $dir, $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return [$rows, $total];
	}

	public function get_stats()
	{
		$sql = 'SELECT COUNT(log_id) AS ips,
				SUM(count_challenged) AS challenged,
				SUM(count_passed) AS passed,
				SUM(count_failed) AS failed,
				SUM(count_blocked) AS blocked
			FROM ' . $this->table;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return array_map('intval', $row ?: ['ips' => 0, 'challenged' => 0, 'passed' => 0, 'failed' => 0, 'blocked' => 0]);
	}

	public function get_ips($ids)
	{
		$ids = array_map('intval', (array) $ids);
		if (empty($ids))
		{
			return [];
		}

		$result = $this->db->sql_query('SELECT log_ip FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('log_id', $ids));
		$ips = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$ips[] = $row['log_ip'];
		}
		$this->db->sql_freeresult($result);

		return $ips;
	}

	public function delete_ids($ids)
	{
		$ids = array_map('intval', (array) $ids);
		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('log_id', $ids));
		return $this->db->sql_affectedrows();
	}

	public function delete_all()
	{
		$this->db->sql_query('DELETE FROM ' . $this->table);
		return $this->db->sql_affectedrows();
	}

	/**
	 * Elimina le righe non più viste da $days giorni
	 */
	public function prune($days)
	{
		$days = (int) $days;
		if ($days < 1)
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE last_seen < ' . (time() - $days * 86400));
		return $this->db->sql_affectedrows();
	}
}
