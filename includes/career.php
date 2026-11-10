<?php
/**
 * 优享财务系统 · 招聘（职位管理 + 简历投递）
 *
 * 设计要点：
 *   - 职位存 yx_career_job，前台「加入我们」与招聘页按 sort 顺序展示已发布职位
 *   - 简历存 yx_resume，投递成功生成编号（RS 前缀），投递人可凭编号 + 手机号查询进度
 *   - 附件只允许 pdf/doc/docx，落盘 uploads/resume/，文件名随机化，不信任原始文件名
 *   - 同一 IP 10 分钟内最多投递 3 次，防止表单被刷
 */

class YxCareer
{
    /** 工作性质 */
    const JOB_TYPES = [
        'fulltime' => '全职',
        'parttime' => '兼职',
        'intern'   => '实习',
    ];

    /** 招聘流程状态（后台流转，对投递人可见） */
    const STATUSES = [
        'new'       => '待筛选',
        'screening' => '筛选中',
        'interview' => '面试中',
        'offer'     => '已发offer',
        'hired'     => '已入职',
        'rejected'  => '未通过',
    ];

    /** 未结束状态 */
    const OPEN_STATUSES = ['new', 'screening', 'interview', 'offer'];

    /** 简历附件限制 */
    const FILE_EXTS = ['pdf', 'doc', 'docx'];
    const FILE_MAX  = 10 * 1024 * 1024; // 10 MB

    /* ------------------------------ 名称映射 ------------------------------ */

    public static function jobTypeName(string $t): string
    {
        return self::JOB_TYPES[$t] ?? $t;
    }

    public static function statusName(string $s): string
    {
        return self::STATUSES[$s] ?? $s;
    }

    public static function statusBadge(string $s): string
    {
        switch ($s) {
            case 'new':       return 'badge-warn';
            case 'screening': return 'badge-info';
            case 'interview': return 'badge-info';
            case 'offer':     return 'badge-ok';
            case 'hired':     return 'badge-ok';
            case 'rejected':  return 'badge-mute';
            default:          return 'badge-mute';
        }
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }

    /* ------------------------------ 职位 ------------------------------ */

    /** 前台可选职位：status=1 且按 sort 升序 */
    public static function jobs(int $limit = 0): array
    {
        try {
            $sql = 'SELECT * FROM ' . YxDb::table('career_job') . ' WHERE status = 1 ORDER BY sort ASC, id ASC';
            if ($limit > 0) {
                $sql .= ' LIMIT ' . max(1, min(200, $limit));
            }
            return YxDb::all($sql);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 后台职位列表（含下线） */
    public static function allJobs(): array
    {
        try {
            return YxDb::all('SELECT * FROM ' . YxDb::table('career_job') . ' ORDER BY sort ASC, id ASC');
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function job(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('career_job') . ' WHERE id = ? LIMIT 1', [$id]);
        return $r ?: null;
    }

    /**
     * 新增 / 更新职位（后台）
     * @return array ['ok','msg','id']
     */
    public static function saveJob(array $data, int $id = 0): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            return ['ok' => false, 'msg' => '职位名称需 2-120 个字'];
        }

        $jobType = (string)($data['job_type'] ?? 'fulltime');
        if (!isset(self::JOB_TYPES[$jobType])) {
            $jobType = 'fulltime';
        }

        $contact = trim((string)($data['contact'] ?? ''));
        if ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '简历接收邮箱格式不正确'];
        }

        $row = [
            'category'    => self::clip($data['category'] ?? '', 40) ?? '其他',
            'title'       => $title,
            'city'        => self::clip($data['city'] ?? '', 40),
            'job_type'    => $jobType,
            'experience'  => self::clip($data['experience'] ?? '', 40),
            'education'   => self::clip($data['education'] ?? '', 40),
            'salary'      => self::clip($data['salary'] ?? '', 40),
            'headcount'   => max(0, min(999, (int)($data['headcount'] ?? 1))),
            'description' => self::clipText($data['description'] ?? '', 8000),
            'requirement' => self::clipText($data['requirement'] ?? '', 8000),
            'contact'     => $contact !== '' ? $contact : null,
            'sort'        => max(-9999, min(99999, (int)($data['sort'] ?? 0))),
            'status'      => (int)($data['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at'  => now(),
        ];

        if ($id > 0) {
            if (!self::job($id)) {
                return ['ok' => false, 'msg' => '职位不存在'];
            }
            YxDb::update('career_job', $row, 'id = :id', ['id' => $id]);
            log_action('career.job.update', 'job#' . $id, ['title' => $title]);
            return ['ok' => true, 'msg' => '职位已更新', 'id' => $id];
        }

        $row['created_at'] = now();
        $newId = YxDb::insert('career_job', $row);
        log_action('career.job.create', 'job#' . $newId, ['title' => $title]);
        return ['ok' => true, 'msg' => '职位已添加', 'id' => $newId];
    }

    /** 删除职位（已有投递记录的职位不允许删除，改为下线） */
    public static function removeJob(int $id): array
    {
        $job = self::job($id);
        if (!$job) {
            return ['ok' => false, 'msg' => '职位不存在'];
        }
        $used = (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('resume') . ' WHERE job_id = ?',
            [$id]
        );
        if ($used > 0) {
            return ['ok' => false, 'msg' => '该职位已有 ' . $used . ' 份投递记录，不能删除；可将状态改为「停止招聘」'];
        }
        YxDb::delete('career_job', 'id = :id', ['id' => $id]);
        log_action('career.job.delete', 'job#' . $id, ['title' => (string)$job['title']]);
        return ['ok' => true, 'msg' => '职位已删除'];
    }

    public static function toggleJob(int $id): array
    {
        $job = self::job($id);
        if (!$job) {
            return ['ok' => false, 'msg' => '职位不存在'];
        }
        $next = (int)$job['status'] === 1 ? 0 : 1;
        YxDb::update('career_job', ['status' => $next, 'updated_at' => now()], 'id = :id', ['id' => $id]);
        log_action('career.job.toggle', 'job#' . $id, ['status' => $next]);
        return ['ok' => true, 'msg' => $next === 1 ? '职位已上线' : '职位已下线'];
    }

    /** 已使用的职位方向（后台下拉建议） */
    public static function categories(): array
    {
        $rows = YxDb::all(
            'SELECT DISTINCT category FROM ' . YxDb::table('career_job')
            . ' WHERE category IS NOT NULL AND category <> \'\' ORDER BY category ASC'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string)$r['category'];
        }
        return $out;
    }

    /** 按方向分组，保持 sort 顺序：['产品与设计' => [job, ...]] */
    public static function grouped(array $jobs): array
    {
        $out = [];
        foreach ($jobs as $j) {
            $cat = trim((string)($j['category'] ?? ''));
            if ($cat === '') {
                $cat = '其他';
            }
            $out[$cat][] = $j;
        }
        return $out;
    }

    /** 文本按行拆成清单项 */
    public static function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/^[\-\*\x{2022}\d\.\、\s]+/u', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** 该职位接收简历的邮箱（职位单独配置优先，其次系统/关于我们邮箱） */
    public static function jobMailbox(?array $job = null): string
    {
        $mail = trim((string)($job['contact'] ?? ''));
        if ($mail === '') {
            $mail = trim((string)setting('about_contact_email', ''));
        }
        if ($mail === '') {
            $mail = trim((string)setting('contact_email', ''));
        }
        return $mail;
    }

    /** 投递简历区块是否可用（启用了关于我们招聘板块或存在在招职位） */
    public static function openForApply(): bool
    {
        if (setting('career_apply_enabled', '1') !== '1') {
            return false;
        }
        return self::jobs() !== [];
    }

    /* ------------------------------ 简历 ------------------------------ */

    public static function bySn(string $sn): ?array
    {
        $sn = trim($sn);
        if ($sn === '') {
            return null;
        }
        $r = YxDb::one('SELECT * FROM ' . YxDb::table('resume') . ' WHERE sn = ? LIMIT 1', [$sn]);
        return $r ?: null;
    }

    /** 最近 N 分钟内同一 IP 的投递数 */
    public static function recentByIp(string $ip, int $minutes = 10): int
    {
        if ($ip === '') {
            return 0;
        }
        return (int)YxDb::value(
            'SELECT COUNT(*) FROM ' . YxDb::table('resume') . ' WHERE ip = ? AND created_at >= ?',
            [$ip, date('Y-m-d H:i:s', time() - $minutes * 60)]
        );
    }

    /**
     * 提交简历
     * @param array $data job_id,name,gender,birth_year,phone,email,city,education,experience,
     *                    current_company,portfolio,message,source
     * @param array|null $file $_FILES 中的单个文件项（可选）
     * @return array ['ok','msg','id','sn']
     */
    public static function submit(array $data, ?array $file = null): array
    {
        $jobId = (int)($data['job_id'] ?? 0);
        $job   = self::job($jobId);
        if (!$job || (int)$job['status'] !== 1) {
            return ['ok' => false, 'msg' => '所选职位不存在或已停止招聘，请刷新后重新选择'];
        }

        $name  = trim((string)($data['name'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return ['ok' => false, 'msg' => '请填写真实姓名（2-40 个字）'];
        }
        if (!preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            return ['ok' => false, 'msg' => '请填写有效的手机号'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '请填写有效的邮箱地址'];
        }

        $message = trim((string)($data['message'] ?? ''));
        if (mb_strlen($message) > 3000) {
            return ['ok' => false, 'msg' => '自我介绍不能超过 3000 个字'];
        }

        $portfolio = trim((string)($data['portfolio'] ?? ''));
        if ($portfolio !== '' && !preg_match('#^https?://#i', $portfolio)) {
            $portfolio = 'https://' . $portfolio;
        }
        if ($portfolio !== '' && !filter_var($portfolio, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'msg' => '作品集 / 主页链接格式不正确'];
        }

        $gender = trim((string)($data['gender'] ?? ''));
        if (!in_array($gender, ['男', '女', ''], true)) {
            $gender = '';
        }
        $birthYear = (int)($data['birth_year'] ?? 0);
        if ($birthYear < 1940 || $birthYear > (int)date('Y')) {
            $birthYear = 0;
        }

        // 附件校验（可选）：先校验再落盘，避免脏数据
        $saved = null;
        $hasFile = $file && isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE;
        if ($hasFile) {
            $up = yx_upload($file, [
                'max_size' => self::FILE_MAX,
                'exts'     => self::FILE_EXTS,
                'sub'      => 'resume',
                'prefix'   => 'cv',
            ]);
            if (empty($up['ok'])) {
                return ['ok' => false, 'msg' => '简历附件上传失败：' . (string)($up['msg'] ?? '')];
            }
            $saved = $up;
        }

        $sn = make_sn('RS', 'resume');
        try {
            $id = YxDb::insert('resume', [
                'sn'              => $sn,
                'job_id'          => (int)$job['id'],
                'job_title'       => (string)$job['title'],
                'name'            => $name,
                'gender'          => $gender !== '' ? $gender : null,
                'birth_year'      => $birthYear,
                'phone'           => $phone,
                'email'           => $email,
                'city'            => self::clip($data['city'] ?? '', 60),
                'education'       => self::clip($data['education'] ?? '', 40),
                'experience'      => self::clip($data['experience'] ?? '', 40),
                'current_company' => self::clip($data['current_company'] ?? '', 120),
                'portfolio'       => $portfolio !== '' ? $portfolio : null,
                'file_path'       => $saved ? $saved['path'] : null,
                'file_name'       => $saved ? self::clip($file['name'] ?? '', 160) : null,
                'message'         => $message !== '' ? $message : null,
                'source'          => in_array((string)($data['source'] ?? ''), ['web', 'about'], true)
                    ? (string)$data['source'] : 'web',
                'status'          => 'new',
                'admin_id'        => 0,
                'ip'              => client_ip(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (Throwable $e) {
            // 入库失败时收回刚上传的附件，避免孤儿文件
            if ($saved) {
                @unlink(yx_upload_dir('resume') . '/' . basename((string)$saved['path']));
            }
            return ['ok' => false, 'msg' => '提交失败，请稍后重试'];
        }

        log_action('resume.submit', $sn, ['job_id' => (int)$job['id'], 'job' => (string)$job['title']]);

        return ['ok' => true, 'msg' => '简历已投递，编号 ' . $sn, 'id' => $id, 'sn' => $sn];
    }

    /** 变更处理状态（后台） */
    public static function setStatus(int $id, string $status, int $adminId = 0, string $note = ''): array
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('resume') . ' WHERE id = ? LIMIT 1', [$id]);
        if (!$row) {
            return ['ok' => false, 'msg' => '投递记录不存在'];
        }
        if (!isset(self::STATUSES[$status])) {
            return ['ok' => false, 'msg' => '未知状态'];
        }
        $data = ['status' => $status, 'updated_at' => now()];
        if ($adminId > 0) {
            $data['admin_id'] = $adminId;
        }
        $note = trim($note);
        if ($note !== '') {
            $data['note'] = mb_substr($note, 0, 3000);
        }
        YxDb::update('resume', $data, 'id = :id', ['id' => $id]);
        log_action('resume.status', (string)$row['sn'], ['status' => $status]);

        return ['ok' => true, 'msg' => '状态已更新为「' . self::statusName($status) . '」'];
    }

    public static function remove(int $id): bool
    {
        $row = YxDb::one('SELECT * FROM ' . YxDb::table('resume') . ' WHERE id = ? LIMIT 1', [$id]);
        if (!$row) {
            return false;
        }
        if (!empty($row['file_path'])) {
            $abs = yx_upload_dir('resume') . '/' . basename((string)$row['file_path']);
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        YxDb::delete('resume', 'id = :id', ['id' => $id]);
        log_action('resume.delete', (string)$row['sn']);
        return true;
    }

    /** 后台列表筛选 */
    public static function search(array $filter, int $limit = 20, int $offset = 0): array
    {
        [$where, $bind] = self::buildWhere($filter);
        return YxDb::all(
            'SELECT * FROM ' . YxDb::table('resume') . $where . ' ORDER BY id DESC LIMIT '
            . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $bind
        );
    }

    public static function count(array $filter): int
    {
        [$where, $bind] = self::buildWhere($filter);
        return (int)YxDb::value('SELECT COUNT(*) FROM ' . YxDb::table('resume') . $where, $bind);
    }

    public static function stats(): array
    {
        $t = YxDb::table('resume');
        return [
            'new'   => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . " WHERE status = 'new'"),
            'open'  => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . " WHERE status IN ('new','screening','interview','offer')"),
            'today' => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t . ' WHERE created_at >= ?', [date('Y-m-d') . ' 00:00:00']),
            'total' => (int)YxDb::value('SELECT COUNT(*) FROM ' . $t),
        ];
    }

    private static function buildWhere(array $filter): array
    {
        $where = ' WHERE 1=1';
        $bind  = [];

        $status = (string)($filter['status'] ?? '');
        if ($status === 'open') {
            $where .= " AND status IN ('new','screening','interview','offer')";
        } elseif ($status !== '' && isset(self::STATUSES[$status])) {
            $where .= ' AND status = ?';
            $bind[] = $status;
        }

        $jobId = (int)($filter['job_id'] ?? 0);
        if ($jobId > 0) {
            $where .= ' AND job_id = ?';
            $bind[] = $jobId;
        }

        $kw = trim((string)($filter['kw'] ?? ''));
        if ($kw !== '') {
            $where .= ' AND (sn LIKE ? OR name LIKE ? OR phone LIKE ? OR email LIKE ? OR job_title LIKE ?)';
            $like = '%' . $kw . '%';
            array_push($bind, $like, $like, $like, $like, $like);
        }

        return [$where, $bind];
    }

    private static function clipText($v, int $len): ?string
    {
        $v = trim(str_replace(["\r\n", "\r"], "\n", (string)$v));
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, $len);
    }

    private static function clip($v, int $len): ?string
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, $len);
    }
}
