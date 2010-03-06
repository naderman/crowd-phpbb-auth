<?php
/**
 *
 * @package ExternalAuthAPI
 * @copyright (c) 2010 phpBB Group
 * @license http://opensource.org/licenses/gpl-license.php
 *          GNU Public License version 2 or 3 at your option
 *
 */

/**
 * @ignore
 */
$allowed_ips = array(
    '127.0.0.1' => true,
);

// make sure this is only accessible from our own servers
if (empty($_SERVER['REMOTE_ADDR']) || !isset($allowed_ips[$_SERVER['REMOTE_ADDR']]))
{
	exit;
}

define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);

// Report all errors, except notices and deprecation messages
if (!defined('E_DEPRECATED'))
{
	define('E_DEPRECATED', 8192);
}
error_reporting(E_ALL ^ E_NOTICE ^ E_DEPRECATED);

require($phpbb_root_path . 'config.' . $phpEx);

if (!defined('PHPBB_INSTALLED') || empty($dbms) || empty($acm_type))
{
	exit;
}

if (version_compare(PHP_VERSION, '6.0.0-dev', '<'))
{
	@set_magic_quotes_runtime(0);
}

define('STRIP', (get_magic_quotes_gpc()) ? true : false);

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

$action = basename(request_var('action', ''));

if (function_exists('phpbb_external_auth_api_' . $action))
{
	$result = call_user_func('phpbb_external_auth_api_' . $action);
}

echo $result;

if (!empty($cache))
{
	$cache->unload();
}
$db->sql_close();

exit;

// phpbb_external_auth_api_ function definitions

function phpbb_external_auth_api_findUserByName()
{
	global $db;
	$name = request_var('name', '', true);

	return 'not implemented';
}

function phpbb_external_auth_api_authenticate()
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
		return "error\nNO_AUTH";
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
			return "error\nFailed to create profile";
		}

		$result = array(
			'status'    => LOGIN_SUCCESS,
			'error_msg' => false,
			'user_row'  => $row,
		);
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

		$output = "success\n";
		$output .= user_row_line($result['user_row']) . "\n";

		return $output;
	}
	else
	{
		// Failures
		switch ($result['status'])
		{
			case LOGIN_BREAK:
				return "error\n" . $result['error_msg'];
			break;

			case LOGIN_ERROR_ATTEMPTS:

				// should we really error here?
				// but if the external system does not protect from brute force
				// not throwing an error here is potentially dangerous
				return "error\n" . $result['error_msg'];
			break;

			case LOGIN_ERROR_PASSWORD_CONVERT:
				// can only tell the person to go back to the forum to get a new password
				// unlikely to happen anyway.
				return "error\n" . $result['error_msg'];
			break;

			// Username, password, etc...
			default:
				return "error\n" . $result['error_msg'];
			break;
		}
	}

	return "error\nUnexpected result";
}

function phpbb_external_auth_api_searchUsers()
{
	global $db;

	$start = request_var('start', 0);
	$max = request_var('max', 0);
	$return_type = request_var('returnType', 0); // NAME or ENTITY
	$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

	$searchRestriction = new SearchRestriction(
		$restriction,
		array(
			'email' => 'user_email',
			'lastName' => 'username',
			'firstName' => '', // always empty
			'displayName' => 'username',
			'name' => 'username',
			'active' => 'user_type'
	));

	$sql = 'SELECT user_id, username, user_type, user_email, user_avatar, user_avatar_type, user_avatar_width, user_avatar_width
		FROM ' . USERS_TABLE . '
		WHERE user_type <> ' . USER_IGNORE;
	$sql .= $searchRestriction->getWhere();
	$result = $db->sql_query_limit($sql, $max, $start);

	$output = '';
	while ($row = $db->sql_fetchrow($result))
	{
		/*if ($return_type == 'ENTITY') - apparently NAME is not used*/
		$output .= user_row_line($row) . "\n";
	}

	$db->sql_freeresult($result);

	return $output;
}

function phpbb_external_auth_api_groupMembers()
{
	global $db;

	$start = request_var('start', 0);
	$max = request_var('max', 0);
	$return_type = request_var('returnType', 0); // NAME or ENTITY
	$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

	$searchRestriction = new SearchRestriction(
		$restriction,
		array(
			'name' => 'g.group_name',
	));

	$sql = 'SELECT u.user_id, u.username, u.user_type, u.user_email, u.user_avatar, u.user_avatar_type, u.user_avatar_width, u.user_avatar_width
		FROM ' . GROUPS_TABLE . ' g, ' . USER_GROUP_TABLE . ' ug, ' . USERS_TABLE . ' u
		WHERE g.group_id = ug.group_id AND ug.user_id = u.user_id AND u.user_type <> ' . USER_IGNORE;
	$sql .= $searchRestriction->getWhere();
	$result = $db->sql_query_limit($sql, $max, $start);

	$output = '';
	while ($row = $db->sql_fetchrow($result))
	{
		if ($return_type == 'ENTITY')
		{
			$output .= user_row_line($row) . "\n";
		}
		else
		{
			$output .= $row['username'] . "\n";
		}
	}

	$db->sql_freeresult($result);

	return $output;
}

function phpbb_external_auth_api_searchGroups()
{
	global $db;

	$start = request_var('start', 0);
	$max = request_var('max', 0);
	$return_type = request_var('returnType', 0); // NAME or ENTITY
	$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

	$searchRestriction = new SearchRestriction(
		$restriction,
		array(
			'description' => 'group_desc',
			'name' => 'group_name',
			'active' => '\'true\'', // all phpBB groups are active, true = true (all), true = false (none)
	));

	$sql = 'SELECT group_id, group_name, group_desc, group_type
		FROM ' . GROUPS_TABLE . '
		WHERE 1=1 ';
	$sql .= $searchRestriction->getWhere();

	$result = $db->sql_query_limit($sql, $max, $start);

	$output = '';
	while ($row = $db->sql_fetchrow($result))
	{
		if ($return_type == 'ENTITY')
		{
			$output .= group_row_line($row) . "\n";
		}
		else
		{
			$output .= _api_get_group_name($row['group_name']) . "\n";
		}
	}

	$db->sql_freeresult($result);

	return $output;
}

function phpbb_external_auth_api_UserMemberships()
{
	global $db;

	$start = request_var('start', 0);
	$max = request_var('max', 0);
	$return_type = request_var('returnType', 0); // NAME or ENTITY
	$restriction = html_entity_decode(request_var('restriction', '', true), ENT_COMPAT, 'UTF-8');

	$searchRestriction = new SearchRestriction(
		$restriction,
		array(
			'name' => 'u.username',
			'groupname' => 'g.group_name',
	));

	$sql = 'SELECT g.group_id, g.group_name, g.group_desc, g.group_type
		FROM ' . USERS_TABLE . ' u, ' . USER_GROUP_TABLE . ' ug, ' . GROUPS_TABLE . ' g
		WHERE u.user_id = ug.user_id AND ug.group_id = g.group_id';
	$sql .= $searchRestriction->getWhere();

	$result = $db->sql_query_limit($sql, $max, $start);

	$output = '';
	while ($row = $db->sql_fetchrow($result))
	{
		if ($return_type == 'ENTITY')
		{
			$output .= group_row_line($row) . "\n";
		}
		else
		{
			$output .= _api_get_group_name($row['group_name']) . "\n";
		}
	}

	$db->sql_freeresult($result);

	return $output;
}

class SearchRestriction
{
	private $obj;
	private $columns;

	public function __construct($restrictionJson, $columnMap)
	{
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

		$where = $column . ' ';

		// remove alias to get plain column name
		$plain_column = (strpos($column, '.') !== false) ? substr($column, strpos($column, '.') + 1) : $column;

		// Adjust value if we need to search for group name.
		if ($plain_column == 'group_name')
		{
			// Define true as second parameter to reverse the mapping (English name to name stored in database)
			$value = _api_get_group_name($value, true);
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

/**
 * @todo use base URL for avatars?
 */
function user_row_line($row)
{
	global $config, $phpEx, $phpbb_root_path;

	$output = '';

	$row['user_avatar'] = html_entity_decode($row['user_avatar'], ENT_COMPAT, 'UTF-8');

	$avatar_url = '';
	if (!empty($row['user_avatar']) && $row['user_avatar_type'] && $config['allow_avatar'])
	{
		switch ($row['user_avatar_type'])
		{
			case AVATAR_UPLOAD:
				if ($config['allow_avatar_upload'])
				{
					$avatar_url = $phpbb_root_path . "download/file.$phpEx?avatar=" . $row['user_avatar'];
				}
			break;

			case AVATAR_GALLERY:
				if ($config['allow_avatar_local'])
				{
					$avatar_url = $phpbb_root_path . $config['avatar_gallery_path'] . '/' . $row['user_avatar'];
				}
			break;

			case AVATAR_REMOTE:
				if ($config['allow_avatar_remote'])
				{
					$avatar_url = $row['user_avatar'];
				}
			break;
		}

		$avatar_url = str_replace(' ', '%20', $avatar_url);
	}

	$output .= $row['user_id'] . "\t";
	$output .= html_entity_decode($row['username'], ENT_COMPAT, 'UTF-8') . "\t";
	$output .= html_entity_decode($row['user_email'], ENT_COMPAT, 'UTF-8') . "\t";
	$output .= $row['user_type'] . "\t";
	$output .= $avatar_url . "\n";

	return $output;
}

function group_row_line($row)
{
	// Return correct group name
	$row['group_name'] = _api_get_group_name($row['group_name']);

	$output  = $row['group_id'] . "\t";
	$output .= html_entity_decode($row['group_name'], ENT_COMPAT, 'UTF-8') . "\t";
	$output .= $row['group_type'] . "\t";
	$output .= html_entity_decode($row['group_desc'], ENT_COMPAT, 'UTF-8') . "\n";

	return $output;
}

/**
 * Get correct group name. Prefixed with _api_ to not conflict with phpBB funciton get_group_name()
 */
function _api_get_group_name($group_name, $reverse = false)
{
	// Special group name mapping
	$special_groups = array(
		'ADMINISTRATORS'		=> 'Administrators',
		'BOTS'					=> 'Bots',
		'GUESTS'				=> 'Guests',
		'REGISTERED'			=> 'Registered users',
		'REGISTERED_COPPA'		=> 'Registered COPPA users',
		'GLOBAL_MODERATORS'		=> 'Global moderators',
		'NEWLY_REGISTERED'		=> 'Newly registered users',
	);

	// Resolve english names to database names?
	if ($reverse === true)
	{
		$special_groups = array_flip($special_groups);
	}

	return (isset($special_groups[$group_name])) ? $special_groups[$group_name] : $group_name;
}
