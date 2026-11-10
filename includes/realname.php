<?php
/**
 * 优享财务系统 · 实名认证（个人 / 企业）
 *
 * 驱动式架构：
 *   - auto：AI 智能自动监测（内置，默认）。不使用「扫码即通过」的模拟核验，
 *           而是对提交资料做完整合规审核（证件号校验位、行政区划、出生日期、
 *           年龄是否成年、姓名规范、企业信用代码、营业执照图片真实性等），
 *           审核通过才写入实名状态，资料有问题一律不予通过。
 *   - http：通用 HTTP 服务商（需后台配置 endpoint / 密钥）。
 *   - mock：历史模拟核验驱动，仅保留用于核销存量认证单，不再作为可选项。
 * 身份证号一律经 yx_encrypt() 加密后入库，不落明文。
 */

interface YxRealnameDriver
{
    public function code(): string;
    public function name(): string;

    /** 创建认证单并返回扫码地址：['ok'=>bool,'qr_url'=>..,'expire_at'=>..,'msg'=>..] */
    public function create(array $subject, string $requestId): array;

    /** 轮询认证状态：['status'=>'pending|verified|failed','message'=>..,'raw'=>[]] */
    public function query(string $requestId): array;

    /** 解析回调：['ok'=>bool,'request_id'=>..,'status'=>'verified|failed','message'=>..,'raw'=>[]] */
    public function verifyCallback(array $params): array;
}

/**
 * AI 智能自动监测审核引擎
 *
 * 对提交的实名资料做静态合规判定，输出结构化检查项：
 *   ['pass'=>bool,'score'=>int,'checks'=>[['key','label','ok','level','note']],'reason'=>string,'age'=>int]
 * 规则设计原则：
 *   - 任何「不可能为真」的资料直接判不通过（校验位错误、行政区划不存在、生日非法）；
 *   - 未满 18 周岁一律不通过（无论其它项是否正常）；
 *   - 明显占位/伪造的姓名、企业名称不通过；
 *   - 营业执照图片做尺寸、体积与空白（纯色）检测。
 */
class YxRealnameAudit
{
    /** 身份证前两位省级行政区划代码 */
    const PROVINCES = ['11', '12', '13', '14', '15', '21', '22', '23', '31', '32', '33', '34', '35', '36', '37',
        '41', '42', '43', '44', '45', '46', '50', '51', '52', '53', '54', '61', '62', '63', '64', '65', '71', '81', '82', '91'];

    /** 姓名占位词（命中即判定为虚假/测试资料） */
    const NAME_BLACKLIST = ['测试', '示例', '样例', '匿名', '管理员', '管理', '哈哈', '某某', '姓名', '名字', '用户',
        '张三', '李四', '王五', '赵六', '老王', '小明', '小红', 'admin', 'test', 'name', 'null', '无'];

    /** 企业名称占位词 */
    const COMPANY_BLACKLIST = ['测试', '示例', '样例', 'demo', 'test', 'xxx', '公司名', '企业名', '待定', '无'];

    /** 企业名称主体关键词（合法登记的企业/个体名称必含其一） */
    const COMPANY_KEYWORDS = ['有限公司', '有限责任公司', '股份有限公司', '集团有限公司', '公司', '集团', '合伙企业',
        '个人独资企业', '个体工商户', '工作室', '中心', '厂', '店', '商行', '经营部', '服务部', '门市部', '研究所',
        '研究院', '医院', '学校', '幼儿园', '事务所', '合作社', '联合社', '协会', '学会', '基金会', '超市', '酒店',
        '宾馆', '药房', '农场', '基地', '馆', '行', '部', '社'];

    /** 统一社会信用代码字符集（不含 I O S V Z） */
    const CREDIT_CHARS = '0123456789ABCDEFGHJKLMNPQRTUWXY';

    /**
     * 执行审核
     * @param array $subject 已规范化的实名资料（dev_type / name / id_card / company / license_no / ...）
     */
    public static function run(array $subject): array
    {
        $type   = (string)($subject['dev_type'] ?? 'personal') === 'enterprise' ? 'enterprise' : 'personal';
        $checks = [];

        $name = trim((string)($subject['name'] ?? ''));
        $idc  = strtoupper(trim((string)($subject['id_card'] ?? '')));

        $checks[] = self::checkName($name, $type === 'enterprise' ? '法定代表人姓名' : '真实姓名');
        $idRes    = self::checkIdCard($idc);
        $checks[] = $idRes['check'];

        if ($type === 'enterprise') {
            $checks[] = self::checkCompany(trim((string)($subject['company'] ?? '')));
            $checks[] = self::checkCreditCode(strtoupper(trim((string)($subject['license_no'] ?? ''))));
            if (trim((string)($subject['legal_person'] ?? '')) !== '' && trim((string)($subject['legal_person'] ?? '')) !== $name) {
                $checks[] = ['key' => 'legal_person', 'label' => '法定代表人一致性', 'ok' => false, 'level' => 'error',
                    'note' => '法定代表人姓名与证件持有者姓名不一致'];
            }
            $imgPath  = (string)($subject['license_image'] ?? '');
            if ($imgPath === '') {
                $checks[] = ['key' => 'license_image', 'label' => '营业执照照片', 'ok' => false, 'level' => 'error', 'note' => '未上传营业执照照片'];
            } else {
                $checks[] = self::checkLicenseImage($imgPath);
            }
            $ocrNote = self::checkOcrConsistency($subject);
            if ($ocrNote !== null) {
                $checks[] = $ocrNote;
            }
        }

        // 汇总：error 级失败直接不通过；warn 级只记录不影响结果
        $fails = [];
        foreach ($checks as $c) {
            if (empty($c['ok']) && (string)($c['level'] ?? 'error') === 'error') {
                $fails[] = (string)$c['note'];
            }
        }
        $total  = max(1, count($checks));
        $passed = 0;
        foreach ($checks as $c) {
            if (!empty($c['ok'])) {
                $passed++;
            }
        }
        $score = (int)round($passed * 100 / $total);
        $pass  = empty($fails);
        if ($pass) {
            $score = max($score, 100);
        }
        $reason = $pass ? '' : implode('；', array_slice(array_unique($fails), 0, 3));

        return [
            'pass'     => $pass,
            'score'    => $score,
            'checks'   => $checks,
            'reason'   => $reason,
            'age'      => (int)($idRes['age'] ?? 0),
            'type'     => $type,
            'checked_at' => now(),
            'engine'   => 'yx-realname-audit/1.0',
        ];
    }

    /** 姓名规范性检测 */
    public static function checkName(string $name, string $label = '真实姓名'): array
    {
        $key = 'name';
        if ($name === '') {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => '请填写' . $label];
        }
        if (preg_match('/\s/u', $name)) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => $label . '不能包含空格'];
        }
        $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($len < 2 || $len > 16) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => $label . '长度应为 2-16 个汉字'];
        }
        // 只允许汉字与少数民族姓名间隔符「·」
        if (!preg_match('/^[\x{4e00}-\x{9fa5}·]+$/u', $name)) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => $label . '只能包含中文汉字，不能含字母、数字或符号'];
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        foreach (self::NAME_BLACKLIST as $word) {
            if ($word !== '' && strpos($lower, $word) !== false) {
                return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => $label . '疑似占位/测试内容，请填写真实姓名'];
            }
        }
        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $uniq  = array_unique($chars === false ? [] : $chars);
        if (count($uniq) <= 1) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => $label . '为重复字符，不是有效姓名'];
        }
        if (preg_match('/(.)\1{2,}/u', $name)) {
            return ['key' => $key, 'label' => $label, 'ok' => false, 'level' => 'error', 'note' => $label . '存在连续重复字符，不是有效姓名'];
        }
        return ['key' => $key, 'label' => $label, 'ok' => true, 'level' => 'ok', 'note' => '格式正常'];
    }

    /**
     * 身份证号检测（GB 11643-1999 校验位 + 行政区划 + 出生日期 + 年龄 + 顺序码）
     * @return array ['check'=>array,'age'=>int,'birth'=>'Y-m-d']
     */
    public static function checkIdCard(string $idc): array
    {
        $fail = function (string $note) {
            return ['check' => ['key' => 'id_card', 'label' => '身份证号', 'ok' => false, 'level' => 'error', 'note' => $note], 'age' => 0, 'birth' => ''];
        };
        if ($idc === '') {
            return $fail('请填写身份证号');
        }
        if (!preg_match('/^\d{17}[\dX]$/', $idc)) {
            return $fail('身份证号须为 18 位，最后一位可为 X');
        }
        if (!in_array(substr($idc, 0, 2), self::PROVINCES, true)) {
            return $fail('身份证号前两位行政区划代码不存在');
        }
        // 校验位（ISO 7064:1983 MOD 11-2）
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $map     = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum     = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int)$idc[$i] * $weights[$i];
        }
        if ($map[$sum % 11] !== $idc[17]) {
            return $fail('身份证号校验位不正确，请核对后重新填写');
        }
        $y = (int)substr($idc, 6, 4);
        $m = (int)substr($idc, 10, 2);
        $d = (int)substr($idc, 12, 2);
        if (!checkdate($m, $d, $y)) {
            return $fail('身份证号中的出生日期不合法');
        }
        $birth = sprintf('%04d-%02d-%02d', $y, $m, $d);
        if (strtotime($birth) === false || strtotime($birth) > time()) {
            return $fail('身份证号中的出生日期不合法');
        }
        $age = self::ageFrom($birth);
        if ($age < 18) {
            return ['check' => ['key' => 'age', 'label' => '年龄（须满 18 周岁）', 'ok' => false, 'level' => 'error',
                'note' => '身份证显示未满 18 周岁（' . $age . ' 岁），未满 18 周岁不可通过实名认证'], 'age' => $age, 'birth' => $birth];
        }
        if ($age > 120) {
            return ['check' => ['key' => 'age', 'label' => '年龄（须满 18 周岁）', 'ok' => false, 'level' => 'error',
                'note' => '身份证出生日期异常（超过 120 周岁）'], 'age' => $age, 'birth' => $birth];
        }
        if (substr($idc, 14, 3) === '000') {
            return ['check' => ['key' => 'id_card', 'label' => '身份证号', 'ok' => false, 'level' => 'error',
                'note' => '身份证号顺序码无效'], 'age' => $age, 'birth' => $birth];
        }
        if (preg_match('/^(\d)\1{16}$/', substr($idc, 0, 17))) {
            return ['check' => ['key' => 'id_card', 'label' => '身份证号', 'ok' => false, 'level' => 'error',
                'note' => '身份证号为无效的重复数字'], 'age' => $age, 'birth' => $birth];
        }
        return [
            'check' => ['key' => 'id_card', 'label' => '身份证号', 'ok' => true, 'level' => 'ok', 'note' => '证件号校验通过'],
            'age'   => $age,
            'birth' => $birth,
        ];
    }

    /** 由出生日期计算周岁 */
    private static function ageFrom(string $birth): int
    {
        $b = strtotime($birth);
        if ($b === false) {
            return 0;
        }
        $y = (int)date('Y', $b);
        $m = (int)date('m', $b);
        $d = (int)date('d', $b);
        $age = (int)date('Y') - $y;
        if ((int)date('m') < $m || ((int)date('m') === $m && (int)date('d') < $d)) {
            $age--;
        }
        return $age;
    }

    /** 企业名称检测 */
    public static function checkCompany(string $company): array
    {
        $key = 'company';
        if ($company === '') {
            return ['key' => $key, 'label' => '企业名称', 'ok' => false, 'level' => 'error', 'note' => '请填写企业名称'];
        }
        $len = function_exists('mb_strlen') ? mb_strlen($company, 'UTF-8') : strlen($company);
        if ($len < 4 || $len > 60) {
            return ['key' => $key, 'label' => '企业名称', 'ok' => false, 'level' => 'error', 'note' => '企业名称长度应为 4-60 个字符'];
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($company, 'UTF-8') : strtolower($company);
        foreach (self::COMPANY_BLACKLIST as $word) {
            if (strpos($lower, $word) !== false) {
                return ['key' => $key, 'label' => '企业名称', 'ok' => false, 'level' => 'error', 'note' => '企业名称疑似占位/测试内容，请填写营业执照上的企业全称'];
            }
        }
        $hit = false;
        foreach (self::COMPANY_KEYWORDS as $kw) {
            if (function_exists('mb_strpos') ? mb_strpos($company, $kw, 0, 'UTF-8') !== false : strpos($company, $kw) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return ['key' => $key, 'label' => '企业名称', 'ok' => false, 'level' => 'error', 'note' => '企业名称缺少组织形式（如「有限公司」「工作室」「中心」），请填写营业执照全称'];
        }
        return ['key' => $key, 'label' => '企业名称', 'ok' => true, 'level' => 'ok', 'note' => '格式正常'];
    }

    /** 统一社会信用代码检测（GB 32100-2015 校验位） */
    public static function checkCreditCode(string $code): array
    {
        $key = 'license_no';
        $fail = function (string $note) use ($key) {
            return ['key' => $key, 'label' => '统一社会信用代码', 'ok' => false, 'level' => 'error', 'note' => $note];
        };
        if ($code === '') {
            return $fail('请填写统一社会信用代码');
        }
        if (strlen($code) !== 18) {
            return $fail('统一社会信用代码须为 18 位');
        }
        $chars = self::CREDIT_CHARS;
        for ($i = 0; $i < 18; $i++) {
            if (strpos($chars, $code[$i]) === false) {
                return $fail('统一社会信用代码含非法字符（不含 I / O / S / V / Z）');
            }
        }
        if (!in_array($code[0], ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'Y', 'N'], true)) {
            return $fail('统一社会信用代码登记管理部门代码无效');
        }
        $weights = [1, 3, 9, 27, 19, 26, 16, 17, 20, 29, 25, 13, 8, 24, 10, 30, 28];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += strpos($chars, $code[$i]) * $weights[$i];
        }
        $mod   = 31 - $sum % 31;
        if ($mod === 31) {
            $mod = 0;
        }
        if ($chars[$mod] !== $code[17]) {
            return $fail('统一社会信用代码校验位不正确，请核对营业执照');
        }
        return ['key' => $key, 'label' => '统一社会信用代码', 'ok' => true, 'level' => 'ok', 'note' => '代码校验通过'];
    }

    /**
     * 营业执照图片检测：存在性 / 体积 / 像素尺寸 / 是否纯色空白
     * 未安装 GD 时降级为仅做尺寸与体积检测（记为提示，不影响结论）。
     */
    public static function checkLicenseImage(string $relPath): array
    {
        $key = 'license_image';
        $abs = YX_ROOT . '/uploads/' . ltrim(str_replace('\\', '/', $relPath), '/');
        if (strpos($relPath, '..') !== false || !is_file($abs)) {
            return ['key' => $key, 'label' => '营业执照照片', 'ok' => false, 'level' => 'error', 'note' => '营业执照图片不存在，请重新上传'];
        }
        $size = (int)@filesize($abs);
        if ($size < 3 * 1024) {
            return ['key' => $key, 'label' => '营业执照照片', 'ok' => false, 'level' => 'error', 'note' => '营业执照图片过小（小于 3KB），请上传清晰照片'];
        }
        $info = @getimagesize($abs);
        if (!$info || empty($info[0]) || empty($info[1])) {
            return ['key' => $key, 'label' => '营业执照照片', 'ok' => false, 'level' => 'error', 'note' => '营业执照图片无法解析，请上传 jpg / png / webp 图片'];
        }
        if ((int)$info[0] < 200 || (int)$info[1] < 150) {
            return ['key' => $key, 'label' => '营业执照照片', 'ok' => false, 'level' => 'error',
                'note' => '营业执照图片分辨率过低（' . (int)$info[0] . '×' . (int)$info[1] . '），请上传清晰照片'];
        }
        $blank = self::imageLooksBlank($abs);
        if ($blank === true) {
            return ['key' => $key, 'label' => '营业执照照片', 'ok' => false, 'level' => 'error', 'note' => '营业执照图片为空白/纯色图，请上传真实执照照片'];
        }
        return ['key' => $key, 'label' => '营业执照照片', 'ok' => true, 'level' => 'ok', 'note' => '图片检测正常'];
    }

    /**
     * 纯色/空白图检测：抽样计算灰度方差，方差过低即判定为空白。
     * @return bool|null true=疑似空白 false=正常 null=无法判断（无 GD 或解码失败）
     */
    public static function imageLooksBlank(string $abs)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecolorat')) {
            return null;
        }
        $raw = @file_get_contents($abs);
        if ($raw === false) {
            return null;
        }
        $im = @imagecreatefromstring($raw);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $sum = 0.0;
        $sum2 = 0.0;
        $n = 0;
        $stepX = max(1, (int)($w / 40));
        $stepY = max(1, (int)($h / 40));
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $sum  += $gray;
                $sum2 += $gray * $gray;
                $n++;
            }
        }
        imagedestroy($im);
        if ($n < 4) {
            return null;
        }
        $mean = $sum / $n;
        $var  = ($sum2 / $n) - ($mean * $mean);
        if ($var < 60) { // 灰度方差极小 → 纯色 / 空白
            return true;
        }
        return false;
    }

    /** 营业执照 OCR 结果与填写内容一致性（仅提示，不影响结论） */
    private static function checkOcrConsistency(array $subject): ?array
    {
        $ocr = trim((string)($subject['license_ocr'] ?? ''));
        if ($ocr === '') {
            return null;
        }
        $json = json_decode($ocr, true);
        if (!is_array($json)) {
            return null;
        }
        $ocrCompany = (string)($json['company'] ?? '');
        $ocrCode    = strtoupper((string)($json['license_no'] ?? ''));
        $company    = (string)($subject['company'] ?? '');
        $code       = strtoupper((string)($subject['license_no'] ?? ''));
        if ($ocrCompany !== '' && self::plainCompany($ocrCompany) !== self::plainCompany($company)) {
            return ['key' => 'ocr_company', 'label' => '执照识别一致性', 'ok' => false, 'level' => 'warn',
                'note' => '营业执照识别出的企业名称（' . $ocrCompany . '）与填写内容不一致，已记录待人工复核'];
        }
        if ($ocrCode !== '' && $ocrCode !== $code) {
            return ['key' => 'ocr_code', 'label' => '执照识别一致性', 'ok' => false, 'level' => 'warn',
                'note' => '营业执照识别出的信用代码与填写内容不一致，已记录待人工复核'];
        }
        return ['key' => 'ocr_company', 'label' => '执照识别一致性', 'ok' => true, 'level' => 'ok', 'note' => '与识别结果一致'];
    }

    /** 去除企业名称中的空白与括号差异，便于比较 */
    private static function plainCompany(string $s): string
    {
        $s = str_replace(['（', '）', '(', ')', ' ', '　', '·'], '', $s);
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}

/**
 * AI 智能自动监测驱动（默认）
 *
 * 不生成二维码：create() 时即时完成审核并给出 decision（verified / failed），
 * 由 YxRealname::start() / startForCustomer() 直接落库终态。
 */
class YxRealnameAuto implements YxRealnameDriver
{
    public function code(): string { return 'auto'; }
    public function name(): string { return 'AI 智能自动监测（内置，免密钥）'; }

    public function create(array $subject, string $requestId): array
    {
        $audit = YxRealnameAudit::run($subject);
        $ok    = !empty($audit['pass']);
        return [
            'ok'        => true,
            'qr_url'    => '',
            'expire_at' => now(),
            'decision'  => $ok ? 'verified' : 'failed',
            'message'   => $ok
                ? 'AI 智能检测通过（综合评分 ' . (int)$audit['score'] . ' 分）'
                : 'AI 智能检测未通过：' . (string)$audit['reason'],
            'raw'       => $audit,
        ];
    }

    /** AI 模式无需轮询：认证单在创建时即已终态 */
    public function query(string $requestId): array
    {
        return ['status' => 'pending'];
    }

    /** AI 模式不接受外部回调，避免伪造链接把认证单改成通过 */
    public function verifyCallback(array $params): array
    {
        return ['ok' => false, 'msg' => '当前为 AI 智能自动监测模式，不接受扫码回调'];
    }
}

/** 模拟核验驱动（历史兼容）：仅用于核销存量认证单，不再作为可选项 */
class YxRealnameMock implements YxRealnameDriver
{
    public function code(): string { return 'mock'; }
    public function name(): string { return '模拟核验（内置，免密钥）'; }

    public function create(array $subject, string $requestId): array
    {
        $qr = url('api/realname_notify.php?mock=1&request_id=' . urlencode($requestId) . '&sign=' . self::token($requestId));
        return ['ok' => true, 'qr_url' => $qr, 'expire_at' => date('Y-m-d H:i:s', time() + 600), 'msg' => '请使用手机扫码完成模拟核验'];
    }

    public function query(string $requestId): array
    {
        return ['status' => 'pending']; // 状态由回调写入
    }

    public function verifyCallback(array $params): array
    {
        $rid  = (string)($params['request_id'] ?? '');
        $sign = (string)($params['sign'] ?? '');
        if ($rid === '' || !hash_equals(self::token($rid), $sign)) {
            return ['ok' => false, 'msg' => '签名校验失败'];
        }
        return ['ok' => true, 'request_id' => $rid, 'status' => 'verified', 'message' => '模拟核验通过', 'raw' => $params];
    }

    /**
     * 二维码签名：HMAC(app.key, request_id)。
     *
     * 签名密钥来自 app.key（安装时随机生成），因此不可由外部推算。
     * 但签名只证明「这条链接是本站签发的」，不证明「现在还能核验」——
     * 有效期与终态由 YxRealname::claimForVerify() 在核销前判定。
     */
    private static function token(string $requestId): string
    {
        return hash_hmac('sha256', 'realname-mock|' . $requestId, (string)config('app.key', 'youxiang'));
    }
}

/** 通用 HTTP 服务商驱动（厂商协议按此适配，配置见后台「实名设置」） */
class YxRealnameHttp implements YxRealnameDriver
{
    public function code(): string { return 'http'; }
    public function name(): string { return '通用 HTTP 实名服务商'; }

    private function conf(): array
    {
        return [
            'endpoint' => rtrim((string)setting('realname_endpoint', ''), '/'),
            'appid'    => (string)setting('realname_appid', ''),
            'secret'   => (string)setting('realname_secret', ''),
        ];
    }

    private function sign(array $data, string $secret): string
    {
        unset($data['sign']);
        $data = array_filter($data, static fn($v) => $v !== '' && $v !== null);
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        return md5(implode('&', $pairs) . $secret);
    }

    public function create(array $subject, string $requestId): array
    {
        $c = $this->conf();
        if ($c['endpoint'] === '' || $c['appid'] === '' || $c['secret'] === '') {
            return ['ok' => false, 'msg' => '实名服务商未配置（后台 → 开发者中心 → 实名设置）'];
        }
        $payload = array_merge(['appid' => $c['appid'], 'request_id' => $requestId], $subject);
        $payload['sign'] = $this->sign($payload, $c['secret']);

        [$ok, , $body] = http_post_json($c['endpoint'] . '/create', $payload);
        if (!$ok) {
            return ['ok' => false, 'msg' => '实名服务不可用：' . $body];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '实名服务返回格式异常'];
        }
        $data = $json['data'] ?? $json;
        $qr   = (string)($data['qr_url'] ?? $data['qr_code'] ?? '');
        if ($qr === '') {
            return ['ok' => false, 'msg' => (string)($json['msg'] ?? '实名下单失败')];
        }
        return [
            'ok'        => true,
            'qr_url'    => $qr,
            'expire_at' => $data['expire_at'] ?? date('Y-m-d H:i:s', time() + 600),
            'msg'       => '请扫码完成实名核验',
            'raw'       => $json,
        ];
    }

    public function query(string $requestId): array
    {
        $c = $this->conf();
        if ($c['endpoint'] === '') {
            return ['status' => 'pending'];
        }
        $payload = ['appid' => $c['appid'], 'request_id' => $requestId];
        $payload['sign'] = $this->sign($payload, $c['secret']);

        [$ok, , $body] = http_post_json($c['endpoint'] . '/query', $payload);
        if (!$ok) {
            return ['status' => 'pending', 'message' => (string)$body];
        }
        $json = json_decode((string)$body, true) ?: [];
        $data = $json['data'] ?? $json;
        return [
            'status'  => (string)($data['status'] ?? 'pending'),
            'message' => (string)($data['message'] ?? ''),
            'raw'     => $json,
        ];
    }

    public function verifyCallback(array $params): array
    {
        $c    = $this->conf();
        $sign = (string)($params['sign'] ?? '');
        if ($sign === '' || !hash_equals($this->sign($params, $c['secret']), $sign)) {
            return ['ok' => false, 'msg' => '回调验签失败'];
        }
        $rid = (string)($params['request_id'] ?? '');
        if ($rid === '') {
            return ['ok' => false, 'msg' => '回调缺少 request_id'];
        }
        $status = (string)($params['status'] ?? '');
        return [
            'ok'         => true,
            'request_id' => $rid,
            'status'     => $status === 'verified' ? 'verified' : 'failed',
            'message'    => (string)($params['message'] ?? ''),
            'raw'        => $params,
        ];
    }

    /** 营业执照 OCR：POST {endpoint}/license_ocr，返回 data.{company,license_no,legal_person} */
    public function ocr(string $relPath): array
    {
        $c = $this->conf();
        if ($c['endpoint'] === '' || $c['appid'] === '' || $c['secret'] === '') {
            return ['ok' => false, 'msg' => '实名服务商未配置，请手动填写企业信息'];
        }
        $abs = YX_ROOT . '/uploads/' . ltrim($relPath, '/');
        if (!is_file($abs)) {
            return ['ok' => false, 'msg' => '营业执照图片不存在，请重新上传'];
        }
        $payload = [
            'appid'   => $c['appid'],
            'biz'     => 'license_ocr',
            'file'    => base64_encode((string)file_get_contents($abs)),
            'mime'    => (string)(@finfo_file(finfo_open(FILEINFO_MIME_TYPE), $abs) ?: 'image/jpeg'),
            'request_id' => 'OC' . date('ymdHis') . bin2hex(random_bytes(4)),
        ];
        $payload['sign'] = $this->sign($payload, $c['secret']);

        [$ok, , $body] = http_post_json($c['endpoint'] . '/license_ocr', $payload, [], 20);
        if (!$ok) {
            return ['ok' => false, 'msg' => '识别服务不可用：' . $body];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '识别服务返回格式异常'];
        }
        $data = $json['data'] ?? $json;
        if (!empty($json['code']) && (string)$json['code'] !== '0' && empty($data['license_no']) && empty($data['company'])) {
            return ['ok' => false, 'msg' => (string)($json['msg'] ?? '未识别到营业执照信息')];
        }
        $out = [
            'ok'           => true,
            'company'      => trim((string)($data['company'] ?? '')),
            'license_no'   => strtoupper(trim((string)($data['license_no'] ?? $data['credit_code'] ?? ''))),
            'legal_person' => trim((string)($data['legal_person'] ?? $data['legal'] ?? '')),
            'raw'          => $json,
        ];
        if ($out['company'] === '' && $out['license_no'] === '') {
            return ['ok' => false, 'msg' => '未识别到营业执照信息，请手动填写'];
        }
        return $out;
    }
}

class YxRealname
{
    const TYPES = ['personal' => '个人实名', 'enterprise' => '企业实名'];

    public static function typeName(string $t): string
    {
        return self::TYPES[$t] ?? $t;
    }

    /** 实名状态中文名（customer.realname_status） */
    public static function statusName(string $s): string
    {
        $map = [
            'none'     => '未认证',
            'pending'  => '认证中',
            'verified' => '已认证',
            'rejected' => '认证失败',
            'expired'  => '已过期',
        ];
        return $map[$s] ?? ($s === '' ? '未认证' : $s);
    }

    public static function drivers(): array
    {
        return [
            'auto' => 'AI 智能自动监测（内置，免密钥）',
            'http' => '通用 HTTP 实名服务商',
        ];
    }

    /** 当前服务商（历史配置为 mock 时自动升级为 AI 自动监测） */
    public static function provider(): string
    {
        $p = (string)setting('realname_provider', 'auto');
        return array_key_exists($p, self::drivers()) ? $p : 'auto';
    }

    /** 是否为 AI 自动监测（即时出结果，无需扫码） */
    public static function isAuto(string $provider = null): bool
    {
        return ($provider ?? self::provider()) === 'auto';
    }

    public static function driver(string $provider = null): YxRealnameDriver
    {
        $p = $provider ?? self::provider();
        if ($p === 'http') {
            return new YxRealnameHttp();
        }
        // 仅存量待核销的 mock 认证单会走到这里；新单一律走 AI 自动监测
        return $p === 'mock' ? new YxRealnameMock() : new YxRealnameAuto();
    }

    /** 校验并规范化提交的实名资料 */
    public static function validate(array $in): array
    {
        return self::validateType(self::normalizeType($in['dev_type'] ?? 'personal'), $in);
    }

    /**
     * 按指定类型校验资料（入驻档案类型受限时使用）。
     * 企业认证必须提供营业执照（图片或已上传路径），并落库企业名称 / 统一社会信用代码 / 法人信息。
     */
    public static function validateType(string $type, array $in): array
    {
        $type    = $type === 'enterprise' ? 'enterprise' : 'personal';
        $name    = trim((string)($in['name'] ?? ''));
        $idCard  = strtoupper(trim((string)($in['id_card'] ?? '')));

        if ($type === 'personal') {
            if ($name === '') {
                return ['ok' => false, 'msg' => '请填写真实姓名'];
            }
            if (!preg_match('/^\d{17}[\dX]$/', $idCard)) {
                return ['ok' => false, 'msg' => '请输入 18 位有效身份证号'];
            }
            return ['ok' => true, 'subject' => ['dev_type' => 'personal', 'name' => $name, 'id_card' => $idCard]];
        }

        $company = trim((string)($in['company'] ?? ''));
        $license = strtoupper(trim((string)($in['license_no'] ?? '')));
        $legal   = trim((string)($in['legal_person'] ?? ''));
        // 先校验文字信息，再校验营业执照图片，报错顺序与用户填写顺序一致
        if ($company === '') {
            return ['ok' => false, 'msg' => '请填写企业名称'];
        }
        if (!preg_match('/^[0-9A-HJ-NPQRTUWXY]{18}$/', $license)) {
            return ['ok' => false, 'msg' => '请输入 18 位统一社会信用代码'];
        }
        if ($legal === '') {
            return ['ok' => false, 'msg' => '请填写法定代表人姓名'];
        }
        if (!preg_match('/^\d{17}[\dX]$/', $idCard)) {
            return ['ok' => false, 'msg' => '请输入法定代表人 18 位有效身份证号'];
        }
        $image = self::saveLicenseImage($in);
        if (empty($image['ok'])) {
            return ['ok' => false, 'msg' => (string)($image['msg'] ?? '营业执照上传失败')];
        }
        return ['ok' => true, 'subject' => [
            'dev_type'      => 'enterprise',
            'name'          => $legal,
            'id_card'       => $idCard,
            'company'       => $company,
            'license_no'    => $license,
            'legal_person'  => $legal,
            'license_image' => (string)$image['path'],
            // 营业执照 OCR 结果（识别经用户确认、提交即视为确认）
            'license_ocr'   => trim((string)($in['license_ocr'] ?? '')),
        ]];
    }

    /** 认证类型归一化 */
    public static function normalizeType($v): string
    {
        return (string)$v === 'enterprise' ? 'enterprise' : 'personal';
    }

    /**
     * 营业执照图片：优先用本次上传的 license_image 文件（$files 或 $_FILES），
     * 否则沿用此前已上传的相对路径（license_image_path，仅限 uploads/realname/ 下）。
     * 返回 ['path'=>..] 或 ['error'=>..]；两者皆无时返回空数组（交由 validateType 判定是否必需）。
     */
    private static function takeLicenseImage(array &$in, array $files = []): array
    {
        // 先看是否已有合法路径（前一步 OCR 上传回填 / 同一请求内已保存过），避免重复落盘
        $path = ltrim(str_replace('\\', '/', trim((string)($in['license_image_path'] ?? ''))), '/');
        if ($path !== ''
            && strpos($path, 'realname/') === 0
            && strpos($path, '..') === false
            && is_file(YX_ROOT . '/uploads/' . $path)) {
            return ['path' => $path];
        }

        $file = $files['license_image'] ?? ($_FILES['license_image'] ?? null);
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = self::storeLicenseImage($file);
            if (empty($res['ok'])) {
                return ['error' => (string)($res['msg'] ?? '营业执照上传失败')];
            }
            // 回写路径：调用方（start/startForCustomer）随后交给 validateType 复用时不再二次上传
            $in['license_image_path'] = (string)$res['path'];
            return ['path' => (string)$res['path']];
        }

        return [];
    }

    /** 营业执照图片相对路径（受 takeLicenseImage 约束） */
    private static function saveLicenseImage(array &$in): array
    {
        $r = self::takeLicenseImage($in);
        if (isset($r['error'])) {
            return ['ok' => false, 'msg' => $r['error']];
        }
        if (empty($r['path'])) {
            return ['ok' => false, 'msg' => '请上传营业执照照片（jpg / png / webp，≤5MB）'];
        }
        return ['ok' => true, 'path' => (string)$r['path']];
    }

    /** 保存营业执照图片，返回 uploads/ 下的相对路径（realname/xxx.jpg） */
    public static function storeLicenseImage($file): array
    {
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'msg' => '请选择营业执照照片'];
        }
        return yx_upload($file, [
            'sub'      => 'realname',
            'prefix'   => 'license',
            'exts'     => ['jpg', 'jpeg', 'png', 'webp'],
            'max_size' => 5 * 1024 * 1024,
        ]);
    }

    /**
     * 营业执照 OCR / 工商信息核验（供用户中心上传页调用）
     * 通用 HTTP 驱动：POST {endpoint}/license_ocr {...sign}，返回 data.{company,license_no,legal_person}
     * 未配置服务商时返回 ok=false，前端保留手动填写
     */
    public static function ocrLicense(string $path): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, 'realname/') !== 0 || strpos($path, '..') !== false || !is_file(YX_ROOT . '/uploads/' . $path)) {
            return ['ok' => false, 'msg' => '营业执照图片不存在，请重新上传'];
        }
        if (self::provider() !== 'http') {
            return ['ok' => false, 'msg' => '未接入营业执照识别服务，请手动填写企业信息'];
        }
        $driver = self::driver('http');
        if (!method_exists($driver, 'ocr')) {
            return ['ok' => false, 'msg' => '服务商不支持营业执照识别，请手动填写'];
        }
        $res = $driver->ocr($path);
        log_action('realname.ocr', $path, ['ok' => !empty($res['ok'])]);
        return $res;
    }

    /** 发起实名认证，返回认证单（AI 模式为即时审核结果，无二维码） */
    public static function start(int $customerId, int $developerId, array $in, array $files = []): array
    {
        // 同 startForCustomer：已有待完成核验时直接复用，避免重复落盘与重复建单
        if ($customerId > 0) {
            $pending = self::pendingForCustomer($customerId);
            if ($pending) {
                return [
                    'ok'         => true,
                    'msg'        => '已有一笔待完成的核验，请继续扫码',
                    'id'         => (int)$pending['id'],
                    'request_id' => (string)$pending['request_id'],
                    'qr_url'     => (string)$pending['qr_url'],
                    'reused'     => true,
                ];
            }
        }
        $up = self::takeLicenseImage($in, $files);
        if (!empty($up['error'])) {
            return ['ok' => false, 'msg' => $up['error']];
        }
        $chk = self::validate($in);
        if (empty($chk['ok'])) {
            return $chk;
        }
        $subject = $chk['subject'];
        // 企业实名：营业执照图片必须存在（本次上传或此前已上传路径），核验时一并留档
        if ($subject['dev_type'] === 'enterprise' && empty($subject['license_image'])) {
            return ['ok' => false, 'msg' => '企业实名请上传营业执照图片'];
        }
        $provider  = self::provider();
        $requestId = 'RN' . date('ymdHis') . bin2hex(random_bytes(4));

        $create = self::driver($provider)->create($subject, $requestId);
        if (empty($create['ok'])) {
            return ['ok' => false, 'msg' => $create['msg'] ?? '实名审核失败'];
        }
        $auto = self::isAuto($provider);

        $id = YxDb::insert('developer_realname', [
            'customer_id'   => $customerId,
            'developer_id'  => $developerId,
            'dev_type'      => $subject['dev_type'],
            'provider'      => $provider,
            'request_id'    => $requestId,
            'qr_url'        => (string)($create['qr_url'] ?? ''),
            'status'        => 'pending',
            'name'          => $subject['name'] ?? null,
            'id_card'       => !empty($subject['id_card']) ? yx_encrypt((string)$subject['id_card']) : null,
            'company'       => $subject['company'] ?? null,
            'license_no'    => $subject['license_no'] ?? null,
            'legal_person'  => $subject['legal_person'] ?? null,
            'license_image' => $subject['license_image'] ?? null,
            'result_raw'    => self::packRaw($subject, $create),
            'message'       => (string)($create['msg'] ?? ''),
            'expire_at'     => (string)($create['expire_at'] ?? date('Y-m-d H:i:s', time() + 600)),
            'created_at'    => now(),
        ]);

        // AI 自动监测：创建即出结果，直接落终态并按结果联动档案
        if ($auto && !empty($create['decision'])) {
            $applied = self::applyResult($requestId, (string)$create['decision'], (string)($create['message'] ?? ''), (array)($create['raw'] ?? []));
            return [
                'ok'         => true,
                'msg'        => (string)($create['message'] ?? ''),
                'id'         => $id,
                'request_id' => $requestId,
                'qr_url'     => '',
                'auto'       => true,
                'decision'   => (string)$create['decision'],
                'status'     => (string)($applied['status'] ?? $create['decision']),
                'verified'   => (string)$create['decision'] === 'verified',
                'audit'      => (array)($create['raw'] ?? []),
            ];
        }

        YxDb::update('developer', ['realname_status' => 'pending', 'type' => $subject['dev_type'], 'updated_at' => now()], 'id = :id', ['id' => $developerId]);

        return ['ok' => true, 'msg' => '请扫码完成实名核验', 'id' => $id, 'request_id' => $requestId, 'qr_url' => (string)($create['qr_url'] ?? '')];
    }

    /**
     * 用户中心实名认证：认证单挂在客户账号上（developer_id = 0），
     * 若该客户已有开发者档案则一并关联，避免与开发者中心流程割裂。
     * @param string $lockedType 档案已定类型时限制认证类型（如企业开发者不可改个人）
     */
    public static function startForCustomer(int $customerId, array $in, string $lockedType = '', array $files = []): array
    {
        $type = $lockedType !== '' ? self::normalizeType($lockedType) : self::normalizeType($in['dev_type'] ?? 'personal');
        // 已认证账号不允许重复发起，实名信息只能由核验结果写入
        $cur = YxDb::one('SELECT realname_status, realname_type FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
        if ($cur && (string)$cur['realname_status'] === 'verified') {
            return ['ok' => false, 'msg' => '当前账号已完成' . self::typeName((string)$cur['realname_type']) . '，无需重复认证'];
        }

        // 已有待完成的核验：不重复下单，直接沿用原二维码。
        // 必须放在「上传营业执照 / 建认证单」之前：否则每次点提交都会先落一份图片、
        // 再返回复用，留下永远没人引用的孤儿文件。
        $pending = self::pendingForCustomer($customerId);
        if ($pending) {
            return [
                'ok'         => true,
                'msg'        => '已有一笔待完成的核验，请继续扫码',
                'id'         => (int)$pending['id'],
                'request_id' => (string)$pending['request_id'],
                'qr_url'     => (string)$pending['qr_url'],
                'reused'     => true,
            ];
        }

        $up   = self::takeLicenseImage($in, $files);
        if (!empty($up['error'])) {
            return ['ok' => false, 'msg' => $up['error']];
        }
        $chk  = self::validateType($type, $in);
        if (empty($chk['ok'])) {
            return $chk;
        }
        $subject = $chk['subject'];
        if ($subject['dev_type'] === 'enterprise' && empty($subject['license_image'])) {
            return ['ok' => false, 'msg' => '企业实名请上传营业执照图片'];
        }
        $provider  = self::provider();
        $requestId = 'RN' . date('ymdHis') . bin2hex(random_bytes(4));

        $create = self::driver($provider)->create($subject, $requestId);
        if (empty($create['ok'])) {
            return ['ok' => false, 'msg' => $create['msg'] ?? '实名审核失败'];
        }
        $auto = self::isAuto($provider);

        $dev   = YxDeveloper::profile($customerId);
        $devId = $dev ? (int)$dev['id'] : 0;

        $id = YxDb::insert('developer_realname', [
            'customer_id'   => $customerId,
            'developer_id'  => $devId,
            'dev_type'      => $subject['dev_type'],
            'provider'      => $provider,
            'request_id'    => $requestId,
            'qr_url'        => (string)($create['qr_url'] ?? ''),
            'status'        => 'pending',
            'name'          => $subject['name'] ?? null,
            'id_card'       => !empty($subject['id_card']) ? yx_encrypt((string)$subject['id_card']) : null,
            'company'       => $subject['company'] ?? null,
            'license_no'    => $subject['license_no'] ?? null,
            'legal_person'  => $subject['legal_person'] ?? null,
            'license_image' => $subject['license_image'] ?? null,
            'result_raw'    => self::packRaw($subject, $create),
            'message'       => (string)($create['msg'] ?? ''),
            'expire_at'     => (string)($create['expire_at'] ?? date('Y-m-d H:i:s', time() + 600)),
            'created_at'    => now(),
        ]);

        // AI 自动监测：创建即出结论，落终态并联动客户 / 开发者档案
        if ($auto && !empty($create['decision'])) {
            $decision = (string)$create['decision'];
            $applied  = self::applyResult($requestId, $decision, (string)($create['message'] ?? ''), (array)($create['raw'] ?? []));
            return [
                'ok'         => true,
                'msg'        => (string)($create['message'] ?? ''),
                'id'         => $id,
                'request_id' => $requestId,
                'qr_url'     => '',
                'auto'       => true,
                'decision'   => $decision,
                'status'     => (string)($applied['status'] ?? $decision),
                'verified'   => $decision === 'verified',
                'audit'      => (array)($create['raw'] ?? []),
            ];
        }

        if ($devId > 0) {
            YxDb::update('developer', ['realname_status' => 'pending', 'type' => $subject['dev_type'], 'updated_at' => now()], 'id = :id', ['id' => $devId]);
        }

        return ['ok' => true, 'msg' => '请扫码完成实名核验', 'id' => $id, 'request_id' => $requestId, 'qr_url' => (string)($create['qr_url'] ?? '')];
    }

    /** 认证单附加信息：营业执照识别结果 + AI 审核明细 */
    private static function packRaw(array $subject, array $create): ?string
    {
        $raw = [];
        if (!empty($subject['license_ocr'])) {
            $raw['license_ocr'] = (string)$subject['license_ocr'];
        }
        if (isset($create['raw']) && is_array($create['raw']) && $create['raw']) {
            $raw['audit'] = $create['raw'];
        }
        return $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * 处理回调（mock=1 时为模拟扫码）
     *
     * 签名只证明链接由本站签发，是否还能核销必须回查认证单：
     *   - 认证单必须由回调声明的驱动签发（mock 链接不能核销真实服务商的单）
     *   - 已过期 / 已失败的认证单不再接受「通过」，重放旧链接无法复现认证
     */
    public static function handleCallback(array $params, bool $mock = false): array
    {
        $provider = $mock ? 'mock' : self::provider();
        $driver   = self::driver($provider);
        $res      = $driver->verifyCallback($params);
        if (empty($res['ok'])) {
            return $res;
        }

        $requestId = (string)$res['request_id'];
        $row = YxDb::one(
            'SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE request_id = ?',
            [$requestId]
        );
        if (!$row) {
            return ['ok' => false, 'msg' => '认证单不存在'];
        }
        if ((string)$row['provider'] !== $provider) {
            return ['ok' => false, 'msg' => '认证单与回调用渠道不一致'];
        }
        if ((string)$res['status'] === 'verified') {
            // 先把超时未完成的单置为 expired，再判定——过期的二维码不应当还能通过
            $row = self::expireIfTimeout($row);
            if (in_array((string)$row['status'], ['expired', 'failed'], true)) {
                return [
                    'ok'     => false,
                    'status' => (string)$row['status'],
                    'msg'    => (string)$row['status'] === 'expired' ? '核验已超时，请重新发起实名认证' : '该认证单已失效，请重新发起实名认证',
                ];
            }
        }

        return self::applyResult($requestId, (string)$res['status'], (string)($res['message'] ?? ''), (array)($res['raw'] ?? []));
    }

    /** 轮询服务商状态（含过期判定：超时未完成则自动置为过期，允许重新发起） */
    public static function poll(string $requestId): array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE request_id = ?', [$requestId]);
        if (!$row) {
            return ['ok' => false, 'msg' => '认证单不存在'];
        }
        $row = self::expireIfTimeout($row);
        if (in_array($row['status'], ['verified', 'failed', 'expired'], true)) {
            return ['ok' => true, 'status' => $row['status'], 'msg' => (string)($row['message'] ?? '')];
        }
        $q = self::driver((string)$row['provider'])->query($requestId);
        if (!empty($q['status']) && $q['status'] !== 'pending') {
            return self::applyResult($requestId, (string)$q['status'], (string)($q['message'] ?? ''), (array)($q['raw'] ?? []));
        }
        return ['ok' => true, 'status' => $row['status'], 'msg' => (string)($row['message'] ?? '')];
    }

    /** 待完成且已超时的认证单置为 expired（避免残留 pending 永久阻塞重新发起） */
    private static function expireIfTimeout(array $row): array
    {
        if ((string)$row['status'] !== 'pending') {
            return $row;
        }
        $expire = (string)($row['expire_at'] ?? '');
        if ($expire === '' || strtotime($expire) === false || strtotime($expire) >= time()) {
            return $row;
        }
        $row['status']  = 'expired';
        $row['message'] = '核验超时，请重新发起实名认证';
        self::applyResult((string)$row['request_id'], 'expired', $row['message'], []);
        return $row;
    }

    /** 最近一笔认证单（用户中心） */
    public static function latestForCustomer(int $customerId): ?array
    {
        $row = YxDb::one(
            'SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE customer_id = ? ORDER BY id DESC LIMIT 1',
            [$customerId]
        );
        return $row ? self::expireIfTimeout($row) : null;
    }

    /** 最近一笔待完成认证单（用户中心 / 开发者中心展示续扫入口） */
    public static function pendingForCustomer(int $customerId): ?array
    {
        $row = YxDb::one(
            'SELECT * FROM ' . YxDb::table('developer_realname') . " WHERE customer_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
            [$customerId]
        );
        if (!$row) {
            return null;
        }
        $row = self::expireIfTimeout($row);
        return (string)$row['status'] === 'pending' ? $row : null;
    }

    /** 落库认证结果并联动客户 / 开发者档案 */
    private static function applyResult(string $requestId, string $status, string $message, array $raw): array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('developer_realname') . ' WHERE request_id = ?', [$requestId]);
        if (!$row) {
            return ['ok' => false, 'msg' => '认证单不存在'];
        }
        if ($row['status'] === 'verified') {
            return ['ok' => true, 'status' => 'verified', 'msg' => '已完成实名', 'repeat' => true];
        }
        // 已过期 / 已失败的认证单不再接受「通过」：链接可能被转发、重放，
        // 用户需要重新发起一笔新的认证（此时会生成新的 request_id）。
        if ($status === 'verified' && in_array((string)$row['status'], ['expired', 'failed'], true)) {
            return ['ok' => false, 'status' => (string)$row['status'], 'msg' => '该认证单' . ((string)$row['status'] === 'expired' ? '已过期' : '已失效') . '，请重新发起实名认证'];
        }

        $status = in_array($status, ['verified', 'failed', 'expired'], true) ? $status : 'pending';
        if ($status !== 'pending') {
            $patch = [
                'status'      => $status,
                'message'     => $message !== '' ? $message : $row['message'],
                'verified_at' => $status === 'verified' ? now() : null,
            ];
            if ($raw) {
                // AI 审核明细与营业执照识别结果可能同存，合并写入不覆盖
                $old = json_decode((string)($row['result_raw'] ?? ''), true);
                $old = is_array($old) ? $old : [];
                $patch['result_raw'] = json_encode(array_merge($old, $raw), JSON_UNESCAPED_UNICODE);
            }
            YxDb::update('developer_realname', $patch, 'id = :id', ['id' => (int)$row['id']]);
        }

        if ($status === 'verified') {
            self::markCustomerVerified((int)$row['customer_id'], $row);
            if ((int)$row['developer_id'] > 0) {
                YxDeveloper::markRealnameVerified((int)$row['developer_id'], $row);
            } else {
                // 认证单挂在账号上，若其后已成为开发者，同步档案状态
                $dev = YxDeveloper::profile((int)$row['customer_id']);
                if ($dev) {
                    YxDeveloper::markRealnameVerified((int)$dev['id'], $row);
                }
            }
        } elseif ($status === 'failed' || $status === 'expired') {
            self::markCustomerFailed((int)$row['customer_id'], (string)$row['dev_type']);
        }

        return ['ok' => true, 'status' => $status, 'msg' => $message];
    }

    /** 认证通过：写回客户账号实名状态（身份证密文落库，不存明文） */
    private static function markCustomerVerified(int $customerId, array $rn): void
    {
        if ($customerId <= 0) {
            return;
        }
        $type = self::normalizeType($rn['dev_type'] ?? 'personal');
        $patch = [
            'realname_status' => 'verified',
            'realname_type'   => $type,
            'verified_at'     => now(),
        ];
        if ($type === 'enterprise') {
            $patch['realname']     = (string)($rn['legal_person'] ?? '');
            $patch['company']      = (string)($rn['company'] ?? '');
            $patch['license_no']   = (string)($rn['license_no'] ?? '');
            $patch['legal_person'] = (string)($rn['legal_person'] ?? '');
            $patch['license_image'] = (string)($rn['license_image'] ?? '');
        } else {
            $patch['realname'] = (string)($rn['name'] ?? '');
        }
        if (!empty($rn['id_card'])) {
            $patch['idcard'] = (string)$rn['id_card']; // 已是密文
        }
        YxDb::update('customer', $patch, 'id = :id', ['id' => $customerId]);
    }

    /** 认证失败 / 过期：回写未通过状态，允许用户重新发起 */
    private static function markCustomerFailed(int $customerId, string $type): void
    {
        if ($customerId <= 0) {
            return;
        }
        $row = YxDb::one('SELECT id, realname_status FROM ' . YxDb::table('customer') . ' WHERE id = ?', [$customerId]);
        if (!$row || (string)$row['realname_status'] === 'verified') {
            return;
        }
        YxDb::update('customer', [
            'realname_status' => 'none',
            'realname_type'   => self::normalizeType($type),
        ], 'id = :id', ['id' => $customerId]);
    }
}
