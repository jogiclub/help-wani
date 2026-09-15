<?php
/**
 * 파일 위치: application/views/home/landing.php
 * 역할: 서비스 소개(홍보) 페이지 본문
 *
 * 문구는 assets/lang/{locale}.json 에서 가져온다. 방문자가 헤더에서 고른 언어가 적용된다.
 * 주의: 실제로 제공하는 기능만 담는다. 도입 실적, 인증, 고객사 로고 등은
 *       확인된 사실이 생기면 추가한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$unit = lang_text('home.pricing.currency_unit');
?>

<!-- 히어로 -->
<section class="relative overflow-hidden bg-slate-900">
  <div class="absolute inset-0 bg-[radial-gradient(60%_60%_at_70%_20%,rgba(31,111,235,0.35),transparent)]"></div>

  <div class="relative mx-auto max-w-6xl px-4 py-20 sm:py-28">
    <div class="grid items-center gap-12 lg:grid-cols-2">
      <div>
        <span class="badge badge-blue"><?= html_escape(lang_text('home.hero.badge')) ?></span>

        <h1 class="mt-4 text-3xl font-bold leading-tight text-white sm:text-4xl lg:text-5xl">
          <?= html_escape(lang_text('home.hero.title1')) ?><br>
          <span class="text-brand-500"><?= html_escape(lang_text('home.hero.title2')) ?></span>
          <?= html_escape(lang_text('home.hero.title3')) ?><br>
          <?= html_escape(lang_text('home.hero.title4')) ?>
        </h1>

        <p class="mt-5 max-w-lg text-base leading-relaxed text-slate-300">
          <?= html_escape(lang_text('home.hero.body')) ?>
        </p>

        <div class="mt-8 flex flex-wrap gap-3">
          <a class="btn btn-primary btn-lg" href="<?= base_url('signup') ?>">
            <?= html_escape(lang_text('home.hero.cta')) ?>
            <span class="material-symbols-outlined icon-sm"><?= $icons['misc']['arrow'] ?></span>
          </a>
          <a class="btn btn-lg border border-slate-600 text-slate-200 hover:bg-slate-800" href="#how">
            <?= html_escape(lang_text('home.hero.cta2')) ?>
          </a>
        </div>

        <p class="mt-4 text-sm text-slate-400">
          <span class="font-semibold text-slate-200"><?= html_escape(lang_text('home.hero.price', array(
              'price'    => number_format($pricing['price']),
              'accounts' => $pricing['accounts'],
          ))) ?></span>
          · <a class="underline hover:text-white" href="#pricing"><?= html_escape(lang_text('home.hero.price_link')) ?></a>
        </p>
      </div>

      <!-- 연결 흐름 요약 -->
      <div class="rounded-xl border border-slate-700 bg-slate-800/60 p-6 backdrop-blur">
        <div class="mb-4 text-sm font-semibold text-slate-300"><?= html_escape(lang_text('home.flow.title')) ?></div>

        <ol class="space-y-4">
          <?php for ($i = 1; $i <= 3; $i++): ?>
          <li class="flex gap-3">
            <span class="icon-tile icon-tile-dark shrink-0">
              <span class="material-symbols-outlined icon-md"><?= $icons['flow'][$i] ?></span>
            </span>
            <div>
              <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-brand-400"><?= $i ?></span>
                <span class="font-medium text-white"><?= html_escape(lang_text('home.flow.step'.$i.'.title')) ?></span>
              </div>
              <div class="text-sm text-slate-400"><?= html_escape(lang_text('home.flow.step'.$i.'.body')) ?></div>
            </div>
          </li>
          <?php endfor; ?>
        </ol>

        <div class="mt-6 rounded-lg bg-slate-900/70 p-4">
          <div class="code-display text-center text-3xl text-brand-500">418 305</div>
          <div class="mt-1 text-center text-xs text-slate-500"><?= html_escape(lang_text('home.flow.sample')) ?></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- 핵심 가치 -->
<section class="border-b border-slate-200 bg-white">
  <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:grid-cols-2 lg:grid-cols-3">
    <?php for ($i = 1; $i <= 6; $i++): ?>
    <div class="flex gap-4">
      <span class="icon-tile icon-tile-brand">
        <span class="material-symbols-outlined icon-lg"><?= $icons['value'][$i] ?></span>
      </span>
      <div>
        <div class="text-lg font-bold text-slate-900"><?= html_escape(lang_text('home.value.'.$i.'.title')) ?></div>
        <p class="mt-1 text-sm leading-relaxed text-slate-500"><?= html_escape(lang_text('home.value.'.$i.'.body')) ?></p>
      </div>
    </div>
    <?php endfor; ?>
  </div>
</section>

<!-- 이용 방법 -->
<section id="how" class="bg-slate-50">
  <div class="mx-auto max-w-6xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900"><?= html_escape(lang_text('home.how.title')) ?></h2>
    <p class="mt-2 text-center text-sm text-slate-500"><?= html_escape(lang_text('home.how.subtitle')) ?></p>

    <div class="mt-10 grid gap-6 md:grid-cols-3">
      <?php for ($i = 1; $i <= 3; $i++): ?>
      <div class="card transition-shadow hover:shadow-md">
        <div class="card-body">
          <div class="flex items-center gap-3">
            <span class="step-badge"><?= $i ?></span>
            <span class="icon-tile icon-tile-brand">
              <span class="material-symbols-outlined icon-lg"><?= $icons['how'][$i] ?></span>
            </span>
          </div>
          <div class="mt-3 font-semibold text-slate-800"><?= html_escape(lang_text('home.how.'.$i.'.title')) ?></div>
          <p class="mt-2 text-sm leading-relaxed text-slate-500"><?= html_escape(lang_text('home.how.'.$i.'.body')) ?></p>
          <?php if ($i === 1): ?>
          <code class="mt-3 block rounded bg-slate-100 px-2 py-1 text-xs text-slate-600">
            <?= base_url() ?><?= html_escape(lang_text('home.how.1.sample')) ?>
          </code>
          <?php endif; ?>
        </div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<!-- 기능 -->
<section id="features" class="bg-white">
  <div class="mx-auto max-w-6xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900"><?= html_escape(lang_text('home.features.title')) ?></h2>
    <p class="mt-2 text-center text-sm text-slate-500"><?= html_escape(lang_text('home.features.subtitle')) ?></p>

    <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <?php for ($i = 1; $i <= 9; $i++): ?>
      <div class="card h-full transition-shadow hover:shadow-md">
        <div class="card-body">
          <span class="icon-tile icon-tile-brand">
            <span class="material-symbols-outlined icon-lg"><?= $icons['features'][$i] ?></span>
          </span>
          <div class="mt-3 font-semibold text-slate-800"><?= html_escape(lang_text('home.features.'.$i.'.title')) ?></div>
          <p class="mt-2 text-sm leading-relaxed text-slate-500"><?= html_escape(lang_text('home.features.'.$i.'.body')) ?></p>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <p class="mt-8 text-center text-xs text-slate-400"><?= html_escape(lang_text('home.features.note')) ?></p>
  </div>
</section>

<!-- 보안 -->
<section id="security" class="bg-slate-900">
  <div class="mx-auto max-w-6xl px-4 py-16">
    <div class="grid gap-10 lg:grid-cols-2">
      <div>
        <h2 class="text-2xl font-bold text-white"><?= html_escape(lang_text('home.security.title')) ?></h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-300"><?= html_escape(lang_text('home.security.body')) ?></p>

        <div class="mt-6 rounded-lg border border-red-500/30 bg-red-500/10 p-4">
          <div class="flex items-center gap-2 font-semibold text-red-300">
            <span class="material-symbols-outlined icon-md"><?= $icons['misc']['phishing'] ?></span>
            <?= html_escape(lang_text('home.security.phishing.title')) ?>
          </div>
          <p class="mt-1 text-sm leading-relaxed text-red-100/80">
            <?= html_escape(lang_text('home.security.phishing.body')) ?>
          </p>
        </div>
      </div>

      <div class="space-y-4">
        <?php for ($i = 1; $i <= 6; $i++): ?>
        <div class="flex gap-3 rounded-lg border border-slate-700 bg-slate-800/60 p-4">
          <span class="icon-tile icon-tile-dark">
            <span class="material-symbols-outlined icon-md"><?= $icons['security'][$i] ?></span>
          </span>
          <div>
            <div class="font-medium text-white"><?= html_escape(lang_text('home.security.'.$i.'.title')) ?></div>
            <p class="mt-1 text-sm leading-relaxed text-slate-400"><?= html_escape(lang_text('home.security.'.$i.'.body')) ?></p>
          </div>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
</section>

<!-- 요금 -->
<section id="pricing" class="bg-white">
  <div class="mx-auto max-w-4xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900"><?= html_escape(lang_text('home.pricing.title')) ?></h2>
    <p class="mt-2 text-center text-sm text-slate-500"><?= html_escape(lang_text('home.pricing.subtitle')) ?></p>

    <div class="mx-auto mt-10 max-w-md">
      <div class="card border-2 border-brand-500 shadow-lg">
        <div class="card-body text-center">
          <div class="badge badge-blue"><?= html_escape(lang_text('home.pricing.badge')) ?></div>

          <div class="mt-4">
            <span class="text-4xl font-bold text-slate-900">
              <?= number_format($pricing['price']) ?><?= html_escape($unit) ?>
            </span>
            <span class="text-slate-500"> / <?= html_escape(lang_text('home.pricing.period', array('months' => $pricing['months']))) ?></span>
          </div>

          <div class="mt-1 text-sm text-slate-500">
            <?= html_escape(lang_text('home.pricing.includes', array('accounts' => $pricing['accounts']))) ?>
          </div>

          <div class="mt-4 rounded-lg bg-slate-50 py-3 text-sm text-slate-600">
            <span class="font-semibold text-slate-800">
              <?= html_escape(lang_text('home.pricing.per_account', array('year' => number_format($pricing['per_account_year'])))) ?>
            </span>
            <span class="text-slate-400">
              <?= html_escape(lang_text('home.pricing.per_month', array('month' => number_format($pricing['per_account_month'])))) ?>
            </span>
          </div>

          <ul class="mt-6 space-y-2 text-left text-sm text-slate-600">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <li class="flex items-start gap-2">
              <span class="material-symbols-outlined icon-sm mt-0.5 text-brand-500"><?= $icons['misc']['check'] ?></span>
              <span><?= html_escape(lang_text('home.pricing.item'.$i, array('accounts' => $pricing['accounts']))) ?></span>
            </li>
            <?php endfor; ?>
          </ul>

          <a class="btn btn-primary btn-lg mt-7 w-full" href="<?= base_url('signup') ?>">
            <?= html_escape(lang_text('home.pricing.cta')) ?>
          </a>

          <p class="mt-3 text-xs text-slate-400"><?= html_escape(lang_text('home.pricing.note')) ?></p>
        </div>
      </div>

      <?php if ( ! empty($contact_email) || ! empty($contact_phone)): ?>
      <div class="mt-6 text-center text-sm text-slate-500">
        <?= html_escape(lang_text('home.pricing.contact')) ?>
        <?php if ( ! empty($contact_phone)): ?>
          <span class="inline-flex items-center gap-1 font-medium text-slate-700">
            <span class="material-symbols-outlined icon-sm"><?= $icons['misc']['call'] ?></span>
            <?= html_escape($contact_phone) ?>
          </span>
        <?php endif; ?>
        <?php if ( ! empty($contact_email)): ?>
          <a class="inline-flex items-center gap-1 font-medium text-brand-600 hover:underline"
             href="mailto:<?= html_escape($contact_email) ?>">
            <span class="material-symbols-outlined icon-sm"><?= $icons['misc']['mail'] ?></span>
            <?= html_escape($contact_email) ?>
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- 자주 묻는 질문 -->
<section id="faq" class="bg-slate-50">
  <div class="mx-auto max-w-4xl px-4 py-16">
    <h2 class="text-center text-2xl font-bold text-slate-900"><?= html_escape(lang_text('home.faq.title')) ?></h2>

    <div class="mt-8 space-y-3">
      <?php for ($i = 1; $i <= 9; $i++): ?>
      <details class="card group">
        <summary class="card-body flex cursor-pointer items-center justify-between font-medium text-slate-800">
          <?= html_escape(lang_text('home.faq.'.$i.'.q')) ?>
          <span class="material-symbols-outlined icon-md shrink-0 text-slate-400 transition-transform group-open:rotate-180"><?= $icons['misc']['expand'] ?></span>
        </summary>
        <div class="border-t border-slate-100 px-5 py-4 text-sm leading-relaxed text-slate-500">
          <?= html_escape(lang_text('home.faq.'.$i.'.a', array('accounts' => $pricing['accounts']))) ?>
        </div>
      </details>
      <?php endfor; ?>
    </div>
  </div>
</section>

<!-- 마무리 CTA -->
<section class="bg-brand-500">
  <div class="mx-auto max-w-4xl px-4 py-14 text-center">
    <h2 class="text-2xl font-bold text-white"><?= html_escape(lang_text('home.cta.title')) ?></h2>
    <p class="mt-3 text-sm text-blue-100"><?= html_escape(lang_text('home.cta.body')) ?></p>
    <a class="btn btn-lg mt-6 bg-white text-brand-600 hover:bg-blue-50" href="<?= base_url('signup') ?>">
      <?= html_escape(lang_text('home.cta.button')) ?>
      <span class="material-symbols-outlined icon-sm"><?= $icons['misc']['arrow'] ?></span>
    </a>
  </div>
</section>
