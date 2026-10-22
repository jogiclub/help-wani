<?php
/**
 * 파일 위치: application/views/guide/_video.php
 * 역할: 유튜브 동영상 하나 (본문 블록과 동영상 모음 공통). 유튜브 iframe 은 이 파일에서만 만든다.
 *
 * 저장된 영상 id($yt_id, 11자)로만 주소를 만들고 처음부터 iframe(youtube-nocookie.com/embed/, 개인정보 보호 강화 모드)으로 그린다.
 * 유튜브가 Referer 를 요구하므로 strict-origin-when-cross-origin 을 둔다(CLAUDE.md 3.15).
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<iframe class="guide-video aspect-video w-full rounded-lg bg-slate-900"
        src="https://www.youtube-nocookie.com/embed/<?= html_escape($yt_id) ?>?rel=0"
        title="<?= html_escape($yt_title !== '' ? $yt_title : lang_text('guide.video_play')) ?>"
        loading="lazy" referrerpolicy="strict-origin-when-cross-origin"
        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen></iframe>
