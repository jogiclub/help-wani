<?php
/**
 * 파일 위치: application/config/autoload.php
 * 역할: 공통 라이브러리/헬퍼 자동 로드
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$autoload['packages']   = array();
$autoload['libraries']  = array('database', 'session', 'encryption', 'form_validation');
$autoload['drivers']    = array();
$autoload['helper']     = array('url', 'form', 'security', 'app');
$autoload['config']     = array();
$autoload['language']   = array();
$autoload['model']      = array();
