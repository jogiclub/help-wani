"""
파일 위치: backend/app/settings/guide_samples.py
역할: 가이드 관리를 처음 열 때 만드는 샘플 가이드 10개 (분류, 동의어, 글/이미지/동영상 블록)

guide_model.seed_samples() 가 읽는다. 샘플은 모두 임시(draft) 상태로 만들어 고객에게 바로 보이지 않는다.
- 글의 {org}, {phone}, {customer_url} 은 조직 값으로 바뀐다.
- image 블록의 sample 은 assets/img/guide-samples/ 의 파일이며, 조직 폴더로 복사한 뒤 src 로 바뀐다.
- video 블록의 영상은 2026-10-21 에 유튜브 oEmbed 로 존재와 퍼가기 허용을 확인한 것이다.

"""

CATEGORIES = [{'key': 'start', 'name': '시작하기', 'sort_order': 1},
 {'key': 'remote', 'name': '원격지원', 'sort_order': 2},
 {'key': 'trouble', 'name': '문제 해결', 'sort_order': 3},
 {'key': 'account', 'name': '계정', 'sort_order': 4},
 {'key': 'billing', 'name': '결제', 'sort_order': 5}]

SYNONYMS = [['원격지원', '원격', '원격제어', '화면공유'],
 ['로그인', '사인인', '로그온'],
 ['비밀번호', '패스워드', '비번', '암호'],
 ['프린터', '인쇄기', '복합기'],
 ['인터넷', '와이파이', 'wifi', '네트워크']]

ARTICLES = [{'category': 'start',
  'slug': 'support-hours',
  'sort_order': 1,
  'title': '상담 운영 시간과 연락처',
  'subtitle': '언제, 어떻게 문의하면 되는지 알려 드립니다',
  'keywords': '운영시간, 상담시간, 연락처, 전화번호, 주말, 공휴일, 문의방법',
  'blocks': [{'type': 'text',
              'text': '{org} 상담 운영 시간은 다음과 같습니다.\n'
                      '\n'
                      '- 평일 09:00 ~ 18:00 (점심 12:00 ~ 13:00)\n'
                      '- 토요일, 일요일, 공휴일은 쉽니다\n'
                      '\n'
                      '전화: {phone}\n'
                      '운영 시간 밖에 남긴 상담 메시지는 다음 영업일에 순서대로 답변드립니다.'}]},
 {'category': 'remote',
  'slug': 'remote-support-how',
  'sort_order': 1,
  'title': '원격지원 받는 방법',
  'subtitle': '앱 설치부터 연결까지 3단계',
  'keywords': '원격지원, 원격, 원격제어, 화면공유, 연결방법, 사용법',
  'blocks': [{'type': 'text', 'text': '1단계. 원격지원 앱 설치\n아래 접속 페이지에서 원격지원 앱을 설치합니다. 한 번만 설치하면 됩니다.\n{customer_url}'},
             {'type': 'text', 'text': '2단계. 코드 입력\n앱을 실행하고 상담원이 알려 드린 6자리 코드를 입력한 뒤 [연결하기] 를 누릅니다.'},
             {'type': 'image', 'sample': 'remote-code.png', 'caption': '상담원에게 받은 6자리 코드를 입력합니다'},
             {'type': 'text', 'text': '3단계. 허용\n상담원 이름을 확인하고 [허용] 을 누르면 상담원이 화면을 함께 봅니다. 언제든 창을 닫으면 바로 끊깁니다.'},
             {'type': 'image', 'sample': 'remote-allow.png', 'caption': '상담원 이름을 확인한 뒤 허용합니다'}]},
 {'category': 'remote',
  'slug': 'remote-code',
  'sort_order': 2,
  'title': '접속 코드는 어디서 받나요?',
  'subtitle': '6자리 코드의 발급과 유효 시간',
  'keywords': '코드, 접속코드, 인증번호, 6자리, 코드만료, 코드오류',
  'blocks': [{'type': 'text',
              'text': '접속 코드는 전화나 상담 대화에서 상담원이 알려 드립니다. 직접 만들 수는 없습니다.\n'
                      '\n'
                      '- 코드는 발급 후 10분 동안만 쓸 수 있습니다.\n'
                      '- 시간이 지났거나 "코드가 올바르지 않습니다" 가 나오면 상담원에게 새 코드를 요청해 주세요.'}]},
 {'category': 'remote',
  'slug': 'remote-safety',
  'sort_order': 3,
  'title': '원격지원 보안 안내',
  'subtitle': '보이스피싱을 막기 위해 꼭 확인하세요',
  'keywords': '보안, 보이스피싱, 사기, 안전, 개인정보, 해킹',
  'blocks': [{'type': 'text',
              'text': '원격지원은 고객님이 [허용] 을 누를 때만 시작되고, 창을 닫으면 즉시 끝납니다.\n'
                      '\n'
                      '이런 요청은 {org} 상담원이 하지 않습니다.\n'
                      '- 계좌 비밀번호, 보안카드, OTP 번호를 알려 달라는 요청\n'
                      '- 은행 앱이나 인터넷뱅킹을 열어 달라는 요청\n'
                      '- 경찰, 검찰, 금융감독원이라며 원격 연결을 요구하는 전화\n'
                      '\n'
                      '의심스러우면 연결을 끊고 대표 전화 {phone} 로 확인해 주세요.'}]},
 {'category': 'trouble',
  'slug': 'app-not-starting',
  'sort_order': 1,
  'title': '원격지원 앱이 실행되지 않을 때',
  'subtitle': '설치 오류, 백신 차단, 창이 안 뜰 때',
  'keywords': '앱, 실행, 설치, 설치오류, 안열림, 백신, 차단, 프로그램',
  'blocks': [{'type': 'text',
              'text': '아래 순서대로 확인해 주세요.\n'
                      '\n'
                      '1. PC 를 다시 시작한 뒤 앱을 다시 실행합니다.\n'
                      '2. 백신 프로그램이 실행을 막았다는 알림이 있으면 [허용] 또는 [예외 추가] 를 누릅니다.\n'
                      '3. 그래도 안 되면 앱을 지우고 접속 페이지에서 다시 설치합니다.\n'
                      '   {customer_url}\n'
                      '\n'
                      '회사 PC 라서 설치 권한이 없으면 전산 담당자에게 문의해 주세요.'}]},
 {'category': 'trouble',
  'slug': 'printer-connect',
  'sort_order': 2,
  'title': '프린터가 연결되지 않을 때',
  'subtitle': 'USB 와 네트워크 프린터 점검 순서',
  'keywords': '프린터, 인쇄, 출력, 복합기, 스캐너, 인쇄안됨, printer',
  'blocks': [{'type': 'text',
              'text': '1. 프린터 전원과 케이블(또는 Wi-Fi 연결)을 확인합니다.\n'
                      '2. 설정 > Bluetooth 및 장치 > 프린터 및 스캐너 에서 [장치 추가] 를 누릅니다.\n'
                      '3. 목록에 프린터가 나오면 선택해 추가하고, 테스트 페이지를 인쇄해 봅니다.'},
             {'type': 'image', 'sample': 'printer-add.png', 'caption': '설정 > Bluetooth 및 장치 > 프린터 및 스캐너'},
             {'type': 'video',
              'youtube_id': 'hXMtG7iD7R0',
              'caption': 'Windows 11 에서 프린터 추가하기 (영어, SHSU Service Desk)'},
             {'type': 'text', 'text': '목록에 프린터가 나오지 않으면 원격지원으로 함께 확인해 드립니다.'}]},
 {'category': 'trouble',
  'slug': 'internet-slow',
  'sort_order': 3,
  'title': '인터넷이 느리거나 끊길 때',
  'subtitle': 'Wi-Fi 와 공유기 점검',
  'keywords': '인터넷, 와이파이, wifi, 네트워크, 끊김, 느림, 연결안됨, 공유기',
  'blocks': [{'type': 'text',
              'text': '1. 작업 표시줄의 Wi-Fi 아이콘을 눌러 "연결됨" 인지 확인합니다.\n'
                      '2. 공유기 전원을 뽑고 10초 뒤 다시 꽂습니다. 불이 모두 켜질 때까지 2~3분 기다립니다.\n'
                      '3. 다른 기기(휴대폰)도 안 되면 인터넷 회선 문제이므로 통신사에 문의해 주세요.'},
             {'type': 'image', 'sample': 'wifi-status.png', 'caption': '연결된 Wi-Fi 이름 아래에 "연결됨" 이 보여야 합니다'},
             {'type': 'video', 'youtube_id': 't8IZKJXBSBQ', 'caption': 'Windows 11 Wi-Fi 문제 해결 (영어, Pureinfotech)'}]},
 {'category': 'account',
  'slug': 'password-reset',
  'sort_order': 1,
  'title': '비밀번호를 잊어버렸어요',
  'subtitle': '비밀번호 재설정 방법',
  'keywords': '비밀번호, 패스워드, 비번, 재설정, 분실, 비밀번호찾기',
  'blocks': [{'type': 'text',
              'text': '1. 로그인 화면에서 [비밀번호 찾기] 를 누릅니다.\n'
                      '2. 가입한 이메일 주소를 입력하면 재설정 링크가 발송됩니다.\n'
                      '3. 메일의 링크를 눌러 새 비밀번호를 정합니다. 링크는 30분 동안만 쓸 수 있습니다.\n'
                      '\n'
                      '메일이 오지 않으면 스팸함을 확인하거나 {phone} 로 문의해 주세요.'}]},
 {'category': 'account',
  'slug': 'login-failed',
  'sort_order': 2,
  'title': '로그인이 되지 않을 때',
  'subtitle': '계정 잠김, 아이디 확인',
  'keywords': '로그인, 로그인안됨, 계정잠김, 아이디, 계정, 사인인',
  'blocks': [{'type': 'text',
              'text': '- 키보드의 Caps Lock(대문자 고정)이 켜져 있지 않은지 확인합니다.\n'
                      '- 비밀번호를 5번 틀리면 10분 동안 로그인이 잠깁니다. 잠시 뒤 다시 시도해 주세요.\n'
                      '- 아이디가 기억나지 않으면 가입한 이메일 주소로 로그인해 보세요.\n'
                      '\n'
                      '계속 안 되면 상담 대화로 문의해 주시면 계정 상태를 확인해 드립니다.'}]},
 {'category': 'billing',
  'slug': 'receipt',
  'sort_order': 1,
  'title': '결제 영수증과 세금계산서',
  'subtitle': '증빙 서류 발급과 환불 문의',
  'keywords': '영수증, 세금계산서, 결제, 증빙, 환불, 카드, 결제내역',
  'blocks': [{'type': 'text',
              'text': '카드 결제 영수증은 결제 완료 메일에 함께 보내 드립니다.\n'
                      '\n'
                      '세금계산서가 필요하시면 사업자등록증과 받을 이메일 주소를 상담 대화로 보내 주세요. 영업일 기준 2일 안에 발행해 드립니다.\n'
                      '\n'
                      '환불은 결제일로부터 7일 이내에 요청하실 수 있습니다.'}]}]
