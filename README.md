# 优享财务系统

> 企业与业务一体化管理平台 —— 产品目录、订单、客户、账单、工单、续费、应用商店、授权中心，一套系统跑通。
> 明文 PHP，前后端可直接修改；MySQL / SQLite 双驱动，复制到站点根目录即可安装。

[![version](https://img.shields.io/badge/version-1.6.4-3b5bfd)](发行说明.md)
[![license](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![php](https://img.shields.io/badge/php-7.4%20~%208.2-8892bf)]()
[![db](https://img.shields.io/badge/db-MySQL%20%7C%20SQLite-4479a1)]()
[![code](https://img.shields.io/badge/code-plain%20PHP%20(no%20encryption)-c0392b)]()
[![stars](https://img.shields.io/github/stars/YeXuanHs/youxiang-finance?style=social)](https://github.com/YeXuanHs/youxiang-finance)

📖 [完整产品说明（361 行功能手册）](docs/产品说明.md) · [安装教程](docs/安装教程.md) · [部署说明](docs/部署说明.md) · [接口对接说明](docs/接口对接说明.md)

---

## 特性

| 模块 | 说明 |
|---|---|
| 产品目录 | 产品、套餐、周期价格、库存、上下架 |
| 订单与账单 | 下单、支付、发票、优惠、账期与流水明细 |
| 客户管理 | 客户档案、余额、积分、代理归属、实名与企业认证 |
| 业务实例 | 开通 / 暂停 / 终止、续费、到期提醒、批量操作 |
| 工单与客服 | 工单流转、AI 值守转人工、留言与响应 |
| 上游对接 | 多厂商可插拔驱动，开通与续费自动化 |
| 应用商店 | 官方 / 第三方应用，付费购买、兑换码、权益与到期管理 |
| 开发者中心 | 入驻、保证金、商品上架、成交分成 |
| 分站与代理 | 多级渠道、佣金结算、代理价自助下单 |
| 开放接口 | HMAC 签名 + 时间窗 + nonce 防重放 + IP 白名单 + 客户范围隔离 |
| 经营分析 | 收入、订单、活跃度与多维度报表 |
| 授权中心 | 域名授权签发、授权码复核、版本收敛 |

## 快速开始

### 环境要求

- PHP **7.4 ~ 8.2**，必需扩展：`pdo` `pdo_mysql`（或 `pdo_sqlite`）`mbstring` `openssl` `curl` `gd` `fileinfo`
- MySQL 5.7+ / 8.0+，或直接用 SQLite（零配置，适合内网自用）
- Nginx / Apache，站点根目录指向本项目即可

### 部署步骤

1. 下载 / 克隆本仓库，把文件放到站点根目录（`index.php` 必须在根）：
   ```bash
   git clone https://github.com/YeXuanHs/youxiang-finance.git
   ```
2. 浏览器访问 `/install/install.php`，按向导走完五步：
   许可协议 → 环境检测 → 数据库与站点信息 → 创始人信息 → 安装完成
3. 安装完成后**务必封闭敏感目录**（否则安装向导可被重跑）：
   ```bash
   # 以下任选其一：改目录名、加 deny 规则、或从服务器上移走
   mv install/ install.locked/
   mv tools/  tools.locked/
   mv includes/ includes.locked/
   mv runtime/ runtime.locked/
   ```
4. 后台入口默认为 `/admin/`，可在安装第 3 步自定义（如 `myadmin`），降低被扫描概率。
5. 在宝塔「计划任务」中添加定时任务，驱动上游开通与自动续费：
   ```bash
   php /你的站点目录/tools/cron.php
   ```

## 目录结构

```
├─ index.php            门户首页
├─ admin/               后台（47 个页面：订单、客户、商店、授权中心、报表…）
├─ user/                用户中心（订单、我的业务、应用、授权码、工单…）
├─ panel/  agent/  subsite/    客户面板 / 代理商 / 分站
├─ store/               应用商店前台
├─ api/                 对外接口：v1、支付回调、授权查询、资源下载
├─ includes/            业务内核（业务、财务、授权、支付、商店、上游…）
├─ install/             安装向导
├─ tools/               定时任务、结构升级
├─ assets/              CSS / JS / Logo
├─ uploads/             上传与文档配图（运行时可写目录）
├─ runtime/  cache/     日志与缓存（不可被直接访问）
└─ docs/                产品说明、安装教程、部署说明、接口对接说明
```

## 版本与能力

三档版本，后台「版本与模块」可随时切换：

| 版本 | rank | 典型能力 |
|---|---|---|
| 普惠版 basic | 1 | 客户、产品、订单、基础财务记账 |
| 专业版 pro | 2 | 工单、续费、优惠券、渠道分销、商店购买 |
| 企业版 enterprise | 3 | 多节点、分站/多站点、开放 API、经营分析、批量导出 |

## 授权说明（企业版）

> **本发行包为企业版，安装与运行均不需要授权码，也不需要连接任何授权中心。**

- **安装向导不索要授权码**：第 2 步「授权前提」显示「已授权 · 无需授权码」，第 4 步授权码可留空，任意内容同样通过。
- **安装完成即为正版授权**：前后台角标统一显示「域名已正版授权」，授权版本上限为**企业版**。
- **版本档位不做收敛**：普惠版 / 专业版 / 企业版三档在后台「版本授权」可自由切换，企业版专属能力（资源节点、经营分析、分站、开放 API、授权中心）全部可用。
- **授权中心仍可用**：`api/license.php` 的 `query` / `pkg_verify` / `pkg_sig_verify` 动作均可正常应答，可作为下游站点的授权服务端。
- 实现见 `includes/license/community.php`（离线授权模块）：`query()` / `badge()` / `isAuthorized()` 恒返回已授权 · 企业版，`mode()` 为 `official`，`modeLocked()` 为 `false`。

### 授权码生成（自用签发）

授权码由「域名 + 版本」按固定散列规则算出，**不依赖任何密钥**，可离线复算：

```
授权码 = strtoupper(substr(hash('sha256', "community|{domain}|{edition}|{expire}"), 0, 16))
        每 4 位一组，用 "-" 连接
```

- `domain`：安装第 3 步填写的「网站域名」，需与系统内 `normalize_domain()` 同口径（小写、去掉协议与端口）。
- `edition`：`basic` / `pro` / `enterprise`。
- `expire`：到期日，留空表示永久。

一行命令生成：

```bash
php -r "echo implode('-', str_split(strtoupper(substr(hash('sha256','community|example.com|enterprise|'),0,16)),4)), PHP_EOL;"
```

## 常用工具

| 命令 | 用途 |
|---|---|
| `php tools/selftest.php` | 端到端自检：建表 → 客户 → 财务 → 订单 → 交付 → 续费 → 工单 → 商店 → 授权，跑在独立临时库，不触碰正式数据 |
| `php tools/upgrade.php --dry-run` | 结构升级预演：已有站点升级代码后补齐新增表/字段/索引（只做加法，不删列、不清空数据） |
| `php tools/cron.php` | 定时任务：驱动上游开通与自动续费 |

## 二次开发

| 想改什么 | 改哪里 |
|---|---|
| 系统名称 / 公告 / 联系方式 | 后台「系统设置」，或 `yx_setting` 表 |
| 三个版本的价格与能力项 | `includes/edition.php` 的 `EDITIONS` / `FEATURES` |
| 界面配色与排版 | `assets/css/style.css`（主色为 `--primary`） |
| Logo | `php tools/gen_logo.php --mark=#ef4444 --text=#000000` |
| 支付通道协议 | `includes/payment.php` |
| 上游厂商对接 | 后台「上游对接」填配置；协议特殊时在 `includes/upstream.php` 新增驱动类 |
| 菜单 | `includes/nav.php` |

## 安全说明

发现安全问题欢迎**直接开公开 Issue** 反馈，也可以提交 Pull Request 一起修复；修复确认后会在 Release 说明里致谢。

部署到公网前请确认：

- `install/` `tools/` `includes/` `runtime/` `cache/` 已封闭或移出 Web 根目录
- `includes/config.php` 中的 `app.key` 为安装时生成的随机值（不要留空）
- 后台入口路径已自定义，管理员密码强度足够
- 支付网关地址使用 **https**（后台「系统设置」→ 支付设置）

## 许可协议

本项目采用 [MIT](LICENSE) 协议开源。

```
Copyright (c) 2026 AnchorFlow团队-Sora
```

## 贡献

欢迎提交 Issue 与 Pull Request。改动前请先跑一遍自检，避免破坏数据一致性：

```bash
php tools/selftest.php
```
