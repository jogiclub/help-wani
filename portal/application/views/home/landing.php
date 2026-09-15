<?php
/**
 * 파일 위치: application/views/home/landing.php
 * 역할: 서비스 소개(홍보) 페이지 본문
 *
 * 주의: 이 페이지의 문구는 실제로 제공하는 기능만 담는다.
 *       도입 실적, 인증, 고객사 로고 등은 확인된 사실이 생기면 추가한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>

<!-- 히어로 -->
<section class="relative overflow-hidden bg-slate-900">
  <div class="absolute inset-0 bg-[radial-gradient(60%_60%_at_70%_20%,rgba(31,111,235,0.35),transparent)]"></div>

  <div class="relative mx-auto max-w-6xl px-4 py-20 sm:py-28">
    <div class="grid items-center gap-12 lg:grid-cols-2">
      <div>
        <span class="badge badge-blue">기업용 원격지원</span>

        <h1 class="mt-4 text-3xl font-bold leading-tight text-white sm:text-4xl lg:text-5xl">
          전화 통화 중에<br>
          <span class="text-brand-500">6자리 코드</span> 하나로<br>
          고객 화면을 봅니다
        </h1>

        <p class="mt-5 max-w-lg text-base leading-relaxed text-slate-300">
          고객은 앱을 실행해 코드를 입력하고, 상담원은 브라우저만 열면 됩니다.
          설치 절차도, 방화벽 설정도, 원격 프로그램 관리도 필요 없습니다.
        </p>

        <div class="mt-8 flex flex-wrap gap-3">
          <a class="btn btn-primary btn-lg" href="<?= base_url('signup') ?>">도입 문의하기</a>
          <a class="btn btn-lg border border-slate-600 text-slate-200 hover:bg-slate-800" href="#how">이용 방법 보기</a>
        </div>

        <p class="mt-4 text-sm text-slate-400">
          <span class="font-semibold text-slate-200">연 <?= number_format($pricing['price']) ?>원</span>
          · 상담원 계정 <?= (int) $pricing['accounts'] ?>개 포함
          · <a class="underline hover:text-white" href="#pricing">요금 자세히 보기</a>
        </p>
      </div>

      <!-- 연결 흐름 요약 -->
      <div class="rounded-xl border border-slate-700 bg-slate-800/60 p-6 backdrop-blur">
        <div class="mb-4 text-sm font-semibold text-slate-300">연결 흐름</div>

        <ol class="space-y-4">
          <li class="flex gap-3">
            <span class="step-badge shrink-0">1</span>
            <div>
              <div class="font-medium text-white">상담원이 코드 발급</div>
              <div class="text-sm text-slate-400">콘솔에서 버튼 한 번. 유효시간 10분, 1회용입니다.</div>
            </div>
          </li>
          <li class="flex gap-3">
            <span class="step-badge shrink-0">2</span>
            <div>
              <div class="font-medium text-white">고객이 코드 입력</div>
              <div class="text-sm text-slate-400">전용 안내 페이지에서 앱을 받아 실행하고 코드를 넣습니다.</div>
            </div>
          </li>
          <li class="flex gap-3">
            <span class="step-badge shrink-0">3</span>
            <div>
              <div class="font-medium text-white">고객이 허용하면 연결</div>
              <div class="text-sm text-slate-400">동의 없이는 절대 시작되지 않습니다.</div>
            </div>
          </li>
        </ol>

        <div class="mt-6 rounded-lg bg-slate-900/70 p-4">
          <div class="code-display text-center text-3xl text-brand-500">418 305</div>
          <div class="mt-1 text-center text-xs text-slate-500">고객에게 불러주는 코드 예시</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- 핵심 가치 -->
<section class="border-b border-slate-200 bg-white">
  <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:grid-cols-3">
    <div>
      <div class="text-2xl font-bold text-slate-900">설치 부담 없음</div>
      <p class="mt-2 text-sm leading-relaxed text-slate-500">
        고객 PC 에는 상담이 끝나면 아무것도 남지 않습니다.
        상담원 PC 에는 설치할 것이 아예 없습니다.
      </p>
    </div>
    <div>
      <div class="text-2xl font-bold text-slate-900">브라우저로 제어</div>
      <p class="mt-2 text-sm leading-relaxed text-slate-500">
        상담원은 크롬, 엣지 등 표준 브라우저에서 바로 고객 화면을 보고 조작합니다.
      </p>
    </div>
    <div>
      <div class="text-2xl font-bold text-slate-900">443 포트 하나</div>
      <p class="mt-2 text-sm leading-relaxed text-slate-500">
        고객사 방화벽에 별도 포트를 열 필요가 없습니다. 일반 웹 트래픽과 같은 경로를 씁니다.
      </p>
    </div>
  </div>
</section>

<!-- 이용 방법 -->
<section id="how" class="bg-slate-50">
  <div class="mx-auto max-w-6xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900">이용 방법</h2>
    <p class="mt-2 text-center text-sm text-slate-500">고객이 해야 할 일은 세 가지뿐입니다.</p>

    <div class="mt-10 grid gap-6 md:grid-cols-3">
      <div class="card">
        <div class="card-body">
          <span class="step-badge">1</span>
          <div class="mt-3 font-semibold text-slate-800">전용 페이지 접속</div>
          <p class="mt-2 text-sm leading-relaxed text-slate-500">
            조직마다 전용 주소가 발급됩니다. 고객은 상담원이 불러주는 주소로 들어갑니다.
          </p>
          <code class="mt-3 block rounded bg-slate-100 px-2 py-1 text-xs text-slate-600">
            <?= base_url() ?>회사이름
          </code>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <span class="step-badge">2</span>
          <div class="mt-3 font-semibold text-slate-800">앱 실행</div>
          <p class="mt-2 text-sm leading-relaxed text-slate-500">
            Microsoft Store 에서 설치하거나 실행 파일을 바로 내려받습니다.
            관리자 권한도, 재부팅도 필요 없습니다.
          </p>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <span class="step-badge">3</span>
          <div class="mt-3 font-semibold text-slate-800">코드 입력 후 허용</div>
          <p class="mt-2 text-sm leading-relaxed text-slate-500">
            6자리 코드를 넣고 [허용] 을 누르면 상담원 화면에 고객 PC 가 나타납니다.
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- 기능 -->
<section id="features" class="bg-white">
  <div class="mx-auto max-w-6xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900">기능</h2>
    <p class="mt-2 text-center text-sm text-slate-500">상담에 필요한 것만 담았습니다.</p>

    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <?php
      $features = array(
          array('화면 보기와 원격 제어', '고객 화면을 실시간으로 보고 마우스와 키보드를 직접 조작합니다. 바뀐 영역만 전송해 느린 회선에서도 쓸 만합니다.'),
          array('상담원 웹 콘솔', '설치 없이 브라우저에서 대기열 확인, 코드 발급, 원격 제어까지 처리합니다.'),
          array('조직 전용 접속 페이지', '회사 이름과 로고가 들어간 고객 안내 페이지를 조직마다 제공합니다.'),
          array('클립보드 전달', '긴 주소나 인증번호를 고객 PC 로 바로 붙여 넣을 수 있습니다.'),
          array('상담 메모와 이력', '원격 중 남긴 메모가 세션 기록에 함께 저장되어 나중에 검색할 수 있습니다.'),
          array('만족도 조사', '상담이 끝나면 고객에게 별점과 의견을 받아 통계로 확인합니다.'),
          array('화질 조절', '회선 상태에 맞춰 화질과 프레임을 상담원이 직접 조절합니다.'),
          array('멀티 모니터와 고해상도', '여러 대의 모니터를 쓰는 고객 PC 도 전체 화면으로 확인합니다.'),
          array('다국어와 시간대', '조직마다 화면 언어(한국어·영어·일본어)와 시간대를 설정합니다.'),
      );

      foreach ($features as $f):
      ?>
      <div class="card h-full">
        <div class="card-body">
          <div class="font-semibold text-slate-800"><?= html_escape($f[0]) ?></div>
          <p class="mt-2 text-sm leading-relaxed text-slate-500"><?= html_escape($f[1]) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <p class="mt-8 text-center text-xs text-slate-400">
      파일 전송, 세션 녹화, 모바일 기기 지원은 준비 중입니다.
    </p>
  </div>
</section>

<!-- 보안 -->
<section id="security" class="bg-slate-900">
  <div class="mx-auto max-w-6xl px-4 py-16">
    <div class="grid gap-10 lg:grid-cols-2">
      <div>
        <h2 class="text-2xl font-bold text-white">보안과 고객 보호</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-300">
          원격지원은 고객 PC 를 직접 다루는 일입니다.
          몰래 연결되거나, 끝난 뒤에도 남아 있거나, 고객이 끊을 수 없는 상황이 없도록 설계했습니다.
        </p>

        <div class="mt-6 rounded-lg border border-red-500/30 bg-red-500/10 p-4">
          <div class="font-semibold text-red-300">보이스피싱 예방</div>
          <p class="mt-1 text-sm leading-relaxed text-red-100/80">
            고객 안내 페이지와 앱 첫 화면에 주의 문구를 항상 노출합니다.
            금융기관이나 수사기관은 원격제어를 요구하지 않는다는 점을 고객이 반복해서 확인하게 됩니다.
          </p>
        </div>
      </div>

      <div class="space-y-4">
        <?php
        $security = array(
            array('전 구간 암호화', '고객 PC 부터 상담원 브라우저까지 TLS 로 암호화됩니다. 평문으로 오가는 구간이 없습니다.'),
            array('1회용 코드와 토큰', '접속 코드는 10분 후 만료되고 한 번 쓰면 재사용할 수 없습니다. 상담원 접속 토큰도 1회용입니다.'),
            array('명시적 동의', '고객이 [허용] 을 누르기 전에는 연결이 시작되지 않습니다. 자동 접속 기능은 제공하지 않습니다.'),
            array('상시 표시와 즉시 종료', '원격 중에는 화면 오른쪽 위에 표시창이 계속 떠 있고, 고객이 언제든 종료할 수 있습니다.'),
            array('잔류 없음', '상담이 끝나면 임시 파일을 지우고 상주 프로그램이나 서비스를 남기지 않습니다.'),
            array('기록', '접속 시각, 담당 상담원, 종료 사유가 모두 기록되어 나중에 확인할 수 있습니다.'),
        );

        foreach ($security as $item):
        ?>
        <div class="rounded-lg border border-slate-700 bg-slate-800/60 p-4">
          <div class="font-medium text-white"><?= html_escape($item[0]) ?></div>
          <p class="mt-1 text-sm leading-relaxed text-slate-400"><?= html_escape($item[1]) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- 요금 -->
<section id="pricing" class="bg-white">
  <div class="mx-auto max-w-4xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900">요금</h2>
    <p class="mt-2 text-center text-sm text-slate-500">단순한 연간 요금 하나입니다. 숨은 비용이 없습니다.</p>

    <div class="mx-auto mt-10 max-w-md">
      <div class="card border-2 border-brand-500 shadow-lg">
        <div class="card-body text-center">
          <div class="badge badge-blue">연간 이용권</div>

          <div class="mt-4">
            <span class="text-4xl font-bold text-slate-900">
              <?= number_format($pricing['price']) ?>원
            </span>
            <span class="text-slate-500"> / <?= (int) $pricing['months'] ?>개월</span>
          </div>

          <div class="mt-1 text-sm text-slate-500">
            상담원 계정 <?= (int) $pricing['accounts'] ?>개 포함
          </div>

          <div class="mt-4 rounded-lg bg-slate-50 py-3 text-sm text-slate-600">
            계정 1개당 <span class="font-semibold text-slate-800">연 <?= number_format($pricing['per_account_year']) ?>원</span>
            <span class="text-slate-400">(월 약 <?= number_format($pricing['per_account_month']) ?>원)</span>
          </div>

          <ul class="mt-6 space-y-2 text-left text-sm text-slate-600">
            <?php foreach (array(
                '상담원 계정 '.$pricing['accounts'].'개',
                '원격 세션 수 제한 없음',
                '조직 전용 고객 접속 페이지',
                '상담 이력, 메모, 만족도 조사, 통계',
                '중계 서버 사용료 포함',
                '기능 제한 없음 — 모든 기능 그대로',
            ) as $item): ?>
            <li class="flex gap-2">
              <span class="text-brand-500">✓</span>
              <span><?= html_escape($item) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>

          <a class="btn btn-primary btn-lg mt-7 w-full" href="<?= base_url('signup') ?>">가입 신청하기</a>

          <p class="mt-3 text-xs text-slate-400">
            계정 수를 더 늘리거나 다른 조건이 필요하시면 문의해 주세요.
          </p>
        </div>
      </div>

      <?php if ( ! empty($contact_email) || ! empty($contact_phone)): ?>
      <div class="mt-6 text-center text-sm text-slate-500">
        도입 상담:
        <?php if ( ! empty($contact_phone)): ?>
          <span class="font-medium text-slate-700"><?= html_escape($contact_phone) ?></span>
        <?php endif; ?>
        <?php if ( ! empty($contact_email)): ?>
          <a class="font-medium text-brand-600 hover:underline"
             href="mailto:<?= html_escape($contact_email) ?>"><?= html_escape($contact_email) ?></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- 자주 묻는 질문 -->
<section id="faq" class="bg-slate-50">
  <div class="mx-auto max-w-4xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900">자주 묻는 질문</h2>

    <div class="mt-8 space-y-3">
      <?php
      $faqs = array(
          array('고객 PC 에 프로그램이 설치되나요?',
                '설치 과정 없이 실행 파일을 한 번 실행하는 방식입니다. 상담이 끝나면 임시 파일을 지우고 아무것도 남기지 않습니다. Microsoft Store 에서 앱으로 설치할 수도 있습니다.'),
          array('상담원 PC 에는 무엇을 설치하나요?',
                '아무것도 설치하지 않습니다. 브라우저에서 로그인해 바로 사용합니다.'),
          array('방화벽에 포트를 열어야 하나요?',
                '아니요. 일반 웹 트래픽과 같은 443 포트만 사용합니다. 고객 PC 가 밖으로 연결하는 방식이라 고객 측 설정도 필요 없습니다.'),
          array('고객 동의 없이 접속할 수 있나요?',
                '없습니다. 매 상담마다 고객이 코드를 직접 입력하고 [허용] 을 눌러야 시작됩니다. 재부팅 후 자동 접속 같은 기능은 제공하지 않습니다.'),
          array('UAC(관리자 권한) 창도 제어할 수 있나요?',
                '일반 권한으로 실행하면 윈도우 정책상 UAC 동의 창은 제어할 수 없습니다. 이 경우 상담원이 고객에게 구두로 안내하는 방식으로 진행합니다.'),
          array('어떤 운영체제를 지원하나요?',
                '고객 PC 는 Windows 10 이상을 지원합니다. 상담원은 최신 브라우저가 설치된 환경이면 운영체제를 가리지 않습니다. macOS 와 모바일 지원은 준비 중입니다.'),
          array('요금은 어떻게 계산되나요?',
                '연간 이용권 하나로 상담원 계정 5개를 쓰실 수 있습니다. 원격 세션 수나 상담 시간에 따른 추가 비용은 없습니다. 계정이 더 필요하시면 문의해 주세요.'),
          array('상담 기록은 얼마나 보관되나요?',
                '접속 시각, 담당 상담원, 상담 메모, 만족도가 기록으로 남습니다. 보관 기간은 도입 시 협의해 설정합니다.'),
      );

      foreach ($faqs as $faq):
      ?>
      <details class="card group">
        <summary class="card-body flex cursor-pointer items-center justify-between font-medium text-slate-800">
          <?= html_escape($faq[0]) ?>
          <span class="text-slate-400 group-open:rotate-180">⌄</span>
        </summary>
        <div class="border-t border-slate-100 px-5 py-4 text-sm leading-relaxed text-slate-500">
          <?= html_escape($faq[1]) ?>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- 마무리 CTA -->
<section class="bg-brand-500">
  <div class="mx-auto max-w-4xl px-4 py-14 text-center">
    <h2 class="text-2xl font-bold text-white">전화로 설명하기 어려운 문제, 직접 보고 해결하세요</h2>
    <p class="mt-3 text-sm text-blue-100">
      가입 신청 후 운영자 승인을 거치면 바로 상담을 시작할 수 있습니다.
    </p>
    <a class="btn btn-lg mt-6 bg-white text-brand-600 hover:bg-blue-50" href="<?= base_url('signup') ?>">
      도입 문의하기
    </a>
  </div>
</section>
