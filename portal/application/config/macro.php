<?php
/**
 * 파일 위치: application/config/macro.php
 * 역할: 상담 대화 매크로 설정 (개수·길이 제한, 파일 등록 제한, 추천 가중치와 기준 점수)
 *
 * 설계는 docs/macro-plan.md. 추천 색인 가중치를 바꾸면 다음 저장 때부터 반영된다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

// 개수 제한
$config['max_org']       = 2000;  // 조직 공용 매크로
$config['max_personal']  = 300;   // 상담원 1명의 개인 매크로
$config['max_favorites'] = 100;   // 상담원 1명의 즐겨찾기

// 길이 제한 (글자 수)
$config['name_max']     = 40;
$config['body_max']     = 2000;
$config['keywords_max'] = 300;

// 파일 등록 (CSV)
$config['import_max_rows']  = 1000;
$config['import_max_bytes'] = 5 * 1024 * 1024;

// 내용에 넣을 수 있는 변수. 저장은 영문 토큰, 화면에서만 이름으로 보여준다. 값은 콘솔이 불러올 때 채운다.
$config['variables'] = array(
    'visitor_name' => '고객 이름',
    'agent_name'   => '상담원 이름',
    'org_name'     => '조직 이름',
);

// 추천 색인 가중치. word = 낱말 그대로, gram = 한글 2글자 조각. 낱말 처리는 Guide_matcher 와 같다.
$config['weights'] = array(
    'name'     => array('word' => 8,  'gram' => 2),
    'keywords' => array('word' => 10, 'gram' => 3),
    'body'     => array('word' => 1,  'gram' => 0),
);

// 한 매크로에서 색인하는 낱말 수 상한
$config['max_terms'] = 200;

// 추천 기준 점수와 개수. 고객 마지막 메시지로 찾는다.
$config['suggest_min_score'] = 6;
$config['suggest_limit']     = 3;
