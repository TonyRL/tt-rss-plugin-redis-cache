<?php
class Redis_Cache extends Plugin implements IHandler {

	const REDIS_URL = 'REDIS_URL';
	const REDIS_HOST = 'REDIS_HOST';
	const REDIS_PORT = 'REDIS_PORT';
	const REDIS_DB = 'REDIS_DB';
	const REDIS_PASSWORD = 'REDIS_PASSWORD';

	const REDIS_COUNTERS_TTL = 'REDIS_COUNTERS_TTL';
	const REDIS_TRANSLATIONS_TTL = 'REDIS_TRANSLATIONS_TTL';
	const REDIS_INIT_PARAMS_TTL = 'REDIS_INIT_PARAMS_TTL';
	const REDIS_FEED_TREE_TTL = 'REDIS_FEED_TREE_TTL';
	const REDIS_VIEW_TTL = 'REDIS_VIEW_TTL';
	const REDIS_RUNTIME_INFO_TTL = 'REDIS_RUNTIME_INFO_TTL';
	const REDIS_LABELS_TTL = 'REDIS_LABELS_TTL';
	const REDIS_FEED_ICONS_TTL = 'REDIS_FEED_ICONS_TTL';

	/** maps handler op -> original class name for catchall forwarding */
	const HANDLER_MAP = [
		'rpc' => 'RPC',
		'pref_feeds' => 'Pref_Feeds',
		'pref_prefs' => 'Pref_Prefs',
		'feeds' => 'Feeds',
		'article' => 'Article',
	];

	private ?\Redis $redis = null;

	/** @var array<int|string, mixed> */
	private array $handler_args = [];

	/** which op we are currently handling, set by backend.php dispatch context */
	private string $current_op = '';

	function about(): array {
		return [1.0, 'Redis cache for frequently accessed data', 'TonyRL', true, 'https://github.com/TonyRL/ttrss-redis-cache'];
	}

	function api_version(): int {
		return 2;
	}

	/**
	 * PluginHost passes itself on plugin load: new $class($this).
	 * backend.php calls __construct($_REQUEST) on handler overrides.
	 * Accept mixed to handle both cases.
	 */
	function __construct(mixed $args = null) {
		$this->pdo = Db::pdo();
		if (is_array($args)) {
			$this->handler_args = $args;
			$this->current_op = strtolower($_REQUEST['op'] ?? '');
		}
	}

	// -- IHandler implementation --

	function before(string $method): bool {
		return !empty($_SESSION['uid']);
	}

	function after(): bool {
		return true;
	}

	function csrf_ignore($method): bool {
		if ($method === 'flushusercache') {
			return true;
		}
		$class = self::HANDLER_MAP[$this->current_op] ?? null;
		if ($class && class_exists($class)) {
			$orig = (new ReflectionClass($class))->newInstanceWithoutConstructor();
			return $orig->csrf_ignore($method);
		}
		return false;
	}

	// -- Plugin lifecycle --

	function init($host): void {
		if (!class_exists('Redis')) {
			user_error('redis_cache: php-redis extension is not installed', E_USER_WARNING);
			return;
		}

		Config::add(self::REDIS_URL, '', Config::T_STRING);
		Config::add(self::REDIS_HOST, 'localhost', Config::T_STRING);
		Config::add(self::REDIS_PORT, '6379', Config::T_STRING);
		Config::add(self::REDIS_DB, '0', Config::T_STRING);
		Config::add(self::REDIS_PASSWORD, '', Config::T_STRING);

		Config::add(self::REDIS_COUNTERS_TTL, '30', Config::T_STRING);
		Config::add(self::REDIS_TRANSLATIONS_TTL, '86400', Config::T_STRING);
		Config::add(self::REDIS_INIT_PARAMS_TTL, '300', Config::T_STRING);
		Config::add(self::REDIS_FEED_TREE_TTL, '300', Config::T_STRING);
		Config::add(self::REDIS_VIEW_TTL, '60', Config::T_STRING);
		Config::add(self::REDIS_RUNTIME_INFO_TTL, '10', Config::T_STRING);
		Config::add(self::REDIS_LABELS_TTL, '300', Config::T_STRING);
		Config::add(self::REDIS_FEED_ICONS_TTL, '86400', Config::T_STRING);

		if (!$this->connect()) {
			return;
		}

		// register wildcard overrides — catchall() forwards unhandled methods
		$host->add_handler('rpc', '*', $this);
		$host->add_handler('pref_feeds', '*', $this);
		$host->add_handler('pref_prefs', '*', $this);
		$host->add_handler('feeds', '*', $this);
		$host->add_handler('article', '*', $this);

		// invalidation hooks
		$host->add_hook($host::HOOK_ARTICLES_MARK_TOGGLED, $this);
		$host->add_hook($host::HOOK_ARTICLES_PUBLISH_TOGGLED, $this);

		// preference tab for status/controls
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	/**
	 * Parse connection parameters from REDIS_URL or individual config keys.
	 * REDIS_URL takes priority when set.
	 * @return array{host: string, port: int, password: string, db: int}
	 */
	private function get_connection_params(): array {
		$url = Config::get(self::REDIS_URL);

		if ($url) {
			$parsed = parse_url($url);

			return [
				'host' => $parsed['host'] ?? 'localhost',
				'port' => $parsed['port'] ?? 6379,
				'password' => isset($parsed['pass']) ? urldecode($parsed['pass']) : (isset($parsed['user']) ? urldecode($parsed['user']) : ''),
				'db' => isset($parsed['path']) ? max(0, (int) ltrim($parsed['path'], '/')) : 0,
			];
		}

		return [
			'host' => Config::get(self::REDIS_HOST),
			'port' => (int) Config::get(self::REDIS_PORT),
			'password' => Config::get(self::REDIS_PASSWORD),
			'db' => (int) Config::get(self::REDIS_DB),
		];
	}

	private function connect(): bool {
		try {
			$this->redis = new \Redis();

			$params = $this->get_connection_params();

			if (!$this->redis->connect($params['host'], $params['port'], 2.0)) {
				user_error('redis_cache: connection to ' . $params['host'] . ':' . $params['port'] . ' failed', E_USER_WARNING);
				$this->redis = null;
				return false;
			}

			if ($params['password']) {
				$this->redis->auth($params['password']);
			}

			if ($params['db'] > 0) {
				$this->redis->select($params['db']);
			}

			return true;
		} catch (\Throwable $e) {
			user_error('redis_cache: ' . $e->getMessage(), E_USER_WARNING);
			$this->redis = null;
			return false;
		}
	}

	// -- Fallback for non-cached methods --

	function catchall(string $method): void {
		$class = self::HANDLER_MAP[$this->current_op] ?? null;

		if (!$class || !class_exists($class)) {
			header("Content-Type: application/json");
			print Errors::to_json(Errors::E_UNKNOWN_METHOD, ["info" => "redis_cache: unknown op {$this->current_op}"]);
			return;
		}

		$handler = new $class($this->handler_args);

		if (method_exists($handler, $method)) {
			$ref = new ReflectionMethod($handler, $method);
			if ($ref->getNumberOfRequiredParameters() == 0) {
				$handler->$method();
			}
		} else if (method_exists($handler, 'catchall')) {
			$handler->catchall($method);
		}
	}

	// -- Cache helpers --

	private function ttl(string $config_key): int {
		return (int) Config::get($config_key);
	}

	private function invalidate_pref_dependent_caches(bool $include_translations = false): void {
		$keys = ['init_params', 'runtime_info', 'view:*', 'feedtree:*'];

		if ($include_translations) {
			$keys[] = 'translations';
		}

		$this->invalidate(...$keys);
	}

	private function delegate_and_invalidate(string $class, string $method, string ...$types): void {
		$handler = new $class($this->handler_args);
		$handler->$method();
		$this->invalidate(...$types);
	}

	private function cache_key(string $type): string {
		$uid = $_SESSION['uid'] ?? 0;
		return "ttrss:{$uid}:{$type}";
	}

	/**
	 * @return mixed|null decoded value or null on miss
	 */
	private function get_cached(string $key): mixed {
		if (!$this->redis) return null;

		try {
			$val = $this->redis->get($key);
			$uid = $_SESSION['uid'] ?? 0;
			if ($val === false) {
				$this->redis->incr("ttrss:{$uid}:_stats:misses");
				return null;
			}
			$this->redis->incr("ttrss:{$uid}:_stats:hits");
			return json_decode($val, true);
		} catch (\Throwable) {
			return null;
		}
	}

	private function set_cached(string $key, mixed $value, int $ttl): void {
		if (!$this->redis) return;

		try {
			$this->redis->setex($key, $ttl, json_encode($value));
		} catch (\Throwable) {
			// silent
		}
	}

	private function invalidate(string ...$types): void {
		if (!$this->redis) return;

		$uid = $_SESSION['uid'] ?? 0;

		try {
			foreach ($types as $type) {
				if (str_contains($type, '*')) {
					$keys = $this->redis->keys("ttrss:{$uid}:{$type}");
					if ($keys) {
						$this->redis->del($keys);
					}
				} else {
					$this->redis->del("ttrss:{$uid}:{$type}");
				}
			}
		} catch (\Throwable) {
			// silent
		}
	}

	// -- Pre-warming / batch caches --

	/**
	 * Batch pre-warm empty label_cache entries in the DB with a single UPDATE.
	 * Converts N per-article label lookups into one query on view() cache miss.
	 */
	private function warmup_label_cache(): void {
		$uid = $_SESSION['uid'] ?? 0;
		$flag_key = $this->cache_key('label_cache_warm');

		if ($this->get_cached($flag_key) !== null) return;

		$pdo = Db::pdo();
		$label_offset = LABEL_BASE_INDEX - 1;
		$no_labels = json_encode(['no-labels' => 1]);

		$sth = $pdo->prepare("UPDATE ttrss_user_entries ue SET label_cache = COALESCE(
			(SELECT json_agg(json_build_array(
				:label_offset - l.id, l.caption, l.fg_color, l.bg_color
			) ORDER BY l.caption)::text
			FROM ttrss_user_labels2 ul
			JOIN ttrss_labels2 l ON l.id = ul.label_id
			WHERE ul.article_id = ue.ref_id),
			:no_labels
		)
		WHERE ue.owner_uid = :uid AND (ue.label_cache = '' OR ue.label_cache IS NULL)
		AND EXISTS (SELECT 1 FROM ttrss_user_labels2 WHERE article_id = ue.ref_id)");

		$sth->execute([
			':label_offset' => $label_offset,
			':no_labels' => $no_labels,
			':uid' => $uid,
		]);

		$this->set_cached($flag_key, true, $this->ttl(self::REDIS_LABELS_TTL));
	}

	/**
	 * Cache the complete category hierarchy for the current user.
	 * @return array<string, int> mapping cat_id => parent_cat (0 for root)
	 */
	private function get_cached_cat_hierarchy(): array {
		$key = $this->cache_key('cat_hierarchy');
		$cached = $this->get_cached($key);

		if ($cached !== null) return $cached;

		$uid = $_SESSION['uid'] ?? 0;
		$cats = ORM::for_table('ttrss_feed_categories')
			->select_many('id', 'parent_cat')
			->where('owner_uid', $uid)
			->find_many();

		$hierarchy = [];
		foreach ($cats as $cat) {
			$hierarchy[(string) $cat->id] = (int) ($cat->parent_cat ?? 0);
		}

		$this->set_cached($key, $hierarchy, $this->ttl(self::REDIS_FEED_TREE_TTL));

		return $hierarchy;
	}

	/**
	 * Get child category IDs from cached hierarchy (no recursive SQL).
	 * @return array<int, int>
	 */
	private function get_cached_child_cats(int $cat_id): array {
		$hierarchy = $this->get_cached_cat_hierarchy();
		return $this->_resolve_children($cat_id, $hierarchy);
	}

	/**
	 * Recursively resolve child categories from hierarchy map.
	 * @param array<string, int> $hierarchy
	 * @return array<int, int>
	 */
	private function _resolve_children(int $cat_id, array $hierarchy): array {
		$children = [];
		foreach ($hierarchy as $id => $parent) {
			if ($parent === $cat_id) {
				$id = (int) $id;
				$children[] = $id;
				array_push($children, ...$this->_resolve_children($id, $hierarchy));
			}
		}
		return $children;
	}

	/**
	 * Cache feed icon existence for all feeds of the current user.
	 * Eliminates per-feed file_exists() calls on cache rebuild.
	 * @return array<string, bool> mapping feed_id => has_icon
	 */
	private function get_cached_feed_icons(): array {
		$key = $this->cache_key('feed_icons');
		$cached = $this->get_cached($key);

		if ($cached !== null) return $cached;

		$uid = $_SESSION['uid'] ?? 0;
		$feeds = ORM::for_table('ttrss_feeds')
			->select('id')
			->where('owner_uid', $uid)
			->find_many();

		$icons = [];
		foreach ($feeds as $feed) {
			$icons[(string) $feed->id] = Feeds::_has_icon($feed->id);
		}

		$this->set_cached($key, $icons, $this->ttl(self::REDIS_FEED_ICONS_TTL));

		return $icons;
	}

	// -- Cached RPC methods --

	/**
	 * Cached version of RPC::getAllCounters().
	 */
	function getallcounters(): void {
		$seq = (int) ($_REQUEST['seq'] ?? 0);

		$feed_id_count = (int) ($_REQUEST['feed_id_count'] ?? -1);
		$label_id_count = (int) ($_REQUEST['label_id_count'] ?? -1);

		$feed_ids = $feed_id_count == -1 ? null : (Handler::_param_to_int_array($_REQUEST['feed_ids'] ?? '') ?? []);
		$label_ids = $label_id_count == -1 ? null : (Handler::_param_to_int_array($_REQUEST['label_ids'] ?? '') ?? []);

		$conditional = is_array($feed_ids)
			&& !Prefs::get(Prefs::DISABLE_CONDITIONAL_COUNTERS, $_SESSION['uid'], $_SESSION['profile'] ?? null);

		if ($conditional || $feed_ids !== null || $label_ids !== null) {
			$handler = new RPC($this->handler_args);
			$handler->getAllCounters();
			return;
		}

		$key = $this->cache_key('counters');

		$counters = $this->get_cached($key);

		if ($counters === null) {
			$counters = Counters::get_all();
			$this->set_cached($key, $counters, $this->ttl(self::REDIS_COUNTERS_TTL));
		}

		print json_encode([
			'counters' => $counters,
			'seq' => $seq,
		]);
	}

	/**
	 * Cached version of RPC::sanityCheck().
	 * Caches translations (24h), init params (5min), and runtime info (10s).
	 */
	function sanitycheck(): void {
		$_SESSION['hasSandbox'] = Handler::_param_to_bool($_REQUEST['hasSandbox'] ?? false);
		$_SESSION['clientTzOffset'] = clean($_REQUEST['clientTzOffset'] ?? '');

		$client_location = $_REQUEST['clientLocation'] ?? '';

		$error = Errors::E_SUCCESS;
		$error_params = [];

		$client_scheme = parse_url($client_location, PHP_URL_SCHEME);
		$server_scheme = parse_url(Config::get_self_url(), PHP_URL_SCHEME);

		if (Config::is_migration_needed()) {
			$error = Errors::E_SCHEMA_MISMATCH;
		} else if ($client_scheme != $server_scheme) {
			$error = Errors::E_URL_SCHEME_MISMATCH;
			$error_params['client_scheme'] = $client_scheme;
			$error_params['server_scheme'] = $server_scheme;
			$error_params['self_url_path'] = Config::get_self_url();
		}

		if ($error != Errors::E_SUCCESS) {
			print Errors::to_json($error, $error_params);
			return;
		}

		$rpc = new RPC($this->handler_args);

		$translations_key = $this->cache_key('translations');
		$translations = $this->get_cached($translations_key);

		if ($translations === null) {
			$ref = new ReflectionMethod($rpc, '_translations_as_array');
			$translations = $ref->invoke($rpc);
			$this->set_cached($translations_key, $translations, $this->ttl(self::REDIS_TRANSLATIONS_TTL));
		}

		$init_params_key = $this->cache_key('init_params');
		$init_params = $this->get_cached($init_params_key);

		if ($init_params === null) {
			$ref = new ReflectionMethod($rpc, '_make_init_params');
			$init_params = $ref->invoke($rpc);
			$this->set_cached($init_params_key, $init_params, $this->ttl(self::REDIS_INIT_PARAMS_TTL));
		}

		$runtime_info = $this->get_cached_runtime_info();

		print json_encode([
			'init-params' => $init_params,
			'runtime-info' => $runtime_info,
			'translations' => $translations,
		]);
	}

	/**
	 * Intercept RPC::catchupFeed to invalidate view/counter caches after catchup.
	 */
	function catchupfeed(): void {
		$handler = new RPC($this->handler_args);

		ob_start();
		$handler->catchupFeed();
		$output = ob_get_clean();

		$this->invalidate('counters', 'view:*', 'runtime_info');

		print $output;
	}

	// -- Cached Feeds methods --

	/**
	 * Cached version of Feeds::view().
	 * Caches first-page, non-search, non-ForceUpdate headline responses.
	 * Side effects (prefs, last_viewed) are preserved on cache hits.
	 */
	function view(): void {
		$feed = $_REQUEST['feed'] ?? '';
		$method = $_REQUEST['m'] ?? '';
		$view_mode = $_REQUEST['view_mode'] ?? '';
		$cat_view = Handler::_param_to_bool($_REQUEST['cat'] ?? false);
		$offset = (int) ($_REQUEST['skip'] ?? 0);
		$order_by = $_REQUEST['order_by'] ?? '';
		$search = $_REQUEST['query'] ?? '';
		$next_unread_feed = $_REQUEST['nuf'] ?? 0;

		// only cache plain first-page view requests without side effects
		if ($search || $offset > 0 || !empty($_REQUEST['debug']) || ($method && $method !== 'undefined')) {
			$handler = new Feeds($this->handler_args);
			$handler->view();
			return;
		}

		$cat_flag = $cat_view ? '1' : '0';
		$key = $this->cache_key("view:{$feed}:{$cat_flag}:{$view_mode}:{$order_by}:{$next_unread_feed}");
		$cached = $this->get_cached($key);

		if ($cached !== null) {
			$this->_view_side_effects($feed, $view_mode, $order_by, $cat_view);
			$cached['runtime-info'] = $this->get_cached_runtime_info();
			print json_encode($cached);
			return;
		}

		// pre-warm empty label caches before building headlines
		$this->warmup_label_cache();

		ob_start();
		$handler = new Feeds($this->handler_args);
		$handler->view();
		$output = ob_get_clean();

		$data = json_decode($output, true);
		if ($data !== null) {
			$this->set_cached($key, $data, $this->ttl(self::REDIS_VIEW_TTL));
		}

		print $output;
	}

	/**
	 * Run the lightweight side effects of Feeds::view() on cache hits.
	 */
	private function _view_side_effects(mixed $feed, string $view_mode, string $order_by, bool $cat_view): void {
		$profile = $_SESSION['profile'] ?? null;

		Prefs::set(Prefs::_DEFAULT_VIEW_MODE, $view_mode, $_SESSION['uid'], $profile);
		Prefs::set(Prefs::_DEFAULT_VIEW_ORDER_BY, $order_by, $_SESSION['uid'], $profile);

		if (time() - ($_SESSION['last_login_update'] ?? 0) > 3600) {
			$user = ORM::for_table('ttrss_users')->find_one($_SESSION['uid']);
			$user->last_login = Db::NOW();
			$user->save();
			$_SESSION['last_login_update'] = time();
		}

		if (!$cat_view && is_numeric($feed) && $feed > 0) {
			$pdo = Db::pdo();
			$sth = $pdo->prepare("UPDATE ttrss_feeds SET last_viewed = NOW()
				WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);
		}
	}

	// -- Cached Pref_Feeds methods --

	/**
	 * Cached version of Pref_Feeds::getfeedtree().
	 */
	function getfeedtree(): void {
		$mode = clean($_REQUEST['mode'] ?? '');
		$numeric_mode = (int) $mode;

		if ($numeric_mode != 2) {
			$search = array_key_exists('search', $_REQUEST)
				? clean($_REQUEST['search'])
				: ($_SESSION['prefs_feed_search'] ?? '');
		} else {
			$search = clean($_REQUEST['search'] ?? '');
		}

		$key = $this->cache_key('feedtree:' . $mode . ':' . sha1($search));
		$cached = $this->get_cached($key);

		if ($cached !== null) {
			print json_encode($cached);
			return;
		}

		ob_start();
		$handler = new Pref_Feeds($this->handler_args);
		$handler->getfeedtree();
		$output = ob_get_clean();

		$data = json_decode($output, true);
		if ($data !== null) {
			$this->set_cached($key, $data, $this->ttl(self::REDIS_FEED_TREE_TTL));
		}

		print $output;
	}

	// -- Feed tree invalidation on structure changes --

	private function delegate_with_tree_invalidation(string $class, string $method): void {
		$this->delegate_and_invalidate($class, $method, 'feedtree:*', 'counters', 'runtime_info', 'cat_hierarchy', 'feed_icons');
	}

	function remove(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'remove');
	}

	function removecat(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'removeCat');
	}

	function addcat(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'addCat');
	}

	function renamecat(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'renameCat');
	}

	function savefeedorder(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'savefeedorder');
	}

	function editsave(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'editSave');
	}

	function batcheditsave(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'batchEditSave');
	}

	function importopml(): void {
		$this->delegate_with_tree_invalidation('Pref_Feeds', 'importOpml');
	}

	function subscribetofeed(): void {
		$this->delegate_with_tree_invalidation('Feeds', 'subscribeToFeed');
	}

	function togglepref(): void {
		$handler = new RPC($this->handler_args);
		$handler->togglepref();
		$this->invalidate_pref_dependent_caches();
	}

	function setpref(): void {
		$handler = new RPC($this->handler_args);
		$handler->setpref();
		$this->invalidate_pref_dependent_caches(clean($_REQUEST['key'] ?? '') === Prefs::USER_LANGUAGE);
	}

	function setwidescreen(): void {
		$handler = new RPC($this->handler_args);
		$handler->setWidescreen();
		$this->invalidate_pref_dependent_caches();
	}

	function saveconfig(): void {
		$handler = new Pref_Prefs($this->handler_args);
		$handler->saveconfig();
		$this->invalidate_pref_dependent_caches(true);
	}

	// -- Label assignment invalidation --

	function assigntolabel(): void {
		$handler = new Article($this->handler_args);
		$handler->assigntolabel();
		$this->invalidate('label_cache_warm', 'labels', 'counters', 'view:*', 'runtime_info');
	}

	function removefromlabel(): void {
		$handler = new Article($this->handler_args);
		$handler->removefromlabel();
		$this->invalidate('label_cache_warm', 'labels', 'counters', 'view:*', 'runtime_info');
	}

	// -- Shared cached data --

	/**
	 * Get runtime info from cache or build fresh using cached labels.
	 * Mirrors RPC::_make_runtime_info() but uses get_cached_labels().
	 * @return array<string, mixed>
	 */
	private function get_cached_runtime_info(): array {
		$key = $this->cache_key('runtime_info');
		$cached = $this->get_cached($key);

		if ($cached !== null) {
			return $cached;
		}

		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT MAX(id) AS mid, COUNT(*) AS nf FROM
			ttrss_feeds WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);
		$row = $sth->fetch();

		$data = [
			'max_feed_id' => (int) $row['mid'],
			'num_feeds' => (int) $row['nf'],
			'cdm_expanded' => Prefs::get(Prefs::CDM_EXPANDED, $_SESSION['uid'], $_SESSION['profile'] ?? null),
			'labels' => $this->get_cached_labels(),
		];

		if (Config::get(Config::LOG_DESTINATION) == 'sql' && $_SESSION['access_level'] >= UserHelper::ACCESS_LEVEL_ADMIN) {
			$sth = $pdo->prepare("SELECT COUNT(id) AS cid
				FROM ttrss_error_log
				WHERE errno NOT IN (".E_USER_NOTICE.", ".E_USER_DEPRECATED.") AND
					created_at > NOW() - INTERVAL '1 hour' AND
					errstr NOT LIKE '%Returning bool from comparison function is deprecated%' AND
					errstr NOT LIKE '%imagecreatefromstring(): Data is not in a recognized format%'");
			$sth->execute();

			if ($row = $sth->fetch()) {
				$data['recent_log_events'] = $row['cid'];
			}
		}

		if (file_exists(Config::get(Config::LOCK_DIRECTORY) . "/update_daemon.lock")) {
			$data['daemon_is_running'] = (int) file_is_locked("update_daemon.lock");

			if (time() - ($_SESSION["daemon_stamp_check"] ?? 0) > 30) {
				$stamp = (int) @file_get_contents(Config::get(Config::LOCK_DIRECTORY) . "/update_daemon.stamp");

				if ($stamp) {
					$stamp_delta = time() - $stamp;

					if ($stamp_delta > 1800) {
						$stamp_check = 0;
					} else {
						$stamp_check = 1;
						$_SESSION["daemon_stamp_check"] = time();
					}

					$data['daemon_stamp_ok'] = $stamp_check;
					$data['daemon_stamp'] = date("Y.m.d, G:i", $stamp);
				}
			}
		}

		$this->set_cached($key, $data, $this->ttl(self::REDIS_RUNTIME_INFO_TTL));

		return $data;
	}

	/**
	 * Get all labels from cache or DB.
	 * @return array<int, array{id: int, fg_color: string, bg_color: string, caption: string}>
	 */
	private function get_cached_labels(): array {
		$key = $this->cache_key('labels');
		$cached = $this->get_cached($key);

		if ($cached !== null) {
			return $cached;
		}

		$uid = $_SESSION['uid'] ?? 0;
		$labels = Labels::get_all($uid);
		$this->set_cached($key, $labels, $this->ttl(self::REDIS_LABELS_TTL));

		return $labels;
	}

	// -- Invalidation hooks --

	function hook_articles_mark_toggled(array $article_ids): void {
		$this->invalidate('counters', 'view:*', 'runtime_info', 'labels', 'label_cache_warm');
	}

	function hook_articles_publish_toggled(array $article_ids): void {
		$this->invalidate('counters', 'view:*', 'runtime_info', 'labels', 'label_cache_warm');
	}

	// -- Prefs tab --

	function hook_prefs_tab($tab): void {
		if ($tab !== 'prefPrefs') return;

		$status = $this->redis ? __('Connected') : __('Disconnected');

		$params = $this->get_connection_params();
		$host = $params['host'];
		$port = $params['port'];
		$db = $params['db'];

		$keys_count = 0;
		$memory = '';
		$hits = 0;
		$misses = 0;

		if ($this->redis) {
			try {
				$uid = $_SESSION['uid'];
				$keys = $this->redis->keys("ttrss:{$uid}:*");
				$keys_count = count($keys);

				$hits = (int) $this->redis->get("ttrss:{$uid}:_stats:hits");
				$misses = (int) $this->redis->get("ttrss:{$uid}:_stats:misses");
				// don't count stats keys themselves
				$stats_keys = 0;
				if ($hits || $misses) $stats_keys = 2;
				$keys_count = max(0, $keys_count - $stats_keys);

				$server_info = $this->redis->info('memory');
				$memory = $server_info['used_memory_human'] ?? 'N/A';
			} catch (\Throwable) {
				// ignore
			}
		}

		?>
		<div dojoType='dijit.layout.AccordionPane' title="<i class='material-icons'>memory</i> <?= __('Redis Cache') ?>">
			<h3><?= __('Cache Status') ?></h3>
			<table width="100%" cellspacing="10">
				<tr>
					<td width="30%"><?= __('Status') ?></td>
					<td><?= htmlspecialchars($status) ?></td>
				</tr>
				<tr>
					<td><?= __('Server') ?></td>
					<td><?= htmlspecialchars($host . ':' . $port . ' (db ' . $db . ')') ?></td>
				</tr>
				<?php if ($this->redis): ?>
				<tr>
					<td><?= __('Cached keys') ?></td>
					<td><?= $keys_count ?></td>
				</tr>
				<tr>
					<td><?= __('Cache hit ratio') ?></td>
					<td><?php
						$total = $hits + $misses;
						echo $total > 0
							? sprintf('%.1f%%', ($hits / $total) * 100) . " ({$hits}/{$total})"
							: 'N/A';
					?></td>
				</tr>
				<tr>
					<td><?= __('Memory used (server)') ?></td>
					<td><?= htmlspecialchars($memory) ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<h3><?= __('TTL Settings') ?></h3>
			<p class="text-muted"><?= __('Override via environment: TTRSS_REDIS_COUNTERS_TTL, TTRSS_REDIS_VIEW_TTL, TTRSS_REDIS_FEED_ICONS_TTL, etc.') ?></p>
			<table width="100%" cellspacing="10">
				<tr>
					<td width="30%"><?= __('Counters') ?></td>
					<td><?= Config::get(self::REDIS_COUNTERS_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Headlines view') ?></td>
					<td><?= Config::get(self::REDIS_VIEW_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Runtime info') ?></td>
					<td><?= Config::get(self::REDIS_RUNTIME_INFO_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Labels') ?></td>
					<td><?= Config::get(self::REDIS_LABELS_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Init params') ?></td>
					<td><?= Config::get(self::REDIS_INIT_PARAMS_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Feed tree') ?></td>
					<td><?= Config::get(self::REDIS_FEED_TREE_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Feed icons') ?></td>
					<td><?= Config::get(self::REDIS_FEED_ICONS_TTL) ?>s</td>
				</tr>
				<tr>
					<td><?= __('Translations') ?></td>
					<td><?= Config::get(self::REDIS_TRANSLATIONS_TTL) ?>s</td>
				</tr>
			</table>

			<?php if ($this->redis): ?>
			<br/>
			<button dojoType='dijit.form.Button'
				onclick="xhr.json('backend.php', {op: 'PluginHandler', plugin: 'redis_cache', method: 'flushUserCache'},
					(reply) => { Notify.info(reply.message); })">
				<?= __('Flush my cache') ?>
			</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Flush all cache entries for the current user.
	 */
	function flushUserCache(): void {
		$uid = $_SESSION['uid'] ?? 0;

		if ($this->redis) {
			try {
				$keys = $this->redis->keys("ttrss:{$uid}:*");
				if ($keys) {
					$this->redis->del($keys);
				}
			} catch (\Throwable) {
				// ignore
			}
		}

		print json_encode(['message' => __('Cache flushed')]);
	}
}
