<?php
/**
*
* @package ExternalAuthAPI
* @copyright 2010 phpBB Ltd.
* @license http://opensource.org/licenses/gpl-license.php GNU Public License version 2 or 3 at your option
*
*/

/**
* @ignore
*/

require './config.php';

if (empty($api_config))
{
	exit;
}

// make sure this is only accessible from our own servers
if (empty($_SERVER['REMOTE_ADDR']) || !isset($api_config['allowed_ips'][$_SERVER['REMOTE_ADDR']]))
{
	exit;
}

define('IN_PHPBB', true);
$root_path = './';
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './../community/';
$phpEx = substr(strrchr(__FILE__, '.'), 1);

// Report all errors, except notices and deprecation messages
if (!defined('E_DEPRECATED'))
{
	define('E_DEPRECATED', 8192);
}
error_reporting(E_ALL ^ E_NOTICE ^ E_DEPRECATED);

if (version_compare(PHP_VERSION, '6.0.0-dev', '<'))
{
	@set_magic_quotes_runtime(0);
}

define('STRIP', (get_magic_quotes_gpc()) ? true : false);

// Before we actually initialise all files, maybe we could simply return the important part quickly?
if ($api_config['api_cache_users'] && !empty($_POST['action']) && $_POST['action'] == 'searchUsers')
{
	$result = _api_get_cached_user($root_path . 'user_cache/', $api_config['api_cache_users']);

	if ($result !== false)
	{
		echo $result;
		garbage_collection();
		exit_handler();
		exit;
	}
}

require($phpbb_root_path . 'config.' . $phpEx);

if (!defined('PHPBB_INSTALLED') || empty($dbms) || empty($acm_type))
{
	exit;
}

// Include files
require($phpbb_root_path . 'includes/acm/acm_' . $acm_type . '.' . $phpEx);
require($phpbb_root_path . 'includes/cache.' . $phpEx);
require($phpbb_root_path . 'includes/db/' . $dbms . '.' . $phpEx);
require($phpbb_root_path . 'includes/constants.' . $phpEx);
require($phpbb_root_path . 'includes/utf/utf_tools.' . $phpEx);
require($phpbb_root_path . 'includes/functions.' . $phpEx);
require($phpbb_root_path . 'includes/functions_user.' . $phpEx);

$db = new $sql_db();
$cache = new cache();

// Connect to DB
if (!@$db->sql_connect($dbhost, $dbuser, $dbpasswd, $dbname, $dbport, false, false))
{
	exit;
}
unset($dbpasswd);

$config = $cache->obtain_config();

/* Try to get some custom "things"
$_POST['max'] = $_REQUEST['max'] = 1;
$_POST['restriction'] = $_REQUEST['restriction'] = "{\"mode\": \"EXACTLY_MATCHES\", \"property\": \"name\", \"value\": \"global moderators\"}";
$_POST['start'] = $_REQUEST['start'] = 0;
$_POST['action'] = $_REQUEST['action'] = 'searchGroups';
$_POST['returnType'] = $_REQUEST['returnType'] = 'ENTITY';
*/

// Initialize auth API
$api = new phpbb_auth_api($api_config);

$action = basename(request_var('action', ''));

// First wanted to implement this with __call(), but then there is no way for auto completion in modern Editors
if ($api->init($action))
{
	$api->$action();
}

echo $api->get_output();
$api->close();

if (!empty($cache))
{
	$cache->unload();
}
$db->sql_close();

exit;

/**
* Authentication to Crowd
*/
class phpbb_auth_api
{
	protected $debug_file = false;

	protected $fp = false;
	protected $output = array();

	protected $user_query_sql = false;

	// For faster access
	protected $special_groups = array();
	protected $special_groups_reverse = array();

	protected $config = array();

	public function __construct($config)
	{
		$this->config = $config;
		$this->debug_file = $config['debug_api'];

		// Special group name mapping
		$this->special_groups = array(
			'ADMINISTRATORS'		=> 'Administrators',
			'BOTS'					=> 'Bots',
			'GUESTS'				=> 'Guests',
			'REGISTERED'			=> 'Registered users',
			'REGISTERED_COPPA'		=> 'Registered COPPA users',
			'GLOBAL_MODERATORS'		=> 'Global moderators',
			'NEWLY_REGISTERED'		=> 'Newly registered users',
		);

		$this->special_groups = array_change_key_case($this->special_groups, CASE_LOWER);

		$this->special_groups_reverse = array_flip($this->special_groups);
		$this->special_groups_reverse = array_change_key_case($this->special_groups_reverse, CASE_LOWER);
	}

	public function implemented()
	{
		return array(
			'authenticate',
			'searchUsers',
			'searchGroups',
			'groupMembers',
			'UserMemberships',
		);
	}

	public function init($action)
	{
		$this->output = array();

		if (!method_exists($this, $action))
		{
			return false;
		}

		if ($this->debug_file && !$this->fp)
		{
			$this->fp = fopen($this->debug_file, 'a');
			$this->debug('INIT', '');
			$this->debug('post', $_POST);
		}
		return true;
	}

	public function close()
	{
		if ($this->debug_file && $this->fp)
		{
			$this->debug('output', $this->output);
			$this->debug('FINISHED', '');
			fclose($this->fp);
		}
	}

	public function get_output()
	{
		return implode("\n", $this->output);
	}

	public function debug($action, $data)
	{
		if (!$this->debug_file) return;

		fwrite($this->fp, '[' . date('Y-m-d H:i:s') . '] [' . $action . '] ');
		if (is_array($data))
		{
			if ($this->config['debug_api_limit_output'] && sizeof($data) > $this->config['debug_api_limit_output'])
			{
				fwrite($this->fp, 'Dataset: ' . sizeof($data) . ' Elements.');
				$data = array();
			}

			if (!empty($data['credential']))
			{
				$data['credential'] = '***';
			}

			foreach ($data as $key => $element)
			{
				fwrite($this->fp, "\n[" . date('Y-m-d H:i:s') . '] [' . $key . '] => ' . $element);
			}
		}
		else
		{
			fwrite($this->fp, $data);
		}

		fwrite($this->fp, "\n");
	}

	protected function add($line)
	{
		$this->output[] = $line;
		return $this;
	}

	protected function groups_query($sql_prefix = '', $sql_alias = '')
	{
		if (empty($this->config['exclude_groups']))
		{
			return '';
		}

		$sql = ($sql_prefix) ? ' ' . $sql_prefix . ' ' : '';
		$sql .= ($sql_alias) ? $sql_alias . '.' : '';
		$sql .= 'group_id NOT IN (' . implode(', ', array_map('intval', $this->config['exclude_groups'])) . ')';

		return $sql;
	}

	protected function users_query($sql_prefix = '', $sql_alias = '')
	{
		global $db;

		if ($this->user_query_sql === false)
		{
			// Get banned user ids
			$banned_user_ids = array();

			if ($this->config['exclude_banned_users'])
			{
				$sql = 'SELECT ban_userid FROM ' . BANLIST_TABLE . '
					WHERE ban_userid <> 0
						AND ban_exclude = 0';
				$result = $db->sql_query($sql);
				while ($row = $db->sql_fetchrow($result))
				{
					$banned_user_ids[] = (int) $row['ban_userid'];
				}
				$db->sql_freeresult($result);
			}

			// Check for last login date. ;)
			// Use the same timestamp for a day, to let the query be cached later maybe. :)
			if ($this->config['last_login_period'])
			{
				$last_logged_in = strtotime(date('Y-m-d')) - (int) ($this->config['last_login_period'] * 24 * 60 * 60);
			}
			else
			{
				$last_logged_in = 0;
			}

			$this->user_query_sql = array();

			if (sizeof($banned_user_ids))
			{
				$this->user_query_sql[] = array('key' => 'user_id', 'query' => 'NOT IN (' . implode(', ', $banned_user_ids) . ')');
			}

			if ($last_logged_in)
			{
				$this->user_query_sql[] = array('key' => 'user_lastvisit', 'query' => ' > ' . $last_logged_in);
			}
		}

		// Return correct query
		if (empty($this->user_query_sql))
		{
			return '';
		}

		$sql_ary = array();
		foreach ($this->user_query_sql as $query)
		{
			$sql_ary[] = (($sql_alias) ? $sql_alias . '.' : '') . $query['key'] . ' ' . $query['query'];
		}

		return (($sql_prefix) ? ' ' . $sql_prefix . ' ' : '') . implode(' AND ', $sql_ary);
	}

	protected function get_user_name($user_name)
	{
		$user_name = html_entity_decode($user_name, ENT_COMPAT, 'UTF-8');

		if (preg_match('/[\x01-\x08]/', $user_name))
		{
			return false;
		}

		return $user_name;
	}

	// not implemented
	public function findUserByName()
	{
		$name = request_var('name', '', true);
		$this->add_line('not implemented');
	}

	public function authenticate()
	{
		global $db, $auth, $config, $phpEx, $phpbb_root_path;

		$username = request_var('name', '', true);
		$password = request_var('credential', '', true);

		$err = '';

		// If authentication is successful we redirect user to previous page
		$method = trim(basename($config['auth_method']));
		include_once($phpbb_root_path . 'includes/auth/auth_' . $method . '.' . $phpEx);

		$method = 'login_' . $method;
		if (!function_exists($method))
		{
			$this->add('error')->add('NO_AUTH');
			return;
		}

		$result = $method($username, $password);

		// If the auth module wants us to create an empty profile do so and then treat the status as LOGIN_SUCCESS
		if ($login['status'] == LOGIN_SUCCESS_CREATE_PROFILE)
		{
			// we are going to use the user_add function so include functions_user.php if it wasn't defined yet
			if (!function_exists('user_add'))
			{
				include($phpbb_root_path . 'includes/functions_user.' . $phpEx);
			}

			user_add($login['user_row'], (isset($login['cp_data'])) ? $login['cp_data'] : false);

			$sql = 'SELECT user_id, username, user_password, user_passchg, user_email, user_type
				FROM ' . USERS_TABLE . "
				WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($username)) . "'";
			$result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);

			if (!$row)
			{
				$this->add('error')->add('Failed to create profile');
				return;
			}

			$result = array(
				'status'    => LOGIN_SUCCESS,
				'error_msg' => false,
				'user_row'  => $row,
			);
		}

		if (isset($result['user_row']['user_id']))
		{
			$sql = 'SELECT ban_userid FROM ' . BANLIST_TABLE . '
				WHERE ban_userid = ' . (int) $result['user_row']['user_id'];
			$ban_result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($ban_result);
			$db->sql_freeresult($ban_result);

			if ($row)
			{
				$this->add('error')->add('You are banned');
				return;
			}
		}

		// The result parameter is always an array, holding the relevant information...
		if ($result['status'] == LOGIN_SUCCESS)
		{
			// get avatar url
			$sql = 'SELECT user_avatar, user_avatar_type, user_avatar_width, user_avatar_height
				FROM ' . USERS_TABLE . '
				WHERE user_id = ' . $result['user_row']['user_id'];
			$sql_result = $db->sql_query($sql);
			$row = $db->sql_fetchrow($sql_result);
			$db->sql_freeresult($sql_result);

			$result['user_row']['user_avatar']        = $row['user_avatar'];
			$result['user_row']['user_avatar_type']   = $row['user_avatar_type'];
			$result['user_row']['user_avatar_width']  = $row['user_avatar_width'];
			$result['user_row']['user_avatar_height'] = $row['user_avatar_height'];

			$this->add('success')->add($this->user_row_line($result['user_row']));
			return;
		}
		else
		{
			// Failures
			switch ($result['status'])
			{
				case LOGIN_BREAK:
					$this->add('error')->add($result['error_msg']);
					return;
				break;

				case LOGIN_ERROR_ATTEMPTS:
					// should we really error here?
					// but if the external system does not protect from brute force
					// not throwing an error here is potentially dangerous
					$this->add('error')->add($result['error_msg']);
					return;
				break;

				case LOGIN_ERROR_PASSWORD_CONVERT:
					// can only tell the person to go back to the forum to get a new password
					// unlikely to happen anyway.
					$this->add('error')->add($result['error_msg']);
					return;
				break;

				// Username, password, etc...
				default:
					$this->add('error')->add($result['error_msg']);
					return;
				break;
			}
		}

		$this->add('error')->add('Unexpected result');
	}

	public function searchUsers()
	{
		global $db;

		$start = request_var('start', 0);
		$max = request_var('max', 0);
		$return_type = request_var('returnType', ''); // NAME or ENTITY
		$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

		$searchRestriction = new SearchRestriction($this,
			$restriction,
			// Is it safe to assume our directory will only search for name? ;)
			array(
				'email' => 'u.user_email',
/*				'lastName' => 'firstname',
				'firstName' => 'lastname', // always empty
				'displayName' => "CONCAT(firstname, ' ', lastname)",*/
				'name' => 'u.username_clean',
				'active' => 'u.user_type'
			));

		$sql = 'SELECT u.user_id, u.username, u.user_type, u.user_email, u.user_avatar, u.user_avatar_type, u.user_avatar_width, u.user_avatar_width';

		if ($this->config['firstname_column'] && $this->config['lastname_column'])
		{
			$sql .= ', pf.pf_' . $this->config['firstname_column'] . ' as firstname, pf.pf_' . $this->config['lastname_column'] . ' as lastname';
		}

		$sql .= ' FROM ' . USERS_TABLE . ' u';

		if ($this->config['firstname_column'] && $this->config['lastname_column'])
		{
			$sql .= ' LEFT JOIN ' . PROFILE_FIELDS_DATA_TABLE . ' pf ON (u.user_id = pf.user_id)';
		}

		$sql .= ' WHERE u.user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')';
		$sql .= $this->users_query('AND', 'u');
		$sql .= $searchRestriction->getWhere();
		$result = $db->sql_query_limit($sql, $max, $start);

		while ($row = $db->sql_fetchrow($result))
		{
			$line = ($return_type == 'ENTITY') ? $this->user_row_line($row) : $this->get_user_name($row['username']);

			if ($line !== false)
			{
				$this->add($line);
			}
		}
		$db->sql_freeresult($result);

		if ($this->config['api_cache_users'] && $max == 1 && $start == 0 && $return_type == 'ENTITY')
		{
			global $root_path;
			_api_set_cached_user($root_path . 'user_cache/', $restriction);
		}
	}

	public function groupMembers()
	{
		global $db;

		$start = request_var('start', 0);
		$max = request_var('max', 0);
		$return_type = request_var('returnType', ''); // NAME or ENTITY
		$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

		$searchRestriction = new SearchRestriction($this,
			$restriction,
			array(
				'name' => 'g.group_name',
		));

		$sql = 'SELECT u.user_id, u.username, u.user_type, u.user_email, u.user_avatar, u.user_avatar_type, u.user_avatar_width, u.user_avatar_width';

		if ($this->config['firstname_column'] && $this->config['lastname_column'])
		{
			$sql .= ', pf.pf_' . $this->config['firstname_column'] . ' as firstname, pf.pf_' . $this->config['lastname_column'] . ' as lastname';
		}

		$sql .= ' FROM (' . GROUPS_TABLE . ' g, ' . USER_GROUP_TABLE . ' ug, ' . USERS_TABLE . ' u)';

		if ($this->config['firstname_column'] && $this->config['lastname_column'])
		{
			$sql .= ' LEFT JOIN ' . PROFILE_FIELDS_DATA_TABLE . ' pf ON (u.user_id = pf.user_id)';
		}

		$sql .= ' WHERE g.group_id = ug.group_id
				AND ug.user_id = u.user_id
				AND ug.user_pending = 0
				AND u.user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')';
		$sql .= $this->users_query('AND', 'u');
		$sql .= $this->groups_query('AND', 'g');
		$sql .= $searchRestriction->getWhere();
		$result = $db->sql_query_limit($sql, $max, $start);

		while ($row = $db->sql_fetchrow($result))
		{
			$line = ($return_type == 'ENTITY') ? $this->user_row_line($row) : $this->get_user_name($row['username']);

			if ($line !== false)
			{
				$this->add($line);
			}
		}
		$db->sql_freeresult($result);
	}

	public function searchGroups()
	{
		global $db;

		$start = request_var('start', 0);
		$max = request_var('max', 0);
		$return_type = request_var('returnType', ''); // NAME or ENTITY
		$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

		$searchRestriction = new SearchRestriction($this,
			$restriction,
			array(
				'description' => 'group_desc',
				'name' => 'group_name',
				'active' => "'true'", // all phpBB groups are active, true = true (all), true = false (none)
		));

		$sql = 'SELECT group_id, group_name, group_desc, group_type
			FROM ' . GROUPS_TABLE . '
			WHERE 1=1';
		$sql .= $this->groups_query('AND');
		$sql .= $searchRestriction->getWhere();
		$result = $db->sql_query_limit($sql, $max, $start);

		while ($row = $db->sql_fetchrow($result))
		{
			$line = ($return_type == 'ENTITY') ? $this->group_row_line($row) : $this->get_group_name($row['group_name']);

			if ($line !== false)
			{
				$this->add($line);
			}
		}
		$db->sql_freeresult($result);
	}

	public function UserMemberships()
	{
		global $db;

		$start = request_var('start', 0);
		$max = request_var('max', 0);
		$return_type = request_var('returnType', ''); // NAME or ENTITY
		$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

		$searchRestriction = new SearchRestriction($this,
			$restriction,
			array(
				'name' => 'u.username_clean',
				'groupname' => 'g.group_name',
		));

		$sql = 'SELECT g.group_id, g.group_name, g.group_desc, g.group_type
			FROM ' . USERS_TABLE . ' u, ' . USER_GROUP_TABLE . ' ug, ' . GROUPS_TABLE . ' g
			WHERE u.user_id = ug.user_id
				AND ug.group_id = g.group_id
				AND ug.user_pending = 0
				AND u.user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')';
		$sql .= $this->users_query('AND', 'u');
		$sql .= $this->groups_query('AND', 'g');
		$sql .= $searchRestriction->getWhere();

		$result = $db->sql_query_limit($sql, $max, $start);

		while ($row = $db->sql_fetchrow($result))
		{
			$line = ($return_type == 'ENTITY') ? $this->group_row_line($row) : $this->get_group_name($row['group_name']);

			if ($line !== false)
			{
				$this->add($line);
			}
		}
		$db->sql_freeresult($result);
	}

	// username, user_email, user_active, avatar, first_name, last_name
	protected function user_row_line($row)
	{
		global $config, $phpEx, $phpbb_root_path;

		$icon_location = '';

		if ($this->config['avatar_base_url'])
		{
			$row['user_avatar'] = html_entity_decode($row['user_avatar'], ENT_COMPAT, 'UTF-8');

			if (!empty($row['user_avatar']) && $row['user_avatar_type'] && $config['allow_avatar'])
			{
				switch ($row['user_avatar_type'])
				{
					case AVATAR_UPLOAD:
						if ($config['allow_avatar_upload'])
						{
							$icon_location = $this->config['avatar_base_url'] . "download/file.$phpEx?avatar=" . $row['user_avatar'];
						}
					break;

					case AVATAR_GALLERY:
						if ($config['allow_avatar_local'])
						{
							$icon_location = $this->config['avatar_base_url'] . $config['avatar_gallery_path'] . '/' . $row['user_avatar'];
						}
					break;

					case AVATAR_REMOTE:
						if ($config['allow_avatar_remote'])
						{
							$icon_location = $row['user_avatar'];
						}
					break;
				}

				$icon_location = str_replace(' ', '%20', $icon_location);
			}
		}

		$username = $this->get_user_name($row['username']);

		if ($username === false)
		{
			return false;
		}

		$user_entity_row = array(
			'user_id'		=> $row['user_id'],
			'username'		=> $username,
			'user_email'	=> html_entity_decode($row['user_email'], ENT_COMPAT, 'UTF-8'),
			'user_type'		=> $row['user_type'],
			'icon_location'	=> $icon_location,
		);

		if (!empty($row['firstname']))
		{
			$user_entity_row['firstname'] = $row['firstname'];
		}

		if (!empty($row['lastname']))
		{
			$user_entity_row['lastname'] = $row['lastname'];
		}

		return implode("\t", $user_entity_row);
	}

	protected function group_row_line($row)
	{
		// Return correct group name
		$group_name = $this->get_group_name($row['group_name']);

		if ($group_name === false)
		{
			return false;
		}

		return $row['group_id'] . "\t" . html_entity_decode($group_name, ENT_COMPAT, 'UTF-8') . "\t" . $row['group_type'] . "\t" . html_entity_decode(str_replace("\n", ' ', $row['group_desc']), ENT_COMPAT, 'UTF-8');
	}

	/**
	* Get correct group name. Prefixed with _api_ to not conflict with phpBB funciton get_group_name()
	*/
	public function get_group_name($group_name, $reverse = false)
	{
		if ($reverse)
		{
			return (isset($this->special_groups_reverse[strtolower($group_name)])) ? $this->special_groups_reverse[strtolower($group_name)] : $group_name;
		}

		$group_name = (isset($this->special_groups[strtolower($group_name)])) ? $this->special_groups[strtolower($group_name)] : $group_name;
		return html_entity_decode($group_name, ENT_COMPAT, 'UTF-8');
	}
}

class SearchRestriction
{
	private $obj;
	private $columns;
	private $api;

	public function __construct($api, $restrictionJson, $columnMap)
	{
		$this->api = $api;
		$this->obj = json_decode($restrictionJson, true);

		$this->columns = $columnMap;
	}

	public function propertyToColumn($property)
	{
		return isset($this->columns[$property]) ? $this->columns[$property] : '';
	}

	public function getWhere()
	{
		$whereClause = $this->recursiveWhere($this->obj);

		if ($whereClause)
		{
			return ' AND ' . $whereClause;
		}

		return '';
	}

	protected function recursiveWhere($obj)
	{
		if (isset($obj['mode'])) // term
		{
			return $this->whereTerm($obj['mode'], $obj['property'], $obj['value']);
		}
		else if (isset($obj['boolean'])) // multi term
		{
			return $this->whereMultiTerm($obj['boolean'], $obj['terms']);
		}

		return '';
	}

	protected function whereMultiTerm($operator, $operands)
	{
		$where = '(';

		$first = true;
		foreach ($operands as $operand)
		{
			$whereClause = $this->recursiveWhere($operand);

			if (!empty($whereClause))
			{
				if (!$first)
				{
					$where .= ' ' . $operator . ' ';
				}
				$first = false;

				$where .= $whereClause;
			}
		}

		$where .= ')';

		return $where;
	}

	protected function whereTerm($compareMode, $property, $value)
	{
		global $db;

		$column = $this->propertyToColumn($property);

		if (empty($column) || (empty($value) && $value !== '0'))
		{
			return '';
		}

		// Because we decoded the search restriction but phpBB always stores speciachar'd content we need to specialchar all values before we use them
		$value = utf8_htmlspecialchars($value);

		$where = $column . ' ';

		// remove alias to get plain column name
		$plain_column = (strpos($column, '.') !== false) ? substr($column, strpos($column, '.') + 1) : $column;

		// Adjust value if we need to search for group name.
		if ($plain_column == 'group_name')
		{
			// Define true as second parameter to reverse the mapping (English name to name stored in database)
			$value = $this->api->get_group_name($value, true);
			$where = 'LOWER(' . $column . ') ';
			$value = strtolower($value);
		}

		// Make sure usernames are always "cleaned" up
		if ($plain_column == 'username_clean')
		{
			$value = utf8_clean_string($value);
		}

		switch ($compareMode)
		{
			case 'CONTAINS':
				$where .= $db->sql_like_expression($db->any_char . $value . $db->any_char);
			break;
			case 'EXACTLY_MATCHES':
				if ($plain_column == 'user_type')
				{
					if ($value == 'true')
					{
						$where .= ' <> ';
					}
					else
					{
						$where .= ' = ';
					}
					$where .= USER_INACTIVE;
				}
				else
				{
					$where .= '= \'' . $db->sql_escape($value) . '\'';
				}
			break;
			case 'GREATER_THAN':
				$where .= '> \'' . (int) $value . '\'';
			break;
			case 'LESS_THAN':
				$where .= '< \'' . (int) $value . '\'';
			break;
			case 'STARTS_WITH':
				$where .= $db->sql_like_expression($value . $db->any_char);
			break;
		}

		return $where;
	}
}

function _api_get_cached_user($cache_path, $api_cache_users)
{
	$max = (!empty($_POST['max'])) ? (int) $_POST['max'] : 0;
	$start = (!empty($_POST['start'])) ? (int) $_POST['start'] : 0;
	$returntype = (!empty($_POST['returnType'])) ? (string) $_POST['returnType'] : '';

	// Check user name...
	if ($max == 1 && $start == 0 && $returntype == 'ENTITY')
	{
		$restriction = (!empty($_POST['restriction'])) ? (string) $_POST['restriction'] : '';
		$restriction = json_decode(html_entity_decode((STRIP) ? stripslashes($restriction) : $restriction, ENT_COMPAT, 'UTF-8'), true);

		if ($restriction['mode'] == 'EXACTLY_MATCHES' && $restriction['property'] == 'name' && !empty($restriction['value']))
		{
			$md5 = md5($restriction['value']);
			$first_char = $md5[0];
			$second_char = $md5[1];

			if (file_exists($cache_path . $first_char . '/' . $second_char . '/' . $md5))
			{
				$lastchange = @filemtime($cache_path . $first_char . '/' . $second_char . '/' . $md5);

				if ($lastchange >= time() - $api_cache_users)
				{
					return file_get_contents($cache_path . $first_char . '/' . $second_char . '/' . $md5);
				}
			}
		}
	}

	return false;
}

function _api_set_cached_user($cache_path, $restriction)
{
	$restriction = json_decode($restriction, true);

	if ($restriction['mode'] == 'EXACTLY_MATCHES' && $restriction['property'] == 'name' && !empty($restriction['value']))
	{
		$md5 = md5($restriction['value']);
		$first_char = $md5[0];
		$second_char = $md5[1];

		if (!file_exists($cache_path . $first_char))
		{
			mkdir($cache_path . $first_char);
		}

		if (!file_exists($cache_path . $first_char . '/' . $second_char))
		{
			mkdir($cache_path . $first_char . '/' . $second_char);
		}

		$fp = fopen($cache_path . $first_char . '/' . $second_char . '/' . $md5, 'w');
		fwrite($fp, $line . "\n");
		fclose($fp);
	}
}
