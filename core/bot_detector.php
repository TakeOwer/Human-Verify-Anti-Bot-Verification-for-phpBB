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

class bot_detector
{
	/**
	 * Elenco predefinito di user-agent da intercettare (scraper IA e strumenti automatici)
	 */
	const DEFAULT_BAD_AGENTS = "GPTBot\nChatGPT-User\nOAI-SearchBot\nClaudeBot\nClaude-Web\nanthropic-ai\nCCBot\nBytespider\nPerplexityBot\nAmazonbot\nmeta-externalagent\nFacebookBot\nGoogle-Extended\nApplebot-Extended\nDiffbot\nImagesiftBot\nOmgilibot\ncohere-ai\nYouBot\nPetalBot\nSemrushBot\nAhrefsBot\nMJ12bot\nDotBot\nDataForSeoBot\nBLEXBot\nserpstatbot\nScrapy\npython-requests\npython-urllib\naiohttp\nhttpx\nGo-http-client\nJava/\nokhttp\nlibwww-perl\ncurl/\nWget\nHeadlessChrome\nPhantomJS\nnode-fetch\naxios/";

	/**
	 * Motori di ricerca verificabili con DNS inverso + diretto
	 */
	const SEARCH_ENGINES = [
		'googlebot'			=> ['.googlebot.com', '.google.com', '.googleusercontent.com'],
		'google-inspectiontool' => ['.googlebot.com', '.google.com'],
		'bingbot'			=> ['.search.msn.com'],
		'msnbot'			=> ['.search.msn.com'],
		'yandex'			=> ['.yandex.ru', '.yandex.net', '.yandex.com'],
		'baiduspider'		=> ['.baidu.com', '.baidu.jp'],
		'applebot'			=> ['.applebot.apple.com'],
		'duckduckbot'		=> ['.duckduckgo.com'],
	];

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\config\db_text */
	protected $config_text;

	/** @var \phpbb\cache\driver\driver_interface */
	protected $cache;

	/** @var array|null */
	protected $bad_agents;

	/** @var array|null */
	protected $whitelist;

	public function __construct($config, $config_text, $cache)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->cache = $cache;
	}

	/**
	 * Converte un testo (una voce per riga, # = commento) in elenco
	 */
	public function parse_list($text)
	{
		$items = [];
		foreach (preg_split('/\R/', (string) $text) as $line)
		{
			$line = trim(preg_replace('/#.*$/', '', $line));
			if ($line !== '')
			{
				$items[] = $line;
			}
		}

		return array_values(array_unique($items));
	}

	public function get_bad_agents()
	{
		if ($this->bad_agents === null)
		{
			$this->bad_agents = $this->parse_list($this->config_text->get('hv_bad_agents'));
		}

		return $this->bad_agents;
	}

	public function get_whitelist()
	{
		if ($this->whitelist === null)
		{
			$this->whitelist = $this->parse_list($this->config_text->get('hv_ip_whitelist'));
		}

		return $this->whitelist;
	}

	/**
	 * @return string|false la voce dell'elenco trovata, false se lo user-agent è pulito
	 */
	public function match_bad_agent($ua)
	{
		$ua = (string) $ua;
		foreach ($this->get_bad_agents() as $needle)
		{
			if (stripos($ua, $needle) !== false)
			{
				return $needle;
			}
		}

		return false;
	}

	public function is_whitelisted($ip)
	{
		foreach ($this->get_whitelist() as $entry)
		{
			if ($this->ip_matches($ip, $entry))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Confronta un IP con un indirizzo singolo o un intervallo CIDR (IPv4/IPv6)
	 */
	public function ip_matches($ip, $entry)
	{
		if (strpos($entry, '/') === false)
		{
			$a = @inet_pton($ip);
			$b = @inet_pton($entry);
			return $a !== false && $b !== false && $a === $b;
		}

		list($subnet, $bits) = explode('/', $entry, 2);
		$ip_bin = @inet_pton($ip);
		$subnet_bin = @inet_pton($subnet);

		if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin) || !ctype_digit($bits))
		{
			return false;
		}

		$bits = (int) $bits;
		$max = strlen($ip_bin) * 8;
		if ($bits > $max)
		{
			return false;
		}

		$bytes = intdiv($bits, 8);
		$rest = $bits % 8;

		if (substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes))
		{
			return false;
		}

		if ($rest === 0)
		{
			return true;
		}

		$mask = (0xFF << (8 - $rest)) & 0xFF;
		return (ord($ip_bin[$bytes]) & $mask) === (ord($subnet_bin[$bytes]) & $mask);
	}

	/**
	 * Voce valida per la whitelist?
	 */
	public function is_valid_entry($entry)
	{
		if (strpos($entry, '/') === false)
		{
			return @inet_pton($entry) !== false;
		}

		list($subnet, $bits) = explode('/', $entry, 2);
		$bin = @inet_pton($subnet);

		return $bin !== false && ctype_digit($bits) && (int) $bits <= strlen($bin) * 8;
	}

	/**
	 * Verifica DNS inversa + diretta di un motore di ricerca dichiarato nello user-agent.
	 *
	 * @return bool|null true = verificato, false = falso motore di ricerca, null = UA non verificabile
	 */
	public function verify_search_engine($ua, $ip)
	{
		$domains = null;
		foreach (self::SEARCH_ENGINES as $needle => $suffixes)
		{
			if (stripos((string) $ua, $needle) !== false)
			{
				$domains = $suffixes;
				break;
			}
		}

		if ($domains === null)
		{
			return null;
		}

		$key = '_hv_dns_' . md5($ip . '|' . implode(',', $domains));
		$cached = $this->cache->get($key);
		if ($cached !== false)
		{
			return $cached === 'ok';
		}

		$ok = false;
		$host = @gethostbyaddr($ip);

		if ($host && $host !== $ip)
		{
			foreach ($domains as $suffix)
			{
				if (substr(strtolower($host), -strlen($suffix)) === $suffix)
				{
					$records = @gethostbynamel($host) ?: [];
					if (strpos($ip, ':') !== false && function_exists('dns_get_record'))
					{
						foreach ((array) @dns_get_record($host, DNS_AAAA) as $rec)
						{
							$records[] = $rec['ipv6'] ?? '';
						}
					}

					foreach ($records as $rec)
					{
						if ($rec !== '' && @inet_pton($rec) === @inet_pton($ip))
						{
							$ok = true;
							break 2;
						}
					}
				}
			}
		}

		$this->cache->put($key, $ok ? 'ok' : 'ko', 86400);

		return $ok;
	}
}
