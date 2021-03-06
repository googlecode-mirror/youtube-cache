<?php

//
// Apache 2.2
//   ServerTokens Prod
//
// PHP 5.3
//   expose_php = Off
//
// Squid 2.7 or 3.1
//   # Do not allow Squid to cache the video files. PHP will do that.
//   cache deny to_localhost
//

openlog('cache.php', LOG_PID, LOG_USER);
set_time_limit(3600 * 24);
//setlocale(LC_TIME, "C");

$basedir = dirname(__FILE__);
include 'config.php';

class YouTubeCacher
{
	public $original_url = null;
	public $cache_filename = null;
	public $cache_header_size = null; // Size of HTTP response headers saved in the cache file.
	public $temp_cache_filename = null;
	public $log_filename = null;
	public $client_request_headers = array();
	public $server_reply_headers = array();
	public $server_fp = null;
	public $cache_fp = null;
	public $log_fp = null;

	//
	// $allowed_request_headers
	//  Browser originated request headers sent to YouTube.
	//
	// $custom_request_headers
	//  Custom headers sent to YouTube.
	//
	// $cached_headers
	//  List of YouTube response headers that are cached and sent to clients.
	//    The 'Server' header is overwritten by Apache and may be removed by Squid.
	//    Date related headers are generated dynamically by 'send_dynamic_headers_to_client()'.
	//
	public static $allowed_request_headers;
	public static $custom_request_headers;
	public static $cached_headers;

	//
	// Called manually after the class definition.
	//
	public static function static_constructor()
	{
		self::$allowed_request_headers = array('Host', 'Referer', 'Range', 'Cookie');
		self::$custom_request_headers = array('User-Agent' => 'YouTube Cache', 'X-Cache-Admin' => $GLOBALS['admin_email']);
		self::$cached_headers = array('Content-Type', 'Content-Length', 'Last-Modified', 'Server');
	}

	public function run()
	{
		$this->get_original_url();
		$this->produce_cache_filename();
		$this->open_log_file();
		$this->get_client_request_headers();
		if ($this->cache_filename) {
			foreach (array('original_url', 'cache_filename', 'temp_cache_filename') as $n) {
				$this->debug("$n = [{$this->$n}].\n");
			}
			$this->send_cached_file(); // If successful, ends here.
		}
		$this->connect_to_server();
		$this->get_server_reply_headers();
		$this->send_reply_headers_to_client();
		if ($this->cache_filename && $this->is_cachable()) {
			$this->open_cache_file();
			$this->write_reply_headers_to_cache_file();
		}
		$this->transfer_file();
		// 'close_cache_file()' is scheduled to run in 'open_cache_file()'.
	}

	public function get_original_url()
	{
		if (!isset($_GET['url']))
			fatal("Proxy URL rewriter error: url GET parameter not found.");

		$this->original_url = base64_decode($_GET['url'], TRUE);
		if (!is_string($this->original_url))
			fatal("Proxy URL rewriter error: url GET parameter is invalidly base64 encoded.");

		$this->warnx("URL [{$this->original_url}]");
	}

	public function produce_cache_filename()
	{
		//
		// Get the client IP address.
		//
		if (!isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$this->warnx("Proxy configuration error: X-Forwarded-For header not found");
			return;
		}
		$client_ip = trim($_SERVER['HTTP_X_FORWARDED_FOR']);
		if (preg_match('/^[0-9a-f:.]+$/', $client_ip) === 0) {
			$this->warnx("Proxy error: invalid X-Forwarded-For header value: [$client_ip]");
			return;
		}

		//
		// Parse the URL and make sure it belongs to a YouTube video.
		//
		$url = parse_url($this->original_url);
		if (!is_array($url) || !is_string($url['query'])) {
			$this->warnx("Invalid URL");
			return;
		}
		parse_str($url['query'], $p);
		if (!is_array($p)) {
			$this->warnx("Invalid query string: [{$url['query']}]");
			return;
		}
		foreach (array('sver', 'itag', 'id') as $n) {
			if (!is_string($p[$n]) || strlen($p[$n]) === 0) {
				$this->warnx("Query parameter [$n] not found or empty");
				return;
			}
		}

		if (isset($p['begin'])) {
			//
			// The user is not downloading the whole video, but seeking within it.
			// TODO How to deal with this?
			//      Maybe nginx's FLV module could help.
			//
			$this->warnx("Uncachable: begin is set: [{$p['begin']}]");
		}
		else if ($p['sver'] != '3') {
			//
			// Stream Version?
			//
			// All requests seem to have this field set to the number 3.
			// If this ever changes, we should look at the new requests to make
			// sure that they are still compatible with this script.
			//
			$this->warnx("Uncachable: sver is not 3: [{$p['sver']}]");
		}
		else {
			//
			// All values in $p are provided by the user.
			// Do not use them directly in 'fopen()'.
			//
			$this->cache_filename =
				cachedir($this) . '/' .
				'id=' . safe_filename($p['id']) .
				'.itag=' . safe_filename($p['itag']);
			$this->log_filename = "{$this->cache_filename}." .
				time() . ".{$client_ip}.log";
			$this->temp_cache_filename = "{$this->cache_filename}." .
				uniqid(mt_rand() . '_', TRUE) . ".{$client_ip}.tmp";
		}
	}

	public function send_dynamic_headers_to_client()
	{
		$now = time();
		$maxage = 365 * 24 * 3600;

		//
		// Allow the browser to cache the file.
		//
		foreach (array(
			'Date: ' . gmdate('D, d M Y H:i:s') . ' GMT',
			'Expires: ' . gmdate('D, d M Y H:i:s', $now + $maxage) . ' GMT',
			'Cache-Control: public, max-age=' . $maxage
		) as $h) {
			header($h);
			$this->debug("Custom header > client: [$h].\n");
		}
	}

	//
	// Send the cached file to the user's browser.
	// YouTube's servers are not used at all in this case.
	//
	// The first lines of the cache file are the static headers, separated 
	// from the file contents by an empty line.
	// Headers related to expiration time are generated dynamically.
	//
	public function send_cached_file()
	{
		if (!file_exists($this->cache_filename)) return FALSE;

		//
		// Open cache file.
		//
		if (($fp = fopen($this->cache_filename, 'rb')) === FALSE) {
			$this->warn("Cannot open cache file: [{$this->cache_filename}]");
			return FALSE;
		}
		$this->debug("Cache file opened for reading.\n");

		//
		// Send headers.
		//
		$hs = array();
		while (!feof($fp)) {
			if (($ln = fgets($fp)) === FALSE)
				$this->err("Cannot read cache file: [{$this->cache_filename}]");
			else if (($ln = rtrim($ln)) == '')
				break;
			else if (!preg_match('/^([^:]+): *(.*)$/', $ln, $mo))
				$this->errx("Invalid cached header in [{$this->cache_filename}]: [{$ln}]");
			else
				$hs[$mo[1]] = $mo[2];
		}
		if (isset($this->client_request_headers['Range'])) {
			$range = $this->client_request_headers['Range'];
			if (!preg_match('/bytes[=\s]+([0-9]+)/', $range, $mo))
				$this->warnx("Unsupported Range header value: [$range]");
			else {
				$firstbyte = $mo[1];
				$size = $hs['Content-Length'];
				$lastbyte = $size - $firstbyte - 1;
				$hs['Content-Range'] = "bytes $firstbyte-$lastbyte/$size";
				$hs['Content-Length'] -= $firstbyte;
				header('HTTP/1.0 206 Partial Content');
				if (fseek($fp, $firstbyte, SEEK_CUR))
					$this->err("Cannot seek to position $firstbyte: [{$this->cache_filename}]");
			}
		}
		foreach ($hs as $n => $v) {
			header("$n: $v");
			$this->debug("Cached header > client: [$n: $v].\n");
		}
		$this->send_dynamic_headers_to_client();

		//
		// Send content.
		//
 		// 'fpassthru($fp)' seems to attempt to mmap the file, and hits the PHP memory limit.
 		// As a workaround, use a 'feof / fread / echo' loop.
 		//
		while (!feof($fp)) {
			if (($data = fread($fp, 131072)) === FALSE)
				$this->err("Cannot read cache file: [{$this->cache_filename}]");
			else
				echo $data;
		}
		fclose($fp);
		$this->errx("Served URL from cache");
	}

	public function get_client_request_headers()
	{
		foreach ($_SERVER as $n => $v) {
			$this->debug("\$_SERVER[$n] => [$v].\n");
			if (strncmp($n, 'HTTP_', 5) === 0) {
				// HTTP_USER_AGENT > USER_AGENT > USER AGENT > user agent > User Agent > User-Agent
				$pn = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($n, 5)))));
				$this->client_request_headers[$pn] = $v;
				$this->debug("client_request_headers[$pn] => [$v].\n");
			}
		}
	}

	public function connect_to_server()
	{
		//
		// Prepare the request headers to be sent to YouTube.
		//
		$hs = array();
		foreach ($this->client_request_headers as $n => $v) {
			if (in_array($n, self::$allowed_request_headers)) {
				$hs []= "$n: $v";
				$this->debug("Request header > server: [$n: $v].\n");
			}
		}
		foreach (self::$custom_request_headers as $n => $v) {
			$hs []= "$n: $v";
			$this->debug("Custom header > server: [$n: $v].\n");
		}

		//
		// Connect to YouTube and send the HTTP request.
		//
		$c = stream_context_create(
			array(
				'socket' => array(
					'bindto' => bindto($this),
				),
				'http' => array(
					'method' => 'GET',
					'header' => implode("\r\n", $hs),
					'max_redirects' => 100,
				),
			)
		);
		if (($this->server_fp = fopen($this->original_url, 'rb', FALSE, $c)) === FALSE)
			$this->err("Cannot open URL");
	}

	public function get_server_reply_headers()
	{
		//
		// If a redirection happens, two sets of headers will be present.
		// Keep the last one only.
		//
		$m = stream_get_meta_data($this->server_fp);
		foreach ($m['wrapper_data'] as $h) {
			if (preg_match('/^([^:]+): *(.+)[ \r\n]*$/', $h, $mo) !== 0) {
				// USER-AGENT > USER AGENT > user agent > User Agent > User-Agent
				$pn = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $mo[1]))));
				$this->server_reply_headers[$pn] = $mo[2];
				$this->debug("server_reply_headers[$pn] = [{$mo[2]}].\n");
			}
			else if (!strncasecmp($h, 'HTTP/', 5)) {
				$this->server_reply_headers = array();
				$this->debug("Server reply [$h].\n");
			}
			else {
				$this->errx("Program error: Unexpected value in stream_get_meta_data[wrapper_data]: [$h]");
			}
		}
	}

	public function send_reply_headers_to_client()
	{
		$hs = $this->server_reply_headers;
		if (isset($hs['Content-Range'])) {
			foreach (array(
				'HTTP/1.0 206 Partial Content',
				'Content-Range: ' . $hs['Content-Range']
			) as $h) {
				header($h);
				$this->debug("Range header > client: [$h].\n");
			}
		}

		foreach ($this->server_reply_headers as $n => $v) {
			if (in_array($n, self::$cached_headers)) {
				header("$n: $v");
				$this->debug("Reply header > client [$n: $v].\n");
			}
		}
		$this->send_dynamic_headers_to_client();
	}

	//
	// Only video files should be cached.
	// Do not cache files of unknown type.
	//
	// The Content-Length header must be present or there will be no way
	// of knowing whether the download completed successfully.
	//
	public function is_cachable()
	{
		$h = $this->server_reply_headers;

		if (isset($h['Content-Range'])) {
			$this->warnx("Uncachable: Content-Range header is present.");
			return FALSE;
		}

		if (!isset($h['Content-Type'])) {
			$this->warnx("No Content-Type header.");
			return FALSE;
		}
		else if (strncasecmp($h['Content-Type'], 'video/', 6)) {
			$this->warnx("Content-Type is not video: [{$h['Content-Type']}].");
			return FALSE;
		}

		if (!isset($h['Content-Length'])) {
			$this->warnx("No Content-Length header.");
			return FALSE;
		}

		return TRUE;
	}

	public function open_cache_file()
	{
		if (($fp = fopen($this->temp_cache_filename, 'xb')) === FALSE)
			$this->warn("Cannot open cache file: [{$this->temp_cache_filename}]");
		else {
			register_shutdown_function(array($this, 'close_cache_file'));
			$this->cache_fp = $fp;
			$this->debug("Cache file opened for writing.\n");
		}
	}

	public function open_log_file()
	{
		if (!$this->log_filename) return;

		if (($fp = fopen($this->log_filename, 'xt')) === FALSE)
			$this->warn("Cannot open log file: [{$this->log_filename}]");
		else
			$this->log_fp = $fp;
	}

	public function debug($msg)
	{
		if (!$this->log_fp) return;

		$when = strftime("%b %d %H:%M:%S");
		if (fwrite($this->log_fp, "$when $msg") === FALSE) {
			$this->warn("Cannot write log file: [{$this->log_filename}]");
			fclose($this->log_fp);
			$this->log_fp = null;
		}
	}

	public function write_reply_headers_to_cache_file()
	{
		if (!$this->cache_fp) return;

		$hs = array();
		$h = $this->server_reply_headers;
		foreach (self::$cached_headers as $n) {
			if (isset($h[$n])) {
				$hs []= "$n: {$h[$n]}";
				$this->debug("Reply header > cache file [$n: {$h[$n]}].\n");
			}
		}
		$hs []= "\n"; // End with empty line.

		if (fwrite($this->cache_fp, implode("\n", $hs)) === FALSE) {
			$this->warn("Cannot write cache file: [{$this->cache_filename}]");
			$this->stop_caching();
			return;
		}

		$this->cache_header_size = ftell($this->cache_fp);
	}

	public function transfer_file()
	{
		$this->debug("Beginning to transfer file content from the Internet.\n");

		while (!feof($this->server_fp)) {
			if (($data = fread($this->server_fp, 131072)) === FALSE)
				$this->err("Cannot read URL");

			// To client.
			echo $data;

			// To cache file.
			if ($this->cache_fp) {
				if (fwrite($this->cache_fp, $data) === FALSE) {
					$this->warn("Cannot write cache file: [{$this->cache_filename}]");
					$this->stop_caching();
				}
			}
		}

		$this->debug("File content fully transferred.\n");
	}

	//
	// Close 'cache_fp' if still opened.
	// Delete the temporary cache file.
	//
	public function stop_caching()
	{
		if ($this->cache_fp) {
			fclose($this->cache_fp);
			$this->cache_fp = null;
		}
	
		if (file_exists($this->temp_cache_filename)) {
			if (unlink($this->temp_cache_filename) === FALSE)
				$this->warn("Cannot delete temporary cache file: [{$this->temp_cache_filename}]");
			else
				$this->debug("Temporary cache file deleted.\n");
		}
	
		$this->cache_filename = $this->temp_cache_filename = null;
	}

	//
	// Make sure the temporary cache file's content is safely stored on disk.
	// Rename the temporary file into the final cache file.
	//
	public function close_cache_file()
	{
		if (!$this->cache_fp) {
			$this->warnx("URL not cached");
			return;
		}

		$cl = $this->server_reply_headers['Content-Length'];
		$sz = ftell($this->cache_fp) - $this->cache_header_size;
		if ($sz != $cl) {
			$this->warnx("Not fully downloaded [$sz/$cl]");
		}
		else if (fflush($this->cache_fp) === FALSE || fclose($this->cache_fp) === FALSE) {
			$this->warn("Cannot write cache file: [{$this->cache_filename}]");
		}
		else if (rename($this->temp_cache_filename, $this->cache_filename) === FALSE) {
			$this->warn("Cannot rename temporary cache file: [{$this->temp_cache_filename}] to [{$this->cache_filename}]");
		}
		else {
			$this->cache_fp = null;
			$this->warnx("Cached URL to disk");
		}

		$this->stop_caching();
	}

	public function err($msg) { $this->warn($msg); exit; }
	public function errx($msg) { $this->warnx($msg); exit; }
	public function warn($msg) { $e = error_get_last(); $this->warnx("{$msg}: {$e['message']}"); }
	public function warnx($msg) { slog("{$msg}"); }
}
YouTubeCacher::static_constructor();

function char_to_hex($ch)
{
	return sprintf('%2X', ord($ch));
}

function safe_filename($fn)
{
	return preg_replace_callback('/[^a-zA-Z0-9_-]/', 'char_to_hex', $fn);
}

function fatal($msg)
{
	slog($msg);
	exit();
}

function slog($msg)
{
	syslog(LOG_ERR, $msg);
}

$cr = new YouTubeCacher();
$cr->run();
