<?php
/**
 * 파일 위치: application/controllers/Download.php
 * 역할: 고객용 런처 실행 파일 다운로드 제공
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Download extends MY_Controller {

    /** 배포 파일이 놓이는 폴더 */
    const DOWNLOAD_DIR = 'assets/downloads';

    /** 배포 파일 이름 (빌드 산출물을 이 이름으로 올린다) */
    const LAUNCHER_FILE = 'RemoteHelp.exe';

    /**
     * 배포 파일의 절대 경로
     */
    public static function launcher_path()
    {
        return FCPATH.self::DOWNLOAD_DIR.'/'.self::LAUNCHER_FILE;
    }

    /**
     * 배포 파일 정보. 파일이 없으면 NULL.
     */
    public static function launcher_info()
    {
        $path = self::launcher_path();

        if ( ! is_file($path))
        {
            return NULL;
        }

        return array(
            'size'     => filesize($path),
            'size_mb'  => round(filesize($path) / 1048576, 1),
            'modified' => date('Y-m-d', filemtime($path)),
            'sha256'   => self::cached_hash($path),
        );
    }

    /**
     * 해시 계산은 파일이 크면 비용이 있으므로 캐시 폴더에 저장해 둔다.
     * 배포 파일이 바뀌면(수정 시각 변경) 다시 계산한다.
     */
    protected static function cached_hash($path)
    {
        $cache = sys_get_temp_dir().'/remotehelp-launcher-'.filemtime($path).'.sha256';

        if (is_file($cache))
        {
            return trim(file_get_contents($cache));
        }

        $hash = hash_file('sha256', $path);
        @file_put_contents($cache, $hash);

        return $hash;
    }

    /**
     * GET /{org_code}/download
     *
     * 조직 코드를 파일 이름에 담아 내려준다.
     * 런처는 실행 파일 이름에서 조직 코드를 읽어 시작 화면에 반영한다.
     */
    public function launcher($org_code = '')
    {
        $org = $this->organization_model->get_active_by_code($org_code);

        if (empty($org))
        {
            show_404();
            return;
        }

        // 직접 다운로드는 코드 서명 인증서가 준비된 뒤에만 연다(docs/code-signing.md).
        if ( ! env('DIRECT_DOWNLOAD_ENABLED', FALSE))
        {
            redirect($org->org_code);
            return;
        }

        $path = self::launcher_path();

        if ( ! is_file($path))
        {
            $this->use_org_locale($org);

            $this->render('customer/download_unavailable', array(
                'page_title' => lang_text('customer.download.unavailable.title'),
                'org'        => $org,
            ), 'layouts/customer');
            return;
        }

        $this->log_model->audit($org->id, NULL, 'launcher_download', 'org='.$org->org_code, client_ip());

        $filename = 'RemoteHelp_'.$org->org_code.'.exe';

        // 파일이 클 수 있으므로 메모리에 올리지 않고 그대로 흘려보낸다.
        if (ob_get_level())
        {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($path));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }
}
