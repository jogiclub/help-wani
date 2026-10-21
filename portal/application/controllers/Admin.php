<?php
/**
 * 파일 위치: application/controllers/Admin.php
 * 역할: 조직 관리(상담원 관리, 조직 정보, 통계, 상담 위젯 설정 API)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends Admin_Controller {

    public function index()
    {
        $stats = $this->organization_model->statistics($this->agent->org_id, 30);

        $this->render('admin/dashboard', array(
            'page_title' => '조직 관리',
            'org'        => $this->organization_model->get_by_id($this->agent->org_id),
            'stats'      => $stats,
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
        ));

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'widget_update',
            'domains='.count($domains), client_ip());

        api_response(TRUE, '위젯 설정을 저장했습니다.');
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
