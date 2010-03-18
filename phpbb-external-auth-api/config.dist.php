<?php

$api_config = array(
	// Base avatar URL, for example http://www.example.com/forum/ - if set to false no avatars are returned
	'avatar_base_url'	=> false,

	// Debug calls? Writes to log file, set to false to disable or point to log file (absolute path)
	'debug_api'			=> false,

	// Do not write to log file if output has more than 50 entries
	'debug_api_limit_output'	=> 50,

	// enable user cache to create less burden on the database if crowd refreshes it's user list cache. Set to time in seconds.
	'api_cache_users'		=> false,

	// Define last login period in days (only users fetched who where active within the last X days)
	'last_login_period'		=> 2 * 365,

	// Exclude banned users from user list?
	'exclude_banned_users'	=> true,

	// Custom profile field for first and last name? false to disable
	'firstname_column'		=> 'first_name',
	'lastname_column'		=> 'last_name',

	// Allowed IP's
	'allowed_ips'			=> array(
		'127.0.0.1'		=> true,
	),

	// Groups we want to not have in our directory
	// This is extremely useful, because this ensures a clean directory - Atlassian Tools try to index and check every single group, regardless of it's status/connection
	'exclude_groups'		=> array(
		228654, // Bots
		228649, // Guests
		228735, // Newly Registered Users
		228651, // COPPA
		228725, // on moderation queue
		228695, // without edit
		84421, // Former Team Members
	),
);
