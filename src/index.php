<?php

declare(strict_types=1);

define('SUPABASE_API_URL', getenv('SUPABASE_API_URL'));
define('SUPABASE_API_KEY', getenv('SUPABASE_API_KEY'));
define('SUPABASE_TABLE_NAME', getenv('SUPABASE_TABLE_NAME'));

define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN'));
define('TELEGRAM_CHAT_ID', (int)getenv('TELEGRAM_CHAT_ID'));

define('TELEGRAM_DEBUG_BOT_TOKEN', getenv('TELEGRAM_DEBUG_BOT_TOKEN'));
define('TELEGRAM_DEBUG_CHAT_ID', (int)getenv('TELEGRAM_DEBUG_CHAT_ID'));

const TELEGRAM_BOT_API_URL = 'https://api.telegram.org/bot';
const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;
const TELEGRAM_ALERT_TEMPLATE = '[{link}]: {title}';

const EOL = '\r\n';

const CONFIG = [
	'ru' => [
		'urls' => [
			'https://www.vedomosti.ru/rss/news.xml',
			'https://www.kommersant.ru/RSS/corp.xml',
			'https://ria.ru/export/rss2/archive/index.xml',
			'https://rssexport.rbc.ru/rbcnews/news/30/full.rss',
		],
		'keywords' => [
			'санкци',
			'огранич',
			'ограничения',
			'огран',
		],
		'excluded_keywords' => [
			'аэропорт',
			'ковер',
			'МУС',
			'нефть',
			'футбол'
		],
	],
	'en' => [
		'urls' => [
			'https://www.consilium.europa.eu/en/rss/pressreleases.ashx',
			'https://www.theguardian.com/uk/rss',
			'https://ofac.treasury.gov/system/files/126/ofac.xml',
		],
		'keywords' => [
			'Russia',
			"Russia's",
			'Russian',
		],
		'excluded_keywords' => [],
	]
];

foreach (CONFIG as $lang => $params) {
	extract($params);

	foreach ($urls as $url) {
		try {
			$updates = get_updates($url, $keywords, $excluded_keywords);

			if (!$updates) {
				continue;
			}

			process_send_updates($updates);
		} catch (Throwable $e) {
			telegram_send_message_request($e->getMessage(), debug: true);
			continue;
		}
	}
}

/** @throws Exception */
function get_updates(string $url, array $keywords, array $excluded_keywords): ?array
{
	$response = @file_get_contents($url);

	if (!$response) {
		throw new Exception("[$url]: xml request error");
	}

	if (str_contains($response, 'a10:updated')) {
		$response = str_replace('a10:updated', 'pubDate', $response);
	}

	$updates = simplexml_load_string($response, options: LIBXML_NOCDATA)->xpath('/rss/channel/item');

	if ($updates === false) {
		throw new Exception("[$url]: xml parse error");
	}

	$updates = json_encode($updates, JSON_UNESCAPED_UNICODE);
	$updates = json_decode($updates, true);

	usort($updates, fn(array $a, array $b) => new_date($b['pubDate']) <=> new_date($a['pubDate']));

	$cache = get_cache($url);

	if ($cache) {
		$i = array_search($cache['link'], array_column($updates, 'link'), true);

		if ($i !== false) {
			array_splice($updates, $i);
		}
	} else {
		$now = new_date();

		$updates = array_filter(
			$updates,
			fn(array $item) => ($now->getTimestamp() - new_date($item['pubDate'])->getTimestamp()) < 24 * 60 * 60,
		);
	}

	if (count($excluded_keywords) > 0) {
		$updates = array_filter(
			$updates,
			fn(array $item) => !str_contains_array($item['title'], $excluded_keywords),
		);
	}

	if (count($keywords) > 0) {
		$updates = array_filter(
			$updates,
			fn(array $item) => str_contains_array($item['title'], $keywords)
		);
	}

	$updates = array_values($updates);

	if (count($updates) === 0) {
		return null;
	}

	update_or_create_cache($url, $updates[0]);

	return $updates;
}

function process_send_updates(array $updates): void
{
	$messages = array_map(
		fn(array $item) => str_replace(
			['{link}', '{title}'],
			[$item['link'], $item['title']],
			TELEGRAM_ALERT_TEMPLATE
		),
		$updates
	);

	$message = implode(EOL, $messages);

	if (strlen($message) > TELEGRAM_MAX_MESSAGE_LENGTH) {
		foreach ($messages as $message) {
			telegram_send_message_request($message);
			telegram_send_message_request($message, debug: true);

		}

		return;
	}

	telegram_send_message_request($message);
	telegram_send_message_request($message, debug: true);
}

function get_cache(string $url): ?array
{
	$cache = supabase_request('GET', params: [
		'select' => 'cache',
		'url' => "eq.$url",
		'limit' => 1
	]);

	return $cache[0]['cache'] ?? null;
}

function update_or_create_cache(string $url, array $cache): void
{
	$method = get_cache($url) ? 'PATCH' : 'POST';

	switch ($method) {
		case 'PATCH':
			supabase_request(
				$method,
				params: [
					'url' => "eq.$url",
				],
				body: compact('url', 'cache')
			);

			break;
		case 'POST':
			supabase_request($method, body: compact('url', 'cache'));

			break;
		default:
	}
}

function telegram_send_message_request(string $message, bool $debug = false): void
{
	request(
		'POST',
		sprintf(
			"%s%s/sendMessage",
			TELEGRAM_BOT_API_URL,
			$debug ? TELEGRAM_DEBUG_BOT_TOKEN : TELEGRAM_BOT_TOKEN,
		),
		params: [
			'chat_id' => $debug ? TELEGRAM_DEBUG_CHAT_ID : TELEGRAM_CHAT_ID,
			'text' => $message
		]);
}

function supabase_request(string $method, array $params = [], array $body = []): ?array
{
	return request(
		$method,
		sprintf("%s/%s", SUPABASE_API_URL, SUPABASE_TABLE_NAME),
		headers: [
			'apikey' => SUPABASE_API_KEY,
		],
		params: $params,
		body: $body
	);
}

function request(
	string $method,
	string $url,
	array  $headers = [],
	array  $params = [],
	array  $body = []
): ?array
{
	sleep(1);

	$headers['Content-Type'] = 'application/json';
	$headers['Accept'] = 'application/json';

	$context = stream_context_create([
		'http' => [
			'method' => $method,
			'header' => implode(
				EOL,
				array_map(
					fn(string $key, string $value) => "$key: $value",
					array_keys($headers),
					array_values($headers)
				)
			),
			'content' => empty($body) ? null : json_encode($body),
			'ignore_errors' => true,
			'timeout' => 10
		],
	]);

	$response = @file_get_contents(sprintf("%s?%s", $url, http_build_query($params)), context: $context);

	preg_match('{HTTP\S*\s(\d{3})}', $http_response_header[0] ?? '', $match);

	$errorCode = (int)($match[1] ?? PHP_INT_MAX);

	if ($errorCode >= 400) {
		throw new Exception(sprintf("[%s]: context %s response %s", $errorCode, json_encode(func_get_args()), $response));
	}

	return $response ? json_decode($response, true) : null;
}

function str_contains_array(string $haystack, array $needles): bool
{
	$haystack = mb_strtolower($haystack);

	foreach ($needles as $needle) {
		$needle = mb_strtolower($needle);

		if (str_contains($haystack, $needle)) {
			return true;
		}
	}

	return false;
}

function new_date(?string $value = null, ?string $timezone = null): DateTime
{
	$date = new DateTime($value ?: 'now');
	$date->setTimezone(new DateTimeZone($timezone ?: 'UTC'));

	return $date;
}