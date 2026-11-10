<?php
/**
 * 优享财务系统 · 登录 / 注册方式切换（手机 / 邮箱）
 *
 * 登录页：「手机登录」「邮箱登录」；注册页：「手机注册」「邮箱注册」。
 * 功能框统一蓝色底、白色字，放在「还没有账户？免费注册」/「已有账户？立即登录」下方。
 *
 * 两种方式共用一个表单，只切换「手机号 / 邮箱」这一行，提交字段名保持
 * account（登录）与 phone / email（注册）不变，因此原有校验与入库链路无需重写。
 */

/**
 * 归一化方式标识；未知值回落到手机
 *
 * @param mixed $mode
 */
function yx_auth_mode($mode, string $fallback = 'phone'): string
{
    $mode = strtolower(trim((string)$mode));
    $fallback = $fallback === 'email' ? 'email' : 'phone';
    return in_array($mode, ['phone', 'email'], true) ? $mode : $fallback;
}
