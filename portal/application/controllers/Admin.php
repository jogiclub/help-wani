<?php
/**
 * 파일 위치: application/controllers/Admin.php
 * 역할: 조직 관리(상담원 관리, 조직 정보, 통계, 상담 위젯 설정 API, 상담 운영 시간·휴일·운영 상태 API)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends Admin_Controller {

    public function index()
    {
        $stats = $this->organization_model->statistics($this->agent->org_id, 30);

        $this->load->model(array('chat_model', 'macro_model'));

        $this->render('admin/dashboard', array(
            'page_title' => '조직 관리',
            'org'        => $this->organization_model->get_by_id($this->agent->org_id),
            'stats'      => $stats,
            // 상담 대화(위젯) 숫자. 원격지원 숫자와 섞지 않는다(CLAUDE.md 3.10).
            'chat_stats' => $this->chat_model->reply_stats($this->agent->org_id, 30),
            // 매크로 사용(docs/macro-plan.md M8). 개인 매크로는 이름 없이 합계만.
            'macro_stats'=> $this->macro_model->usage_stats($this->agent->org_id, gmdate('Y-m-d H:i:s', time() - 30 * 86400)),
        ));
    }

    public function agents()
    {
        $this->render('admin/agents', array(
            'page_title' => '상담원 관리',
            'use_grid'   => TRUE,
            'agents'     => $this->agent_model->list_by_org($this->agent->org_id),
        ));
    }

    public function organization()
    {
        $this->render('admin/organization', array(
            'page_title'   => '조직 정보',
            'use_dropzone' => TRUE,
            'org'          => $this->organization_model->get_by_id($this->agent->org_id),
            'locales'    => supported_locales(),
            'timezones'  => supported_timezones(),
        ));
    }

    /**
     * GET /admin/widget
     * 상담 위젯 설정은 상담 대화 화면의 설정 드로어로 옮겼다. 예전 주소로 오면 드로어를 연 채로 보낸다.
     */
    public function widget()
    {
        redirect('console/chat?settings=widget');
    }

    /**
     * POST /admin/api/agent_save
     */
    public function api_agent_save()
    {
        $id    = (int) $this->input->post('id');
        $name  = $this->input->post('name', TRUE);
        $email = $this->input->post('email', TRUE);
        $role  = $this->input->post('role', TRUE) === 'admin' ? 'admin' : 'agent';
        $pass  = (string) $this->input->post('password');

        if ($name === '' OR ! filter_var($email, FILTER_VALIDATE_EMAIL))
        {
            api_response(FALSE, '이름과 올바른 이메일을 입력해 주세요.', array(), 400);
            return;
        }

        $dup = $this->agent_model->get_by_email($email);

        if ($dup && (int) $dup->id !== $id)
        {
            api_response(FALSE, '이미 사용 중인 이메일입니다.', array(), 409);
            return;
        }

        if ($id > 0)
        {
            $target = $this->agent_model->get_by_id($id);

            if (empty($target) || (int) $target->org_id !== (int) $this->agent->org_id)
            {
                api_response(FALSE, '대상을 찾을 수 없습니다.', array(), 404);
                return;
            }

            $data = array('name' => $name, 'email' => $email, 'role' => $role,
                          'phone' => $this->input->post('phone', TRUE));

            if ($pass !== '')
            {
                if (strlen($pass) < 10)
                {
                    api_response(FALSE, '비밀번호는 10자 이상이어야 합니다.', array(), 400);
                    return;
                }
                $data['password'] = $pass;
            }

            $this->agent_model->update($id, $data);
            $this->log_model->audit($this->agent->org_id, $this->agent->id, 'agent_update', 'agent_id='.$id, client_ip());
            api_response(TRUE, '상담원 정보를 저장했습니다.', array());
            return;
        }

        if (strlen($pass) < 10)
        {
            api_response(FALSE, '비밀번호는 10자 이상이어야 합니다.', array(), 400);
            return;
        }

        $new_id = $this->agent_model->insert(array(
            'org_id'      => $this->agent->org_id,
            'email'       => $email,
            'password'    => $pass,
            'name'        => $name,
            'phone'       => $this->input->post('phone', TRUE),
            'role'        => $role,
            'is_verified' => 1,
        ));

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'agent_create', 'agent_id='.$new_id, client_ip());
        api_response(TRUE, '상담원을 추가했습니다.', array('id' => $new_id));
    }

    /**
     * POST /admin/api/agent_toggle
     */
    public function api_agent_toggle()
    {
        $id     = (int) $this->input->post('id');
        $target = $this->agent_model->get_by_id($id);

        if (empty($target) || (int) $target->org_id !== (int) $this->agent->org_id)
        {
            api_response(FALSE, '대상을 찾을 수 없습니다.', array(), 404);
            return;
        }

        if ((int) $target->id === (int) $this->agent->id)
        {
            api_response(FALSE, '본인 계정은 비활성화할 수 없습니다.', array(), 400);
            return;
        }

        $next = ((int) $target->is_active === 1) ? 0 : 1;
        $this->agent_model->update($id, array('is_active' => $next));
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'agent_toggle', 'agent_id='.$id.' active='.$next, client_ip());

        api_response(TRUE, $next ? '계정을 활성화했습니다.' : '계정을 비활성화했습니다.', array('is_active' => $next));
    }

    /**
     * POST /admin/api/org_save
     */
    public function api_org_save()
    {
        $name  = trim((string) $this->input->post('name', TRUE));
        $phone = $this->input->post('phone', TRUE);
        // 소개 문구는 화면에서 html_escape 하므로 원문을 받고 길이만 자른다(줄바꿈 유지).
        $intro = mb_substr(trim((string) $this->input->post('intro')), 0, 500);

        if ($name === '')
        {
            api_response(FALSE, '조직명을 입력해 주세요.', array(), 400);
            return;
        }

        // 언어와 시간대는 허용 목록 안의 값만 받는다.
        $locales   = supported_locales();
        $timezones = supported_timezones();

        $locale   = (string) $this->input->post('locale', TRUE);
        $timezone = (string) $this->input->post('timezone', TRUE);

        $locale   = isset($locales[$locale]) ? $locale : 'ko';
        $timezone = isset($timezones[$timezone]) ? $timezone : 'Asia/Seoul';

        $this->organization_model->update($this->agent->org_id, array(
            'name'     => $name,
            'phone'    => $phone,
            'intro'    => $intro === '' ? NULL : $intro,
            'locale'   => $locale,
            'timezone' => $timezone,
        ));

        $this->session->set_userdata(array(
            'org_name' => $name,
            'locale'   => $locale,
            'timezone' => $timezone,
        ));
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'org_update', NULL, client_ip());

        api_response(TRUE, '조직 정보를 저장했습니다.', array());
    }

    /**
     * POST /admin/api/widget_save
     */
    public function api_widget_save()
    {
        // 색상과 위치는 허용 목록 검사를 거친다. (CLAUDE.md 3.7)
        $color = (string) $this->input->post('color', TRUE);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#2563eb';

        $position = (string) $this->input->post('position', TRUE);
        $position = in_array($position, array('right', 'left'), TRUE) ? $position : 'right';

        $email = (string) $this->input->post('offline_email', TRUE);
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : NULL;

        // 운영 시간(docs/chat-operation-plan.md F1). 형식이 틀리면 아무것도 저장하지 않는다.
        $this->load->model('chat_hours_model');
        $hours = $this->chat_hours_model->clean_hours($this->input->post('hours'));

        if (is_string($hours))
        {
            api_response(FALSE, $hours, array(), 400);
            return;
        }

        $hours_enabled = $this->input->post('hours_enabled') ? 1 : 0;
        $open_days = count(array_filter($hours));

        if ($hours_enabled && $open_days === 0)
        {
            api_response(FALSE, '운영 시간을 쓰려면 운영하는 요일을 하루 이상 넣어 주세요.', array(), 400);
            return;
        }

        $offhours = (string) $this->input->post('offhours_mode', TRUE);
        $offhours = in_array($offhours, array('accept', 'info', 'hide'), TRUE) ? $offhours : 'accept';

        // 답변 속도 안내(F6). 직접 지정 값은 위젯이 보여 주는 구간 중 하나만 받는다.
        $this->load->model('chat_model');
        $reply_mode = (string) $this->input->post('reply_mode', TRUE);
        $reply_mode = in_array($reply_mode, array('off', 'manual', 'auto'), TRUE) ? $reply_mode : 'off';
        $reply_minutes = (int) $this->input->post('reply_minutes');
        $reply_minutes = in_array($reply_minutes, Chat_model::REPLY_BUCKETS, TRUE) ? $reply_minutes : NULL;

        if ($reply_mode === 'manual' && $reply_minutes === NULL)
        {
            api_response(FALSE, '답변 속도를 골라 주세요.', array(), 400);
            return;
        }

        // 자동 응답 문구는 위젯이 textContent 로 그리므로 원문을 받고 길이만 자른다. 비우면 기본 문구.
        $messages = array();

        foreach (array('closed', 'holiday', 'away') as $kind)
        {
            $text = mb_substr(trim((string) $this->input->post('msg_'.$kind)), 0, 500);
            $messages['chat_msg_'.$kind] = $text === '' ? NULL : $text;
        }

        // 인사말(docs/widget-ops-plan.md 2장). 위젯이 textContent 로 그리므로 원문을 받고, 버튼 주소는 https 만 받는다.
        $this->load->library('chat_greeting');
        $greeting = str_replace(array("\r\n", "\r"), "\n", (string) $this->input->post('greeting'));
        $greeting = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $greeting));

        if (mb_strlen($greeting) > Chat_greeting::BODY_MAX)
        {
            api_response(FALSE, '인사말은 '.Chat_greeting::BODY_MAX.'자까지 쓸 수 있습니다.', array(), 400);
            return;
        }

        $buttons = $this->chat_greeting->clean_buttons($this->input->post('greeting_buttons'));

        if (is_string($buttons))
        {
            api_response(FALSE, $buttons, array(), 400);
            return;
        }

        // 연락처 카드와 팔로업 메일(3장)
        $ask = array();

        foreach (array('name', 'email', 'phone') as $field)
        {
            $ask['chat_ask_'.$field] = $this->input->post('ask_'.$field) ? 1 : 0;
        }

        $contact_required = $this->input->post('contact_required') ? 1 : 0;

        if ($contact_required && array_sum($ask) === 0)
        {
            api_response(FALSE, '연락처를 받아야 문의를 받으려면 묻는 항목을 하나 이상 골라 주세요.', array(), 400);
            return;
        }

        $contact_notice = mb_substr(trim((string) $this->input->post('contact_notice')), 0, 500);

        // 도메인은 줄 단위로 받아 호스트만 남긴다.
        $lines = preg_split('/[\r\n,]+/', (string) $this->input->post('domains', TRUE));
        $domains = array();

        foreach ($lines as $line)
        {
            $line = strtolower(trim($line));
            $line = preg_replace('#^https?://#', '', $line);
            $line = trim(preg_replace('#/.*$#', '', $line));

            if ($line !== '' && preg_match('/^[a-z0-9.\-*]+$/', $line))
            {
                $domains[] = $line;
            }
        }

        $this->organization_model->update($this->agent->org_id, array(
            'widget_enabled'       => $this->input->post('enabled') ? 1 : 0,
            'widget_color'         => $color,
            'widget_position'      => $position,
            'widget_domains'       => empty($domains) ? NULL : mb_substr(implode("\n", $domains), 0, 500),
            'widget_offline_email' => $email,
            'guide_autosuggest'    => $this->input->post('guide_autosuggest') ? 1 : 0,
            'chat_hours_enabled'   => $hours_enabled,
            'chat_offhours_mode'   => $offhours,
            'chat_reply_mode'      => $reply_mode,
            'chat_reply_minutes'   => $reply_minutes,
            // 방식을 바꾸면 실제 기록을 다음 요청에서 다시 계산한다.
            'chat_reply_calc_at'   => NULL,
            'chat_phone_button'    => $this->input->post('phone_button') ? 1 : 0,
            'chat_phone_hours_only'=> $this->input->post('phone_hours_only') ? 1 : 0,
            'chat_greeting'        => $greeting === '' ? NULL : $greeting,
            'chat_greeting_buttons'=> empty($buttons) ? NULL : json_encode($buttons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chat_contact_required'=> $contact_required,
            'chat_contact_notice'  => $contact_notice === '' ? NULL : $contact_notice,
            'chat_followup_email'  => $this->input->post('followup_email') ? 1 : 0,
        ) + $ask + $messages);

        $this->chat_hours_model->save_hours($this->agent->org_id, $hours);

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'widget_update',
            'domains='.count($domains).' hours='.($hours_enabled ? $open_days.'days' : 'off').' offhours='.$offhours
            .' reply='.$reply_mode.' phone='.($this->input->post('phone_button') ? 1 : 0)
            .' greeting='.($greeting === '' ? 'off' : mb_strlen($greeting).'chars/'.count($buttons).'btn')
            .' ask='.implode('', array_map(function ($k) use ($ask) { return $ask['chat_ask_'.$k] ? $k[0] : '-'; }, array('name', 'email', 'phone')))
            .' required='.$contact_required.' followup='.($this->input->post('followup_email') ? 1 : 0), client_ip());

        // 모르는 변수는 저장은 하되 알려 준다(매크로와 같은 방식).
        $unknown = $this->chat_greeting->unknown_variables($greeting);
        $message = empty($unknown) ? '위젯 설정을 저장했습니다.'
            : '저장했습니다. 인사말의 {'.implode('}, {', $unknown).'} 는 알 수 없는 변수라 글자 그대로 보입니다.';

        api_response(TRUE, $message);
    }

    /**
     * POST /admin/api/chat_state  (state = auto | open | closed)
     * 조직 운영 상태를 바로 바꾼다. 자동 판정이 같은 값이 되는 때 '자동' 으로 돌아간다(F3).
     */
    public function api_chat_state()
    {
        $state = (string) $this->input->post('state', TRUE);

        if ( ! in_array($state, array('auto', 'open', 'closed'), TRUE))
        {
            api_response(FALSE, '운영 상태 값이 올바르지 않습니다.', array(), 400);
            return;
        }

        $this->load->model('chat_hours_model');
        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $availability = $this->chat_hours_model->set_manual($org, $state);

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'chat_state',
            'state='.$state.' applied='.$availability['manual'].' until='.($availability['manual_until'] ?: '-'), client_ip());

        $labels = array('auto' => '운영 시간대로 돌렸습니다.', 'open' => '지금부터 상담을 받습니다.', 'closed' => '지금부터 상담을 받지 않습니다.');
        $message = $availability['manual'] === 'auto' && $state !== 'auto'
            ? '지금 운영 시간 설정과 같은 상태라 운영 시간대로 둡니다.'
            : $labels[$state];

        api_response(TRUE, $message, array('availability' => $this->chat_hours_model->console_view($org, $availability)));
    }

    /**
     * GET /admin/api/holiday_candidates
     * 조직 국가의 공휴일 후보(config/holidays.php). 자동으로 넣지 않고 관리자가 고른다.
     */
    public function api_holiday_candidates()
    {
        $this->load->model('chat_hours_model');
        $org = $this->organization_model->get_by_id($this->agent->org_id);

        api_response(TRUE, '', array('candidates' => $this->chat_hours_model->holiday_candidates($org)));
    }

    /**
     * POST /admin/api/holiday_add
     * 한 건(start_date, end_date, name, mode, start, end, repeat_yearly) 또는
     * 공휴일 후보 여러 건(items = JSON [{date, name}])을 넣는다.
     */
    public function api_holiday_add()
    {
        $this->load->model('chat_hours_model');

        $items = json_decode((string) $this->input->post('items'), TRUE);

        if ( ! is_array($items))
        {
            $items = array(array(
                'start_date'    => $this->input->post('start_date', TRUE),
                'end_date'      => $this->input->post('end_date', TRUE),
                'name'          => $this->input->post('name', TRUE),
                'mode'          => $this->input->post('mode', TRUE),
                'start'         => $this->input->post('start', TRUE),
                'end'           => $this->input->post('end', TRUE),
                'repeat_yearly' => $this->input->post('repeat_yearly'),
            ));
        }
        else
        {
            $items = array_map(function ($item) {
                $date = is_array($item) && isset($item['date']) ? $item['date'] : '';
                $name = is_array($item) && isset($item['name']) ? $item['name'] : '';
                return array('start_date' => $date, 'end_date' => $date, 'name' => $name, 'mode' => 'closed');
            }, array_slice($items, 0, 50));
        }

        $rows = array();

        foreach ($items as $item)
        {
            $row = $this->chat_hours_model->clean_holiday($item);

            if (is_string($row))
            {
                api_response(FALSE, $row, array(), 400);
                return;
            }

            $rows[] = $row;
        }

        if (empty($rows))
        {
            api_response(FALSE, '추가할 휴일을 골라 주세요.', array(), 400);
            return;
        }

        foreach ($rows as $row)
        {
            $this->chat_hours_model->add_holiday($this->agent->org_id, $row);
        }

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'chat_holiday_add',
            'count='.count($rows).' first='.$rows[0]['start_date'], client_ip());

        api_response(TRUE, '휴일 '.count($rows).'건을 추가했습니다.', array('holidays' => $this->holiday_rows()));
    }

    /**
     * POST /admin/api/holiday_delete
     */
    public function api_holiday_delete()
    {
        $this->load->model('chat_hours_model');

        $id = (int) $this->input->post('id');

        if ( ! $this->chat_hours_model->delete_holiday($this->agent->org_id, $id))
        {
            api_response(FALSE, '휴일을 찾을 수 없습니다.', array(), 404);
            return;
        }

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'chat_holiday_delete', 'id='.$id, client_ip());

        api_response(TRUE, '휴일을 지웠습니다.', array('holidays' => $this->holiday_rows()));
    }

    protected function holiday_rows()
    {
        return array_map(array($this->chat_hours_model, 'present_holiday'),
            $this->chat_hours_model->list_holidays($this->agent->org_id));
    }

    /**
     * POST /admin/api/widget_regenerate
     * 키를 새로 발급하면 기존 스니펫은 즉시 동작하지 않는다.
     */
    public function api_widget_regenerate()
    {
        $key = $this->organization_model->regenerate_widget_key($this->agent->org_id);
        $org = $this->organization_model->get_by_id($this->agent->org_id);

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'widget_key_regenerate', NULL, client_ip());

        api_response(TRUE, '새 키를 발급했습니다. 기존 스니펫은 더 이상 동작하지 않습니다.', array(
            'widget_key' => $key,
            'snippet'    => $this->organization_model->widget_snippet($org),
        ));
    }

    /**
     * POST /admin/api/logo_upload  (multipart, 필드명 logo)
     * 고객 접속 페이지와 런처에 보이는 조직 로고를 바꾼다.
     * SVG 는 스크립트를 담을 수 있으므로 받지 않는다. 형식은 확장자가 아니라 파일 내용으로 판별한다.
     */
    public function api_logo_upload()
    {
        $file = isset($_FILES['logo']) ? $_FILES['logo'] : NULL;
        $ext  = check_uploaded_image($file, RH_LOGO_MAX_BYTES);

        if (is_array($ext))
        {
            api_response(FALSE, $ext['error'], array(), 400);
            return;
        }

        $org  = $this->organization_model->get_by_id($this->agent->org_id);
        $dir  = FCPATH.'assets/uploads/logos/';
        is_dir($dir) OR @mkdir($dir, 0775, TRUE);
        // 이름을 매번 바꿔 브라우저와 런처가 예전 로고를 캐시에서 보여주지 않게 한다.
        $name = $org->org_code.'-'.bin2hex(random_bytes(6)).'.'.$ext;

        if ( ! @move_uploaded_file($file['tmp_name'], $dir.$name))
        {
            api_response(FALSE, '로고를 저장하지 못했습니다. 업로드 폴더 권한을 확인해 주세요.', array(), 500);
            return;
        }

        $this->remove_logo_file($org->logo_path);
        $logo_path = 'assets/uploads/logos/'.$name;
        $this->organization_model->update($org->id, array('logo_path' => $logo_path));
        $this->log_model->audit($org->id, $this->agent->id, 'org_logo_update', $logo_path, client_ip());

        api_response(TRUE, '로고를 저장했습니다.', array('logo_url' => base_url($logo_path)));
    }

    /**
     * POST /admin/api/logo_delete
     */
    public function api_logo_delete()
    {
        $org = $this->organization_model->get_by_id($this->agent->org_id);

        $this->remove_logo_file($org->logo_path);
        $this->organization_model->update($org->id, array('logo_path' => NULL));
        $this->log_model->audit($org->id, $this->agent->id, 'org_logo_delete', NULL, client_ip());

        api_response(TRUE, '로고를 삭제했습니다.', array());
    }

    /**
     * 예전 로고 파일을 지운다. 로고 폴더 밖의 경로는 건드리지 않는다.
     */
    protected function remove_logo_file($logo_path)
    {
        if (empty($logo_path) || ! preg_match('#^assets/uploads/logos/[A-Za-z0-9_\-]+\.(png|jpg|webp)$#', $logo_path))
        {
            return;
        }

        if (is_file(FCPATH.$logo_path))
        {
            @unlink(FCPATH.$logo_path);
        }
    }
}
