<?php
/**
 *
 * Human Verify. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026, Salvo Cortesiano, https://www.netshadows.de/ombra
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\humanverify\cron\task;

/**
 * Elimina dal registro gli IP non più visti da N giorni (una volta al giorno)
 */
class prune_log extends \phpbb\cron\task\base
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \salvocortesiano\humanverify\core\ip_log */
	protected $ip_log;

	public function __construct($config, $ip_log)
	{
		$this->config = $config;
		$this->ip_log = $ip_log;
	}

	public function run()
	{
		$this->ip_log->prune((int) $this->config['hv_log_retention_days']);
		$this->config->set('hv_prune_last_gc', time(), false);
	}

	public function is_runnable()
	{
		return (int) $this->config['hv_log_retention_days'] > 0;
	}

	public function should_run()
	{
		return (int) $this->config['hv_prune_last_gc'] < time() - (int) $this->config['hv_prune_gc'];
	}
}
