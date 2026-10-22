<?php
/**
 * 파일 위치: application/views/layouts/main.php
 * 역할: 조직 소속 사용자(상담원, 조직 관리자) 레이아웃
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$is_super = (bool) $this->session->userdata('is_super');

// 상단 메뉴. 순서는 가이드, 상담 대화, 원격지원. 조직 관리 하위 화면(상담원 관리, 조직 정보)도 관리자에게는 여기 바로 둔다.
// 상담 위젯 설정은 상담 대화 화면의 설정 드로어에 있다.
// 조직 관리(/admin 통계) 자체는 메뉴에 두지 않고 왼쪽 위 서비스 이름을 누르면 열린다.
// [주소, 이름, 현재 화면 여부]. '|' 는 구분선.
$uri  = trim(uri_string(), '/');
$here = function ($path, $exact = FALSE) use ($uri) {
    return $exact ? $uri === $path : ($uri === $path || strpos($uri, $path.'/') === 0);
};
$is_admin = $this->session->userdata('agent_role') === 'admin';
// 상담원은 /admin 에 들어갈 수 없으므로 원격지원으로 보낸다.
$home_url = $is_admin ? 'admin' : 'console';
$menu = array();
// 가이드는 관리자만 고칠 수 있으므로 관리자에게만 맨 앞에 둔다.
if ($is_admin)
{
    $menu[] = array('admin/guide', '가이드', $here('admin/guide'));
}
$menu[] = array('console/chat', '상담 대화', $here('console/chat'));
// console/session/{id} (원격 화면)은 원격지원에서 들어가므로 원격지원에 불을 켠다.
$menu[] = array('console', '원격지원', $uri === 'console' || strpos($uri, 'console/session') === 0);
if ($is_admin)
{
    $menu[] = '|';
    $menu[] = array('admin/agents', '상담원 관리', $here('admin/agents'));
    $menu[] = array('admin/organization', '조직 정보', $here('admin/organization'));
}
?>
<!doctype html>
<html lang="<?= html_escape(isset($i18n) ? $i18n["locale"] : "ko") ?>">
<head>
<?php $this->load->view('layouts/_head', array(
    'use_grid'     => isset($use_grid) ? $use_grid : FALSE,
    'use_dropzone' => isset($use_dropzone) ? $use_dropzone : FALSE,
)); ?>
</head>
<body class="min-h-screen">

<header class="bg-slate-900 text-white">
  <div class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3">
    <a class="text-lg font-bold" href="<?= base_url($home_url) ?>"
       <?= $is_admin ? 'title="조직 관리"' : '' ?>><?= html_escape($app_name) ?></a>

    <nav class="hidden flex-wrap gap-1 sm:flex">
      <?php foreach ($menu as $item): ?>
        <?php if ($item === '|'): ?>
          <span class="mx-1 my-1.5 w-px bg-slate-700" aria-hidden="true"></span>
        <?php else: ?>
          <a class="rounded px-3 py-1.5 text-sm <?= $item[2] ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>"
             href="<?= base_url($item[0]) ?>"<?= $item[2] ? ' aria-current="page"' : '' ?>><?= $item[1] ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="ml-auto flex items-center gap-3">
      <span class="hidden text-sm text-slate-300 md:inline">
        <?= html_escape($this->session->userdata('org_name')) ?> ·
        <?= html_escape($this->session->userdata('agent_name')) ?>
      </span>
      <?php if ($is_super): ?>
      <!-- 운영자 계정만 보이는 콘솔 전환 링크. 메뉴 자체는 분리되어 있다. -->
      <a class="btn btn-sm border border-amber-400/60 text-amber-300 hover:bg-amber-400/10"
         href="<?= base_url('operator') ?>">운영자 콘솔</a>
      <?php endif; ?>
      <a class="btn btn-sm border border-slate-600 text-slate-200 hover:bg-slate-800"
         href="<?= base_url('logout') ?>">로그아웃</a>
    </div>
  </div>
</header>

<main class="mx-auto <?= isset($fluid) && $fluid ? 'max-w-full' : 'max-w-7xl' ?> px-4 py-6">
<?php $this->load->view($content_view); ?>
</main>

<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
