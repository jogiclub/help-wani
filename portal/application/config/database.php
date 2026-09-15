<?php
/**
 * 파일 위치: application/config/database.php
 * 역할: MySQL 접속 설정 (환경변수 기반)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/env.php';

$active_group = 'default';
$query_builder = TRUE;

$db['default'] = array(
	'dsn'      => '',
	'hostname' => env('DB_HOST', 'db'),
	'username' => env('DB_USER', 'remotehelp'),
	'password' => env('DB_PASS', 'remotehelp'),
	'database' => env('DB_NAME', 'remotehelp'),
	'dbdriver' => 'mysqli',
	'dbprefix' => '',
	'pconnect' => FALSE,
	'db_debug' => (env('APP_ENV') === 'development'),
	'cache_on' => FALSE,
	'cachedir' => '',
	'char_set' => 'utf8mb4',
	'dbcollat' => 'utf8mb4_unicode_ci',
	'swap_pre' => '',
	'encrypt'  => FALSE,
	'compress' => FALSE,
	'stricton' => FALSE,
	'failover' => array(),
	'save_queries' => TRUE,
	'port'     => (int) env('DB_PORT', 3306),
);
