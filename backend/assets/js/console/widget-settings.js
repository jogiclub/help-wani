/**
 * 파일 위치: assets/js/console/widget-settings.js
 * 역할: 상담 대화 > 설정 드로어 (탭 전환, 스니펫 복사/키 재발급, 인사말 변수·바로가기 버튼·미리보기, 요일별 운영 시간 편집,
 *       휴일 추가/삭제/공휴일 불러오기, 답변 속도·전화 버튼 설정, 연락처 카드·팔로업 메일 설정, 저장)
 *
 * 뷰(templates/console/_widget_settings.html)가 WIDGET_URLS, WIDGET_HOURS, WIDGET_HOLIDAYS, WIDGET_OPEN_SETTINGS, WIDGET_GREETING 을 넘긴다.
 * 화면은 Bootstrap 6 + 왔니(<dialog class="drawer">). JS 로 만드는 요소의 클래스도 Bootstrap 6 이름이며, 버튼·배지는 RHUi 톤 이름으로 고른다.
 * 숨기기는 d-none 이다(Tailwind 의 hidden 이 아님).
 * 운영 시간과 휴일 시각은 조직 시간대의 벽시계 값('HH:MM')이다(docs/chat-operation-plan.md).
 */
(function ($) {
    'use strict';

    /** 화면 순서는 월요일부터. 값은 0=일 ... 6=토 */
    var WEEK = [[1, '월'], [2, '화'], [3, '수'], [4, '목'], [5, '금'], [6, '토'], [0, '일']];
    var MAX_RANGES = 3;
    /** 운영 시간이 비어 있을 때 처음 보여 줄 값 */
    var DEFAULT_RANGE = ['09:00', '18:00'];

    var hours = {};
    var holidays = [];
    var greetingButtons = [];

    /** 인사말 바로가기 버튼 수 (서버 Chat_greeting::BUTTON_MAX 와 같다) */
    var GREETING_BUTTON_MAX = 3;

    // ------------------------------------------------------------------
    // 시간 선택
    // ------------------------------------------------------------------

    /** 00:00 ~ 24:00 를 30분 단위로. 저장된 값이 30분 단위가 아니면 그 값도 넣는다. */
    function timeOptions(selected) {
        var list = [];

        for (var m = 0; m <= 1440; m += 30) {
            list.push(pad(Math.floor(m / 60)) + ':' + pad(m % 60));
        }

        if (selected && list.indexOf(selected) < 0) {
            list.push(selected);
            list.sort();
        }

        return list.map(function (t) {
            return '<option value="' + t + '"' + (t === selected ? ' selected' : '') + '>' + t + '</option>';
        }).join('');
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    // ------------------------------------------------------------------
    // 요일별 운영 시간
    // ------------------------------------------------------------------

    function renderHours() {
        var $editor = $('#widgetHoursEditor').empty();

        WEEK.forEach(function (day) {
            var weekday = day[0];
            var ranges = hours[weekday] || [];
            var $row = $('<div class="rh-ws-day-row"></div>');
            var checkId = 'widgetHoursDay_' + weekday;

            var $check = $('<div class="form-field rh-ws-day"></div>')
                .append($('<input type="checkbox" class="check">').attr('id', checkId).prop('checked', ranges.length > 0)
                    .on('change', function () {
                        hours[weekday] = this.checked ? [DEFAULT_RANGE.slice()] : [];
                        renderHours();
                    }))
                .append($('<label class="fs-sm fw-medium"></label>').attr('for', checkId).text(day[1]));

            var $ranges = $('<div class="d-flex flex-grow-1 flex-column gap-1 min-w-0"></div>');

            if (!ranges.length) {
                $ranges.append('<span class="rh-ws-closed fs-sm fg-3">휴무</span>');
            }

            ranges.forEach(function (range, index) {
                var $line = $('<div class="d-flex flex-wrap align-items-center gap-2"></div>');

                $line.append($('<select class="form-control form-control-sm w-auto" aria-label="시작"></select>').html(timeOptions(range[0]))
                    .on('change', function () { range[0] = this.value; }));
                $line.append('<span class="fg-3">-</span>');
                $line.append($('<select class="form-control form-control-sm w-auto" aria-label="끝"></select>').html(timeOptions(range[1]))
                    .on('change', function () { range[1] = this.value; }));

                if (index > 0) {
                    $line.append($('<button type="button" title="이 시간대 지우기">빼기</button>').addClass(RHUi.button('ghost'))
                        .on('click', function () {
                            ranges.splice(index, 1);
                            renderHours();
                        }));
                } else if (ranges.length < MAX_RANGES) {
                    $line.append($('<button type="button" title="같은 날 시간대 추가">+ 시간대</button>').addClass(RHUi.button('ghost'))
                        .on('click', function () {
                            var last = ranges[ranges.length - 1];
                            ranges.push([last[1], last[1] < '23:00' ? addHour(last[1]) : '24:00']);
                            renderHours();
                        }));
                }

                $ranges.append($line);
            });

            $row.append($check, $ranges);
            $editor.append($row);
        });
    }

    function addHour(time) {
        var parts = time.split(':');
        return pad(Math.min(24, parseInt(parts[0], 10) + 1)) + ':' + parts[1];
    }

    function copyMondayToWeekdays() {
        var monday = hours[1] || [];

        [2, 3, 4, 5].forEach(function (weekday) {
            hours[weekday] = monday.map(function (range) { return range.slice(); });
        });

        renderHours();
        showToast('월요일 시간을 화-금에 적용했습니다. 저장을 눌러야 반영됩니다.', 'info');
    }

    // ------------------------------------------------------------------
    // 휴일
    // ------------------------------------------------------------------

    function renderHolidays() {
        var $list = $('#widgetHolidayList').empty();

        if (!holidays.length) {
            $list.append('<li class="px-4 py-3 fg-3">등록된 휴일이 없습니다.</li>');
            return;
        }

        holidays.forEach(function (item) {
            var period = item.start_date === item.end_date ? item.start_date : item.start_date + ' ~ ' + item.end_date;

            if (item.repeat_yearly) {
                period = '매년 ' + period.replace(/\d{4}-/g, '');
            }

            var detail = item.mode === 'short' ? '단축 ' + item.start + '-' + item.end : '종일 휴무';
            var $li = $('<li class="d-flex align-items-center gap-3 px-4 py-2"></li>');

            $li.append($('<span class="rh-ws-period flex-shrink-0 font-monospace fs-xs fg-2"></span>').text(period));
            $li.append($('<span class="min-w-0 flex-grow-1 text-truncate fg-1"></span>').text(item.name));
            $li.append($('<span class="flex-shrink-0 fs-xs fg-3"></span>').text(detail));
            $li.append($('<button type="button">삭제</button>').addClass(RHUi.button('danger'))
                .on('click', function () { deleteHoliday(item); }));

            $list.append($li);
        });
    }

    function addHoliday() {
        var mode = $('input[name="holidayMode"]:checked').val();
        var $btn = $('#btnHolidayAdd').prop('disabled', true);

        apiPost(WIDGET_URLS.holidayAdd, {
            start_date: $('#holidayStart').val(),
            end_date: $('#holidayEnd').val(),
            name: $('#holidayName').val(),
            mode: mode,
            start: $('#holidayShortStart').val(),
            end: $('#holidayShortEnd').val(),
            repeat_yearly: $('#holidayRepeat').is(':checked') ? 1 : 0
        }, function (data, message) {
            showToast(message, 'success');
            holidays = data.holidays;
            renderHolidays();
            $('#holidayStart, #holidayEnd, #holidayName').val('');
            $('#holidayRepeat').prop('checked', false);
        }).always(function () { $btn.prop('disabled', false); });
    }

    function deleteHoliday(item) {
        showConfirmModal('휴일 삭제', '"' + item.name + '" 휴일을 지울까요?', function () {
            apiPost(WIDGET_URLS.holidayDelete, { id: item.id }, function (data, message) {
                showToast(message, 'success');
                holidays = data.holidays;
                renderHolidays();
            });
        });
    }

    function loadCandidates() {
        apiGet(WIDGET_URLS.holidayCandidates, function (data) {
            var $list = $('#widgetHolidayCandidateList').empty();

            if (!data.candidates.length) {
                $list.append('<li class="fg-3">조직 국가의 공휴일 목록이 없습니다. 직접 추가해 주세요.</li>');
            }

            data.candidates.forEach(function (item, index) {
                var checkId = 'widgetHolidayCandidate_' + index;
                var $label = $('<label class="d-flex flex-wrap align-items-center gap-3"></label>').attr('for', checkId);
                var $check = $('<input type="checkbox" class="check">')
                    .attr('id', checkId)
                    .data('item', item)
                    .prop('disabled', item.added);

                $label.append($('<span class="font-monospace fs-xs fg-3"></span>').text(item.date));
                $label.append($('<span></span>').text(item.name));

                if (item.added) {
                    $label.append($('<span>추가됨</span>').addClass(RHUi.badge('gray')));
                }

                $list.append($('<li class="form-field"></li>').append($check, $label));
            });

            $('#widgetHolidayCandidates').removeClass('d-none');
        });
    }

    function addCandidates() {
        var items = [];

        $('#widgetHolidayCandidateList input:checked').each(function () {
            var item = $(this).data('item');
            items.push({ date: item.date, name: item.name });
        });

        if (!items.length) {
            showToast('추가할 공휴일을 골라 주세요.', 'info');
            return;
        }

        apiPost(WIDGET_URLS.holidayAdd, { items: JSON.stringify(items) }, function (data, message) {
            showToast(message, 'success');
            holidays = data.holidays;
            renderHolidays();
            $('#widgetHolidayCandidates').addClass('d-none');
        });
    }

    // ------------------------------------------------------------------
    // 인사말 (docs/widget-ops-plan.md 2장)
    // ------------------------------------------------------------------

    function renderGreetingButtons() {
        var $list = $('#widgetGreetingButtons').empty();

        greetingButtons.forEach(function (item, index) {
            var $row = $('<li class="d-flex align-items-center gap-3"></li>');

            $row.append($('<input type="text" class="form-control form-control-sm rh-ws-btn-label" maxlength="30" placeholder="버튼 이름" aria-label="버튼 이름">')
                .val(item.label).on('input', function () { item.label = this.value; renderGreetingPreview(); }));
            $row.append($('<input type="url" class="form-control form-control-sm flex-grow-1 min-w-0" maxlength="500" placeholder="https://" aria-label="버튼 주소">')
                .val(item.url).on('input', function () { item.url = this.value; }));
            $row.append($('<button type="button">삭제</button>').addClass(RHUi.button('ghost'))
                .on('click', function () {
                    greetingButtons.splice(index, 1);
                    renderGreetingButtons();
                }));
            $list.append($row);
        });

        $('#btnGreetingButtonAdd').prop('disabled', greetingButtons.length >= GREETING_BUTTON_MAX);
        renderGreetingPreview();
    }

    /** 위젯에서 보일 모양. 고객 이름은 예시로 채운다. */
    function renderGreetingPreview() {
        var body = $.trim($('#widgetGreeting').val() || '');
        var $preview = $('#widgetGreetingPreview');
        var $buttons = $('#widgetGreetingPreviewButtons').empty();

        if (!body) {
            $preview.addClass('is-empty').text('인사말이 없으면 이 말풍선은 보이지 않습니다.');
        } else {
            $preview.removeClass('is-empty')
                .text(body.split('{visitor_name}').join('홍길동').split('{org_name}').join(WIDGET_GREETING.orgName));
        }

        greetingButtons.forEach(function (item) {
            if ($.trim(item.label)) {
                $buttons.append($('<span class="rh-ws-chip"></span>').text(item.label));
            }
        });
    }

    /** 커서 자리에 {변수} 를 넣는다. */
    function insertGreetingVariable(name) {
        var el = document.getElementById('widgetGreeting');
        var token = '{' + name + '}';
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;

        el.value = el.value.slice(0, start) + token + el.value.slice(end);
        el.focus();
        el.selectionStart = el.selectionEnd = start + token.length;
        renderGreetingPreview();
    }

    // ------------------------------------------------------------------
    // 저장
    // ------------------------------------------------------------------

    function save() {
        var $btn = $('#btnSaveWidget').prop('disabled', true);

        apiPost(WIDGET_URLS.save, {
            enabled: $('#widgetEnabled').is(':checked') ? 1 : 0,
            color: $('#widgetColor').val(),
            position: $('#widgetPosition').val(),
            domains: $('#widgetDomains').val(),
            offline_email: $('#widgetOfflineEmail').val(),
            guide_autosuggest: $('#widgetGuideSuggest').is(':checked') ? 1 : 0,
            hours_enabled: $('#widgetHoursEnabled').is(':checked') ? 1 : 0,
            hours: JSON.stringify(hours),
            offhours_mode: $('input[name="widgetOffhours"]:checked').val(),
            msg_closed: $('#widgetMsg_closed').val(),
            msg_holiday: $('#widgetMsg_holiday').val(),
            msg_away: $('#widgetMsg_away').val(),
            reply_mode: $('input[name="widgetReplyMode"]:checked').val() || 'off',
            reply_minutes: $('#widgetReplyMinutes').val(),
            phone_button: $('#widgetPhoneButton').is(':checked') ? 1 : 0,
            phone_hours_only: $('#widgetPhoneHoursOnly').is(':checked') ? 1 : 0,
            greeting: $('#widgetGreeting').val(),
            greeting_buttons: JSON.stringify(greetingButtons.filter(function (item) {
                return $.trim(item.label) || $.trim(item.url);
            })),
            ask_name: $('#widgetAskName').is(':checked') ? 1 : 0,
            ask_email: $('#widgetAskEmail').is(':checked') ? 1 : 0,
            ask_phone: $('#widgetAskPhone').is(':checked') ? 1 : 0,
            contact_required: $('#widgetContactRequired').is(':checked') ? 1 : 0,
            contact_notice: $('#widgetContactNotice').val(),
            followup_email: $('#widgetFollowupEmail').is(':checked') ? 1 : 0
        }, function (data, message) {
            showToast(message, 'success');
            closeDrawer('widgetSettingsDrawer');
            // 운영 시간을 바꾸면 화면 상단의 운영 상태도 달라지므로 새로 그린다.
            window.location.reload();
        }).always(function () { $btn.prop('disabled', false); });
    }

    function showTab(key) {
        $('#widgetSettingsDrawer [data-tab]').each(function () {
            $(this).toggleClass('active', $(this).data('tab') === key)
                .attr('aria-selected', $(this).data('tab') === key ? 'true' : 'false');
        });
        $('#widgetSettingsDrawer [data-tab-panel]').each(function () {
            $(this).toggleClass('d-none', $(this).data('tab-panel') !== key);
        });
    }

    $(function () {
        // 서버 값은 {"1": [["09:00","18:00"]]} 모양. 처음 쓰는 조직이면 평일 09:00-18:00 을 채워 둔다.
        hours = $.extend(true, {}, WIDGET_HOURS);

        var empty = WEEK.every(function (day) { return !(hours[day[0]] || []).length; });

        if (empty && !$('#widgetHoursEnabled').is(':checked')) {
            [1, 2, 3, 4, 5].forEach(function (weekday) { hours[weekday] = [DEFAULT_RANGE.slice()]; });
        }

        holidays = WIDGET_HOLIDAYS || [];

        renderHours();
        renderHolidays();
        $('#holidayShortStart').html(timeOptions('09:00'));
        $('#holidayShortEnd').html(timeOptions('13:00'));

        $('#btnChatSettings').on('click', function () { openDrawer('widgetSettingsDrawer'); });
        $('#widgetSettingsDrawer [data-tab]').on('click', function () { showTab($(this).data('tab')); });

        $('#btnCopySnippet').on('click', function () {
            var $snippet = $('#widgetSnippet');
            $snippet.get(0).select();

            // 보안 컨텍스트가 아니면 클립보드 API 를 쓸 수 없어 선택 상태로만 둔다.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText($snippet.val()).then(function () {
                    showToast('스니펫을 복사했습니다.', 'success');
                });
            } else {
                showToast('스니펫을 선택했습니다. Ctrl+C 로 복사하세요.', 'info');
            }
        });

        $('#btnRegenerate').on('click', function () {
            showConfirmModal('키 재발급',
                '새 키를 발급하면 지금 설치된 스니펫은 즉시 동작하지 않습니다. 계속할까요?',
                function () {
                    apiPost(WIDGET_URLS.regenerate, {}, function (data, message) {
                        $('#widgetSnippet').val(data.snippet);
                        showToast(message, 'success');
                    });
                });
        });

        greetingButtons = (WIDGET_GREETING.buttons || []).map(function (item) {
            return { label: item.label, url: item.url };
        });
        renderGreetingButtons();
        $('#widgetGreeting').on('input', renderGreetingPreview);
        $('[data-greeting-var]').on('click', function () { insertGreetingVariable($(this).data('greeting-var')); });
        $('#btnGreetingButtonAdd').on('click', function () {
            if (greetingButtons.length < GREETING_BUTTON_MAX) {
                greetingButtons.push({ label: '', url: '' });
                renderGreetingButtons();
            }
        });

        $('#btnHoursCopyWeekdays').on('click', copyMondayToWeekdays);

        $('input[name="holidayMode"]').on('change', function () {
            var short = $('input[name="holidayMode"]:checked').val() === 'short';
            $('#holidayShortTimes').toggleClass('d-none', !short).toggleClass('d-flex', short);
        });
        $('#btnHolidayAdd').on('click', addHoliday);
        $('#btnHolidayCandidates').on('click', loadCandidates);
        $('#btnHolidayCandidatesClose').on('click', function () { $('#widgetHolidayCandidates').addClass('d-none'); });
        $('#btnHolidayCandidatesAdd').on('click', addCandidates);

        $('#btnSaveWidget').on('click', save);

        if (WIDGET_OPEN_SETTINGS) {
            openDrawer('widgetSettingsDrawer');
        }
    });
})(jQuery);
