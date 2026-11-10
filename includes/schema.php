<?php
/**
 * 优享财务系统 · 数据库结构定义（单一事实来源）
 *
 * 通过抽象列描述同时生成 MySQL 与 SQLite 的 DDL，避免维护两份 SQL。
 *
 * 列描述语法：  "类型[:修饰][:修饰]..."
 *   类型： int | tinyint | bigint | varchar(N) | text | json | decimal(N,M) | datetime | date
 *   修饰： pk      主键
 *          ai      自增
 *          null    允许为空
 *          unique  唯一索引
 *          default=VALUE   默认值（数字原样，其它自动加引号）
 */

return [
    /* ---------------- 管理端 ---------------- */
    'admin' => [
        'comment' => '管理员',
        'columns' => [
            'id'            => 'int:pk:ai',
            'username'      => 'varchar(50):unique',
            'password'      => 'varchar(255)',
            'nickname'      => 'varchar(50):null',
            'role'          => "varchar(20):default=owner",
            'status'        => 'tinyint:default=1',
            'last_login_at' => 'datetime:null',
            'last_login_ip' => 'varchar(46):null',
            'fail_count'    => 'int:default=0',
            'lock_until'    => 'datetime:null',
            'created_at'    => 'datetime:null',
        ],
        'indexes' => [['status']],
    ],

    'admin_log' => [
        'comment' => '管理端操作审计',
        'columns' => [
            'id'         => 'int:pk:ai',
            'admin_id'   => 'int:default=0',
            'action'     => 'varchar(100)',
            'target'     => 'varchar(255):null',
            'detail'     => 'json:null',
            'ip'         => 'varchar(46):null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['admin_id'], ['action']],
    ],

    /* ---------------- 客户 ---------------- */
    'customer' => [
        'comment' => '客户',
        'columns' => [
            'id'            => 'int:pk:ai',
            'user_no'       => 'int:null:unique',
            'username'      => 'varchar(50):unique',
            'email'         => 'varchar(120)',
            'phone'         => 'varchar(30):null',
            'password'      => 'varchar(255)',
            'company'       => 'varchar(120):null',
            'balance'       => 'decimal(12,2):default=0',
            'credit'        => 'decimal(12,2):default=0',
            'points'        => 'int:default=0',
            'level'         => "varchar(20):default=normal",
            'agent_id'      => 'int:default=0',
            'subsite_id'    => 'int:default=0',
            'status'        => 'tinyint:default=1',
            'realname'      => 'varchar(50):null',
            'idcard'        => 'varchar(64):null',
            'realname_status' => "varchar(20):default=none",       // none|pending|verified（扫码核验结果）
            'realname_type'   => "varchar(20):default=personal",   // personal|enterprise
            'company'         => 'varchar(160):null',
            'license_no'      => 'varchar(64):null',               // 统一社会信用代码
            'legal_person'    => 'varchar(50):null',
            'license_image'   => 'varchar(255):null',              // 营业执照图片（uploads/realname/..）
            'verified_at'     => 'datetime:null',
            'api_enabled'     => 'tinyint:default=0',              // 下游 API 对接开关（用户中心自助开启）
            'api_agree_at'    => 'datetime:null',                  // 同意《API 对接服务协议》时间
            'publisher_id'    => 'int:default=0',                  // 推广来源推广人客户 ID（0=无）
            'reg_ip'        => 'varchar(46):null',
            'remark'        => 'varchar(255):null',
            'last_login_at' => 'datetime:null',
            'created_at'    => 'datetime:null',
        ],
        'indexes' => [['email'], ['agent_id'], ['status']],
    ],

    /* ---------------- 产品与业务 ---------------- */
    'product_group' => [
        'comment' => '产品分组',
        'columns' => [
            'id'     => 'int:pk:ai',
            'name'   => 'varchar(80)',
            'sort'   => 'int:default=0',
            'status' => 'tinyint:default=1',
        ],
        'indexes' => [['sort']],
    ],

    'product' => [
        'comment' => '产品/套餐',
        'columns' => [
            'id'          => 'int:pk:ai',
            'group_id'    => 'int:default=0',
            'name'        => 'varchar(120)',
            'code'        => 'varchar(60):null',
            'type'        => "varchar(20):default=host",
            'cycle'       => "varchar(20):default=month",
            // 允许下单的周期，逗号分隔（month,quarter,semiyear,year）；空 = 不限制。
            // 上游按年计费的商品会收窄为 year，避免按月价卖出整年服务。
            'cycles'      => 'varchar(80):null',
            'price'       => 'decimal(12,2):default=0',
            'setup_fee'   => 'decimal(12,2):default=0',
            'stock'       => 'int:default=-1',
            'auto_setup'  => 'tinyint:default=0',
            'edition_min' => "varchar(20):default=basic",
            'config'      => 'json:null',
            'description' => 'text:null',
            'status'      => 'tinyint:default=1',
            'sort'        => 'int:default=0',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['group_id'], ['status'], ['type']],
    ],

    'node' => [
        'comment' => '资源节点',
        'columns' => [
            'id'         => 'int:pk:ai',
            'name'       => 'varchar(80)',
            'type'       => "varchar(20):default=host",
            'host'       => 'varchar(120):null',
            'port'       => 'int:default=0',
            'api_key'    => 'varchar(255):null',
            'capacity'   => 'int:default=0',
            'used'       => 'int:default=0',
            'status'     => 'tinyint:default=1',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['type'], ['status']],
    ],

    'service' => [
        'comment' => '业务实例（已售出服务）',
        'columns' => [
            'id'            => 'int:pk:ai',
            'sn'            => 'varchar(40):unique',
            'customer_id'   => 'int:default=0',
            'product_id'    => 'int:default=0',
            'node_id'       => 'int:default=0',
            'name'          => 'varchar(120)',
            'hostname'      => 'varchar(120):null',
            'ip'            => 'varchar(64):null',
            'login_user'    => 'varchar(64):null',
            'login_pass'    => 'varchar(255):null',
            'billing_cycle' => "varchar(20):default=month",
            'price'         => 'decimal(12,2):default=0',
            'status'        => "varchar(20):default=pending",
            'next_due_date' => 'date:null',
            'activated_at'  => 'datetime:null',
            'remark'        => 'varchar(255):null',
            'source'        => "varchar(20):default=web",
            'appid'         => 'varchar(40):null',
            'external_id'   => 'varchar(80):null',
            'upstream_id'   => 'int:default=0',
            'customer_upstream_id' => 'int:default=0',
            'remote_code'   => 'varchar(120):null',
            'remote_spec'   => 'json:null',
            'remote_id'     => 'varchar(120):null',
            'remote_status' => 'varchar(40):null',
            'remote_raw'    => 'json:null',
            'created_at'    => 'datetime:null',
            'updated_at'    => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['product_id'], ['status'], ['next_due_date'], ['appid'], ['remote_id'], ['customer_upstream_id']],
    ],

    /* ---------------- 财务 ---------------- */
    'order' => [
        'comment' => '订单',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',
            'customer_id' => 'int:default=0',
            'type'        => "varchar(20):default=new",
            'product_id'  => 'int:default=0',
            'service_id'  => 'int:default=0',
            'app_id'      => 'int:default=0',
            'name'        => 'varchar(180):null',
            'amount'      => 'decimal(12,2):default=0',
            'discount'    => 'decimal(12,2):default=0',
            'total'       => 'decimal(12,2):default=0',
            'status'      => "varchar(20):default=unpaid",
            'pay_method'  => 'varchar(20):null',
            'trade_no'    => 'varchar(80):null',
            'coupon_id'   => 'int:default=0',
            'agent_id'    => 'int:default=0',
            'subsite_id'  => 'int:default=0',
            'note'        => 'varchar(255):null',
            'source'      => "varchar(20):default=web",
            'appid'       => 'varchar(40):null',
            'external_sn' => 'varchar(80):null',
            'customer_upstream_id' => 'int:default=0',
            'remote_code' => 'varchar(120):null',
            'remote_spec' => 'json:null',
            'billing_cycle' => "varchar(20):default=month",
            'upstream_markup' => 'text:null',   // 上游对接代理等级加价快照（插件 upstream-agent-markup）
            'publisher_id' => 'int:default=0',           // 推广人客户 ID（下单时快照）
            'affiliate_rate' => 'decimal(6,2):default=0',      // 佣金比例快照（%）
            'affiliate_commission' => 'decimal(12,2):default=0', // 佣金金额快照
            'affiliate_settled_at' => 'datetime:null',         // 佣金结款时间（null=未结）
            'paid_at'     => 'datetime:null',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['type'], ['created_at'], ['appid'], ['customer_upstream_id'], ['publisher_id']],
    ],

    'invoice' => [
        'comment' => '账单/发票',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',
            'customer_id' => 'int:default=0',
            'order_id'    => 'int:default=0',
            'service_id'  => 'int:default=0',
            'title'       => 'varchar(180):null',
            'amount'      => 'decimal(12,2):default=0',
            'paid_amount' => 'decimal(12,2):default=0',
            'type'        => "varchar(20):default=service",
            'status'      => "varchar(20):default=unpaid",
            'due_date'    => 'date:null',
            'issued_at'   => 'datetime:null',
            'paid_at'     => 'datetime:null',
            'remark'      => 'varchar(255):null',
        ],
        'indexes' => [['customer_id'], ['status'], ['due_date']],
    ],

    'transaction' => [
        'comment' => '收支流水',
        'columns' => [
            'id'            => 'int:pk:ai',
            'sn'            => 'varchar(40):unique',
            'customer_id'   => 'int:default=0',
            'direction'     => "varchar(10):default=income",
            'category'      => 'varchar(30)',
            'amount'        => 'decimal(12,2):default=0',
            'balance_after' => 'decimal(12,2):default=0',
            'method'        => 'varchar(30):null',
            'ref_type'      => 'varchar(30):null',
            'ref_id'        => 'int:default=0',
            'operator'      => 'varchar(50):null',
            'remark'        => 'varchar(255):null',
            'created_at'    => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['direction'], ['category'], ['created_at']],
    ],

    'payment' => [
        'comment' => '支付记录',
        'columns' => [
            'id'          => 'int:pk:ai',
            'order_id'    => 'int:default=0',
            'order_sn'    => 'varchar(40):null',
            'customer_id' => 'int:default=0',
            'gateway'     => 'varchar(30):null',
            'trade_no'    => 'varchar(80):null',
            'amount'      => 'decimal(12,2):default=0',
            'status'      => "varchar(20):default=pending",
            'raw'         => 'json:null',
            'created_at'  => 'datetime:null',
            'paid_at'     => 'datetime:null',
        ],
        'indexes' => [['order_id'], ['status']],
    ],

    'coupon' => [
        'comment' => '优惠券',
        'columns' => [
            'id'         => 'int:pk:ai',
            'code'       => 'varchar(40):unique',
            'type'       => "varchar(20):default=fixed",
            'value'      => 'decimal(12,2):default=0',
            'min_amount' => 'decimal(12,2):default=0',
            'total'      => 'int:default=0',
            'used'       => 'int:default=0',
            'edition_min'=> "varchar(20):default=pro",
            'expire_at'  => 'date:null',
            'status'     => 'tinyint:default=1',
        ],
        'indexes' => [['status']],
    ],

    /* ---------------- 工单 ---------------- */
    'ticket' => [
        'comment' => '工单',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',
            'customer_id' => 'int:default=0',
            'service_id'  => 'int:default=0',
            'department'  => "varchar(30):default=tech",
            'subject'     => 'varchar(200)',
            'priority'    => "varchar(20):default=normal",
            'status'      => "varchar(20):default=open",
            'admin_id'    => 'int:default=0',
            'reply_count' => 'int:default=0',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
            'closed_at'   => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['department']],
    ],

    'ticket_reply' => [
        'comment' => '工单回复',
        'columns' => [
            'id'          => 'int:pk:ai',
            'ticket_id'   => 'int:default=0',
            'author_type' => "varchar(20):default=customer",
            'author_id'   => 'int:default=0',
            'content'     => 'text',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['ticket_id']],
    ],

    /* ---------------- 商店 ---------------- */
    'app_category' => [
        'comment' => '应用分类',
        'columns' => [
            'id'     => 'int:pk:ai',
            'key'    => 'varchar(40):unique',
            'name'   => 'varchar(80)',
            'icon'   => 'varchar(40):null',
            'desc'   => 'varchar(255):null',
            'sort'   => 'int:default=0',
            'status' => 'tinyint:default=1',
        ],
        'indexes' => [['sort']],
    ],

    'app' => [
        'comment' => '商店应用/插件',
        'columns' => [
            'id'           => 'int:pk:ai',
            'category_key' => 'varchar(40):default=other',
            'key'          => 'varchar(60):unique',
            'name'         => 'varchar(120)',
            'version'      => "varchar(20):default=1.0.0",
            'author'       => 'varchar(80):null',
            'price'        => 'decimal(12,2):default=0',
            'bill_type'    => "varchar(20):default=free",   // free|once|year|points
            'points_price' => 'int:default=0',
            'description'  => 'text:null',
            'changelog'    => 'text:null',
            'edition_min'  => "varchar(20):default=basic",
            'compatible'   => 'varchar(60):null',
            'downloads'    => 'int:default=0',
            'sales'        => 'int:default=0',
            'featured'     => 'tinyint:default=0',
            'source'       => 'varchar(255):null',
            'developer_id' => 'int:default=0',
            'author_type'  => "varchar(20):default=official",
            'type'         => "varchar(20):default=plugin",
            'cover'        => 'varchar(255):null',
            'screenshots'  => 'json:null',
            'package_path' => 'varchar(255):null',
            'package_size' => 'int:default=0',
            'review_note'  => 'varchar(255):null',
            'specs'        => 'json:null',                   // 应用设置项：可选规格与对应价格
            'price_options' => 'json:null',                  // 版本报价：[{edition,price,bill_type,days,label}]，为空时回退 price/bill_type
            'language'     => "varchar(60):default=简体中文",  // 支持语言
            'published_at' => 'datetime:null',                // 上架时间（商品信息「最近更新」优先取 updated_at）
            'status'       => "varchar(20):default=on",
            'created_at'   => 'datetime:null',
            'updated_at'   => 'datetime:null',
        ],
        'indexes' => [['category_key'], ['status'], ['featured'], ['developer_id']],
    ],

    'app_install' => [
        'comment' => '已安装应用',
        'columns' => [
            'id'           => 'int:pk:ai',
            'app_id'       => 'int:default=0',
            'app_key'      => 'varchar(60):null',
            'customer_id'  => 'int:default=0',
            'domain'       => 'varchar(120):null',
            'license_key'  => 'varchar(80):null',
            'order_id'     => 'int:default=0',
            'status'       => "varchar(20):default=enabled",
            'expire_at'    => 'date:null',
            'installed_at' => 'datetime:null',
        ],
        'indexes' => [['app_id'], ['domain'], ['status']],
    ],

    /* ---------------- 服务中心（可购买的服务项目） ---------------- */
    'service_item' => [
        'comment' => '服务中心 · 服务项目（含价格，可购买）',
        'columns' => [
            'id'          => 'int:pk:ai',
            'group_key'   => 'varchar(40):default=ops',
            'name'        => 'varchar(120)',
            'subtitle'    => 'varchar(160):null',
            'icon'        => 'varchar(40):null',
            'price'       => 'decimal(12,2):default=0',
            'bill_type'   => "varchar(20):default=once",   // once|year|month|free|quote
            'unit'        => 'varchar(20):null',
            'stock'       => 'int:default=-1',
            'delivery'    => 'varchar(255):null',
            'description' => 'text:null',
            'features'    => 'json:null',
            'specs'       => 'json:null',
            'badge'       => 'varchar(40):null',
            'featured'    => 'tinyint:default=0',
            'sales'       => 'int:default=0',
            'sort'        => 'int:default=0',
            'status'      => 'tinyint:default=1',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
        ],
        'indexes' => [['group_key'], ['status'], ['sort'], ['featured']],
    ],

    'service_order' => [
        'comment' => '服务中心 · 服务购买记录',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',
            'customer_id' => 'int:default=0',
            'item_id'     => 'int:default=0',
            'order_id'    => 'int:default=0',
            'service_id'  => 'int:default=0',
            'name'        => 'varchar(180):null',
            'spec_text'   => 'varchar(180):null',
            'amount'      => 'decimal(12,2):default=0',
            'bill_type'   => "varchar(20):default=once",
            'contact'     => 'varchar(120):null',
            'remark'      => 'text:null',
            'status'      => "varchar(20):default=pending",
            'expire_at'   => 'date:null',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['item_id'], ['order_id'], ['status']],
    ],

    /* ---------------- 授权 ---------------- */
    'license' => [
        'comment' => '域名授权记录',
        'columns' => [
            'id'            => 'int:pk:ai',
            'domain'        => 'varchar(120):unique',
            'license_key'   => 'varchar(80)',
            'edition'       => "varchar(20):default=basic",
            'type'          => "varchar(20):default=official",
            'status'        => "varchar(20):default=active",
            'expire_at'     => 'date:null',
            'max_sites'     => 'int:default=1',
            'remark'        => 'varchar(255):null',
            'bound_at'      => 'datetime:null',
            'last_check_at' => 'datetime:null',
            'created_at'    => 'datetime:null',
        ],
        'indexes' => [['status'], ['license_key']],
    ],

    'license_log' => [
        'comment' => '授权校验日志',
        'columns' => [
            'id'         => 'int:pk:ai',
            'domain'     => 'varchar(120)',
            'app_id'     => 'varchar(40):null',
            'result'     => "varchar(20):default=unauthorized",
            'source'     => "varchar(20):default=local",
            'ip'         => 'varchar(46):null',
            'ua'         => 'varchar(255):null',
            'message'    => 'varchar(255):null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['domain'], ['result'], ['app_id']],
    ],

    /* ---------------- 购买凭证（正版授权购买证明，加盖电子公章） ---------------- */
    'certificate' => [
        'comment' => '正版购买凭证',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',                    // 凭证编号
            'customer_id' => 'int:default=0',
            'order_id'    => 'int:default=0',
            'order_sn'    => 'varchar(40):null',
            'app_id'      => 'int:default=0',
            'app_key'     => 'varchar(60):null',
            'app_name'    => 'varchar(200):null',
            'app_version' => 'varchar(20):null',
            'product'     => "varchar(20):default=official",          // official|open
            'domain'      => 'varchar(120):null',
            'license_key' => 'varchar(80):null',
            'edition'     => 'varchar(20):null',
            'buyer_name'  => 'varchar(160):null',
            'amount'      => 'decimal(12,2):default=0',
            'pay_method'  => 'varchar(30):null',
            'paid_at'     => 'datetime:null',
            'valid_from'  => 'date:null',
            'valid_to'    => 'date:null',
            'issuer'      => 'varchar(160):null',                     // 签发方（盖章主体）
            'sign_hash'   => 'varchar(128):null',                     // 内容摘要，供核验
            'status'      => "varchar(20):default=valid",             // valid|revoked
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['order_id'], ['domain']],
    ],

    /* ---------------- 渠道 / 设置 ---------------- */
    'agent' => [
        'comment' => '渠道/代理',
        'columns' => [
            'id'              => 'int:pk:ai',
            'name'            => 'varchar(80)',
            'username'        => 'varchar(50):unique',
            'password'        => 'varchar(255)',
            'domain'          => 'varchar(120):null',
            'commission_rate' => 'decimal(5,2):default=0',
            'balance'         => 'decimal(12,2):default=0',
            'customer_id'     => 'int:default=0',
            'level_key'       => 'varchar(32):null',   // 代理等级（插件 upstream-agent-markup 使用）
            'status'          => 'tinyint:default=1',
            'created_at'      => 'datetime:null',
        ],
        'indexes' => [['status']],
    ],

    /* ---------------- 关于我们（可后台编辑的静态内容） ---------------- */
    'about_item' => [
        'comment' => '关于我们 · 可重复内容项（核心能力/数据指标/理念/发展历程/伙伴/福利）',
        'columns' => [
            'id'         => 'int:pk:ai',
            'section'    => 'varchar(30)',
            'sort'       => 'int:default=0',
            'title'      => 'varchar(160):null',
            'subtitle'   => 'varchar(160):null',
            'content'    => 'text:null',
            'icon'       => 'varchar(40):null',
            'link'       => 'varchar(255):null',
            'status'     => 'tinyint:default=1',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['section'], ['sort'], ['status']],
    ],

    /* ---------------- 首页横幅（后台可编辑图片与文案） ---------------- */
    'banner' => [
        'comment' => '首页横幅（图片或渐变底 + 文案 + 按钮）',
        'columns' => [
            'id'         => 'int:pk:ai',
            'tag'        => 'varchar(80):null',
            'title'      => 'varchar(160):null',
            'subtitle'   => 'varchar(500):null',
            'image'      => 'varchar(255):null',
            'bg'         => "varchar(20):default=blue",
            'btn1_text'  => 'varchar(40):null',
            'btn1_url'   => 'varchar(255):null',
            'btn2_text'  => 'varchar(40):null',
            'btn2_url'   => 'varchar(255):null',
            'sort'       => 'int:default=0',
            'status'     => 'tinyint:default=1',
            'created_at' => 'datetime:null',
            'updated_at' => 'datetime:null',
        ],
        'indexes' => [['status'], ['sort']],
    ],

    'setting' => [
        'comment' => '系统设置',
        'columns' => [
            'k'          => 'varchar(60):pk',
            'v'          => 'text:null',
            'updated_at' => 'datetime:null',
        ],
        'indexes' => [],
    ],

    /* ---------------- 上游对接（供应商 / 产品映射 / 异步任务） ---------------- */
    'upstream' => [
        'comment' => '上游供应商',
        'columns' => [
            'id'          => 'int:pk:ai',
            'name'        => 'varchar(80)',
            'driver'      => "varchar(30):default=mock",
            'endpoint'    => 'varchar(255):null',
            'appid'       => 'varchar(80):null',
            'secret'      => 'varchar(255):null',
            'config'      => 'json:null',
            'timeout'     => 'int:default=10',
            'auto_setup'  => 'tinyint:default=0',
            'status'      => 'tinyint:default=1',
            'remark'      => 'varchar(255):null',
            'last_test_at' => 'datetime:null',
            'last_test_ok' => 'tinyint:default=0',
            'last_test_msg' => 'varchar(255):null',
            'last_stock_at' => 'datetime:null',
            'last_stock_ok' => 'tinyint:default=0',
            'last_stock_msg' => 'varchar(255):null',
            'last_stock_total' => 'int:default=0',
            'last_stock_in' => 'int:default=0',
            'last_stock_out' => 'int:default=0',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['driver'], ['status']],
    ],

    'customer_upstream' => [
        'comment' => '客户自有上游对接（购买自动开通）',
        'columns' => [
            'id'           => 'int:pk:ai',
            'customer_id'  => 'int:default=0',
            'name'         => 'varchar(80)',
            'driver'       => "varchar(30):default=http",
            'endpoint'     => 'varchar(255):null',
            'appid'        => 'varchar(80):null',
            'secret'       => 'varchar(255):null',
            'config'       => 'json:null',
            'timeout'      => 'int:default=10',
            'status'       => 'tinyint:default=1',
            'last_test_at' => 'datetime:null',
            'last_test_ok' => 'tinyint:default=0',
            'last_test_msg' => 'varchar(255):null',
            'created_at'   => 'datetime:null',
            'updated_at'   => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status']],
    ],

    'upstream_map' => [
        'comment' => '本地产品与上游产品映射',
        'columns' => [
            'id'          => 'int:pk:ai',
            'product_id'  => 'int:default=0',
            'upstream_id' => 'int:default=0',
            'remote_code' => 'varchar(120):null',
            'remote_name' => 'varchar(160):null',
            'spec'        => 'json:null',
            'price'       => 'decimal(12,2):default=0',
            'auto_setup'  => 'tinyint:default=1',
            'status'      => 'tinyint:default=1',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['product_id'], ['upstream_id']],
    ],

    'task' => [
        'comment' => '异步任务队列（上游开通/续费/操作、下游回调）',
        'columns' => [
            'id'            => 'int:pk:ai',
            'type'          => 'varchar(40)',
            'ref_type'      => 'varchar(30):null',
            'ref_id'        => 'int:default=0',
            'upstream_id'   => 'int:default=0',
            'payload'       => 'json:null',
            'status'        => "varchar(20):default=pending",
            'attempts'      => 'int:default=0',
            'max_attempts'  => 'int:default=5',
            'result'        => 'json:null',
            'error'         => 'varchar(500):null',
            'next_retry_at' => 'datetime:null',
            'locked_at'     => 'datetime:null',
            'created_at'    => 'datetime:null',
            'updated_at'    => 'datetime:null',
        ],
        'indexes' => [['status'], ['type'], ['ref_id'], ['next_retry_at']],
    ],

    /* ---------------- 下游开放接口 ---------------- */
    'api_app' => [
        'comment' => '下游应用（API 调用方）',
        'columns' => [
            'id'           => 'int:pk:ai',
            'name'         => 'varchar(80)',
            'appid'        => 'varchar(40):unique',
            'secret'       => 'varchar(255)',
            'owner_type'   => "varchar(20):default=customer",
            'owner_id'     => 'int:default=0',
            'notify_url'   => 'varchar(255):null',
            'ip_whitelist' => 'varchar(255):null',
            'rate_limit'   => 'int:default=120',
            'allow_actions' => 'text:null',
            'status'       => 'tinyint:default=1',
            'remark'       => 'varchar(255):null',
            'call_count'   => 'int:default=0',
            'last_call_at' => 'datetime:null',
            'created_at'   => 'datetime:null',
        ],
        'indexes' => [['status'], ['owner_type'], ['owner_id']],
    ],

    'api_customer_map' => [
        'comment' => '下游客户与我们客户的映射',
        'columns' => [
            'id'          => 'int:pk:ai',
            'appid'       => 'varchar(40)',
            'external_id' => 'varchar(80)',
            'customer_id' => 'int:default=0',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['appid'], ['external_id'], ['customer_id']],
    ],

    'api_log' => [
        'comment' => '开放接口调用日志',
        'columns' => [
            'id'         => 'int:pk:ai',
            'appid'      => 'varchar(40):null',
            'action'     => 'varchar(60):null',
            'ip'         => 'varchar(46):null',
            'code'       => 'int:default=0',
            'msg'        => 'varchar(255):null',
            'cost_ms'    => 'int:default=0',
            'params'     => 'json:null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['appid'], ['action'], ['code'], ['created_at']],
    ],

    'api_nonce' => [
        'comment' => '接口防重放随机串',
        'columns' => [
            'id'         => 'int:pk:ai',
            'appid'      => 'varchar(40)',
            'nonce'      => 'varchar(64)',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['appid'], ['nonce'], ['created_at']],
    ],

    /* ---------------- 开发者中心 ---------------- */
    'developer' => [
        'comment' => '开发者（入驻档案）',
        'columns' => [
            'id'              => 'int:pk:ai',
            'customer_id'     => 'int:default=0',
            'type'            => "varchar(20):default=personal",   // personal=个人 enterprise=企业
            'realname_status' => "varchar(20):default=none",        // none|pending|verified|rejected|expired
            'real_name'       => 'varchar(50):null',
            'id_card'         => 'varchar(255):null',               // AES 加密存储
            'company'         => 'varchar(160):null',
            'license_no'      => 'varchar(64):null',                // 统一社会信用代码
            'legal_person'    => 'varchar(50):null',
            'license_image'   => 'varchar(255):null',               // 营业执照图片（uploads/realname/..）
            'contact'         => 'varchar(60):null',
            'website'         => 'varchar(160):null',               // 作者网站（前台应用详情展示）
            'email'           => 'varchar(120):null',               // 电子邮箱
            'qq'              => 'varchar(30):null',                // 咨询 QQ
            'wechat'          => 'varchar(60):null',                // 联系微信
            'intro'           => 'varchar(255):null',               // 开发者简介
            'deposit_status'  => "varchar(20):default=none",        // none|unpaid|paid|refunded|deducted
            'deposit_amount'  => 'decimal(12,2):default=0',
            'can_publish'     => 'tinyint:default=0',
            'status'          => "varchar(20):default=applying",    // applying|pending_review|approved|rejected|disabled
            'remark'          => 'varchar(255):null',
            'applied_at'      => 'datetime:null',
            'verified_at'     => 'datetime:null',
            'approved_at'     => 'datetime:null',
            'created_at'      => 'datetime:null',
            'updated_at'      => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['realname_status']],
    ],

    'developer_realname' => [
        'comment' => '开发者实名认证单（个人/企业扫码核验）',
        'columns' => [
            'id'           => 'int:pk:ai',
            'customer_id'  => 'int:default=0',
            'developer_id' => 'int:default=0',
            'dev_type'     => "varchar(20):default=personal",
            'provider'     => "varchar(30):default=mock",
            'request_id'   => 'varchar(80):null',
            'qr_url'       => 'varchar(500):null',
            'status'       => "varchar(20):default=pending",        // pending|scanning|verified|failed|expired
            'name'         => 'varchar(50):null',
            'id_card'      => 'varchar(255):null',                  // AES 加密存储
            'company'      => 'varchar(160):null',
            'license_no'   => 'varchar(64):null',
            'legal_person' => 'varchar(50):null',
            'license_image' => 'varchar(255):null',                 // 营业执照图片（uploads/realname/..）
            'result_raw'   => 'json:null',
            'message'      => 'varchar(255):null',
            'expire_at'    => 'datetime:null',
            'verified_at'  => 'datetime:null',
            'created_at'   => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['developer_id'], ['status'], ['request_id']],
    ],

    'developer_deposit' => [
        'comment' => '开发者保证金单据',
        'columns' => [
            'id'           => 'int:pk:ai',
            'developer_id' => 'int:default=0',
            'customer_id'  => 'int:default=0',
            'order_id'     => 'int:default=0',
            'sn'           => 'varchar(40):unique',
            'amount'       => 'decimal(12,2):default=0',
            'status'       => "varchar(20):default=unpaid",         // unpaid|paid|refunded|deducted
            'trade_no'     => 'varchar(80):null',
            'paid_at'      => 'datetime:null',
            'remark'       => 'varchar(255):null',
            'created_at'   => 'datetime:null',
        ],
        'indexes' => [['developer_id'], ['customer_id'], ['status'], ['order_id']],
    ],

    'dev_app_version' => [
        'comment' => '开发者商品版本（安装包）',
        'columns' => [
            'id'           => 'int:pk:ai',
            'app_id'       => 'int:default=0',
            'developer_id' => 'int:default=0',
            'version'      => 'varchar(32)',
            'package_path' => 'varchar(255):null',
            'package_size' => 'int:default=0',
            'changelog'    => 'text:null',
            'created_at'   => 'datetime:null',
        ],
        'indexes' => [['app_id'], ['developer_id']],
    ],

    'developer_settlement' => [
        'comment' => '开发者售卖分成结算',
        'columns' => [
            'id'           => 'int:pk:ai',
            'developer_id' => 'int:default=0',
            'app_id'       => 'int:default=0',
            'order_id'     => 'int:default=0',
            'gross'        => 'decimal(12,2):default=0',
            'commission'   => 'decimal(12,2):default=0',
            'net'          => 'decimal(12,2):default=0',
            'status'       => "varchar(20):default=pending",        // pending|settled
            'created_at'   => 'datetime:null',
            'settled_at'   => 'datetime:null',
        ],
        'indexes' => [['developer_id'], ['order_id'], ['status']],
    ],

    /* ---------------- 每日签到 ---------------- */
    'checkin_log' => [
        'comment' => '用户每日签到记录',
        'columns' => [
            'id'          => 'int:pk:ai',
            'customer_id' => 'int:default=0',
            'check_date'  => 'date:null',
            'points'      => 'int:default=0',
            'streak'      => 'int:default=1',
            'ip'          => 'varchar(46):null',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['check_date']],
    ],

    /* ---------------- 在线客服 ---------------- */
    'chat_session' => [
        'comment' => '在线客服会话',
        'columns' => [
            'id'             => 'int:pk:ai',
            'token'          => 'varchar(64):unique',
            'customer_id'    => 'int:default=0',
            'visitor_name'   => 'varchar(60):null',
            'visitor_ip'     => 'varchar(46):null',
            'source_url'     => 'varchar(255):null',
            'status'         => "varchar(20):default=ai",   // ai|waiting|serving|closed
            'agent_id'       => 'int:default=0',
            'ticket_id'      => 'int:default=0',
            'message_count'  => 'int:default=0',
            'ai_count'       => 'int:default=0',
            'unread_admin'   => 'int:default=0',
            'unread_visitor' => 'int:default=0',
            'last_msg_at'    => 'datetime:null',
            'created_at'     => 'datetime:null',
            'updated_at'     => 'datetime:null',
            'closed_at'      => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['token'], ['updated_at']],
    ],

    'chat_message' => [
        'comment' => '在线客服消息',
        'columns' => [
            'id'         => 'int:pk:ai',
            'session_id' => 'int:default=0',
            'role'       => "varchar(12):default=visitor",  // visitor|ai|agent|system
            'content'    => 'text:null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['session_id'], ['role']],
    ],

    'chat_leave' => [
        'comment' => '小优助手留言（商务合作 / 售后咨询）',
        'columns' => [
            'id'         => 'int:pk:ai',
            'session_id' => 'int:default=0',
            'category'   => "varchar(20):default=business",  // business=商务合作 aftersale=售后咨询
            'contact'    => 'varchar(120):null',
            'content'    => 'text:null',
            'ticket_id'  => 'int:default=0',
            'status'     => "varchar(20):default=new",        // new|handled
            'ip'         => 'varchar(46):null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['session_id'], ['category'], ['status'], ['created_at']],
    ],

    /* ---------------- 版权侵权处理 ---------------- */
    'infringe_rule' => [
        'comment' => '侵权检测规则库',
        'columns' => [
            'id'         => 'int:pk:ai',
            'type'       => "varchar(20):default=keyword",   // keyword|blacklist|regex
            'name'       => 'varchar(80):null',
            'pattern'    => 'varchar(255)',
            'weight'     => 'int:default=3',
            'risk'       => "varchar(10):default=mid",        // low|mid|high
            'status'     => 'tinyint:default=1',
            'remark'     => 'varchar(255):null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['type'], ['status']],
    ],

    'infringe_report' => [
        'comment' => '侵权举报单',
        'columns' => [
            'id'            => 'int:pk:ai',
            'sn'            => 'varchar(40):unique',
            'customer_id'   => 'int:default=0',
            'reporter'      => 'varchar(80):null',
            'contact'       => 'varchar(120):null',
            'target_type'   => "varchar(20):default=text",   // app|url|text
            'target_app_id' => 'int:default=0',
            'target_url'    => 'varchar(255):null',
            'target_text'   => 'varchar(255):null',
            'work_title'    => 'varchar(180):null',
            'work_owner'    => 'varchar(120):null',
            'proof_url'     => 'varchar(255):null',
            'description'   => 'text:null',
            'risk'          => 'varchar(10):null',
            'score'         => 'int:default=0',
            'detect_result' => 'json:null',
            'status'        => "varchar(20):default=pending", // pending|accepted|rejected|takedown|closed
            'handle_note'   => 'varchar(255):null',
            'handled_by'    => 'int:default=0',
            'created_at'    => 'datetime:null',
            'updated_at'    => 'datetime:null',
            'handled_at'    => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['target_app_id'], ['created_at']],
    ],

    'infringe_scan' => [
        'comment' => '侵权批量扫描记录',
        'columns' => [
            'id'         => 'int:pk:ai',
            'scope'      => 'varchar(30):null',
            'total'      => 'int:default=0',
            'hits'       => 'int:default=0',
            'result'     => 'json:null',
            'operator'   => 'varchar(50):null',
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['created_at']],
    ],

    /* ---------------- 漏洞提交 ---------------- */
    'vuln_report' => [
        'comment' => '漏洞提交',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',
            'customer_id' => 'int:default=0',
            'reporter'    => 'varchar(80):null',
            'contact'     => 'varchar(120):null',
            'title'       => 'varchar(180)',
            'asset_type'  => "varchar(30):default=web",      // web|server|app|other
            'target'      => 'varchar(255):null',
            'severity'    => "varchar(10):default=mid",       // low|mid|high|critical
            'description' => 'text:null',
            'poc'         => 'text:null',
            'status'      => "varchar(20):default=pending",   // pending|accepted|fixing|fixed|rejected
            'handle_note' => 'varchar(255):null',
            'handled_by'  => 'int:default=0',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
            'handled_at'  => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['severity']],
    ],

    /* ---------------- 分站（多级分销） ---------------- */
    'subsite_plan' => [
        'comment' => '分站套餐',
        'columns' => [
            'id'              => 'int:pk:ai',
            'name'            => 'varchar(120)',
            'price'           => 'decimal(12,2):default=0',
            'duration_days'   => 'int:default=365',
            'commission_rate' => 'decimal(5,2):default=0',
            'max_children'    => 'int:default=0',        // 0 = 不限
            'sort'            => 'int:default=0',
            'status'          => 'tinyint:default=1',
            'remark'          => 'varchar(255):null',
            'created_at'      => 'datetime:null',
        ],
        'indexes' => [['status'], ['sort']],
    ],

    'subsite' => [
        'comment' => '分站（支持无限级下级分站）',
        'columns' => [
            'id'              => 'int:pk:ai',
            'parent_id'       => 'int:default=0',
            'path'            => 'varchar(255):default=,',  // 物化路径 ,1,5,
            'level'           => 'int:default=1',
            'name'            => 'varchar(120)',
            'username'        => 'varchar(50):unique',
            'password'        => 'varchar(255)',
            'domain'          => 'varchar(120):null',
            'plan_id'         => 'int:default=0',
            'customer_id'     => 'int:default=0',
            'agent_id'        => 'int:default=0',
            'commission_rate' => 'decimal(5,2):default=0',
            'balance'         => 'decimal(12,2):default=0',
            'max_children'    => 'int:default=0',           // 0 = 不限
            'expire_at'       => 'date:null',
            'status'          => 'tinyint:default=1',        // 1 正常 0 停用
            'remark'          => 'varchar(255):null',
            'last_login_at'   => 'datetime:null',
            'created_at'      => 'datetime:null',
            'updated_at'      => 'datetime:null',
        ],
        'indexes' => [['parent_id'], ['level'], ['status'], ['customer_id'], ['agent_id']],
    ],

    'subsite_commission' => [
        'comment' => '分站佣金结算记录',
        'columns' => [
            'id'             => 'int:pk:ai',
            'order_id'       => 'int:default=0',
            'order_sn'       => 'varchar(40):null',
            'beneficiary_id' => 'int:default=0',       // 获得佣金的分站
            'from_id'        => 'int:default=0',       // 产生订单的分站（0=站方直客）
            'level'          => 'int:default=1',       // 相对下单分站的层级（0=归属分站本级）
            'rate'           => 'decimal(5,2):default=0',
            'base_amount'    => 'decimal(12,2):default=0',
            'amount'         => 'decimal(12,2):default=0',
            'status'         => "varchar(20):default=settled",
            'remark'         => 'varchar(255):null',
            'created_at'     => 'datetime:null',
        ],
        'indexes' => [['order_id'], ['beneficiary_id'], ['created_at']],
    ],

    /* ---------------- 工单举报 ---------------- */
    'ticket_report' => [
        'comment' => '工单举报单',
        'columns' => [
            'id'          => 'int:pk:ai',
            'sn'          => 'varchar(40):unique',
            'customer_id' => 'int:default=0',
            'subsite_id'  => 'int:default=0',
            'ticket_id'   => 'int:default=0',
            'category'    => "varchar(30):default=abuse",  // abuse|fraud|content|service|other
            'subject'     => 'varchar(200)',
            'content'     => 'text:null',
            'contact'     => 'varchar(120):null',
            'evidence'    => 'varchar(255):null',
            'priority'    => "varchar(20):default=normal",
            'status'      => "varchar(20):default=pending", // pending|accepted|processing|rejected|closed
            'admin_id'    => 'int:default=0',
            'handle_note' => 'varchar(255):null',
            'reply_count' => 'int:default=0',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
            'handled_at'  => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['subsite_id'], ['status'], ['category'], ['created_at']],
    ],

    'ticket_report_reply' => [
        'comment' => '工单举报回复',
        'columns' => [
            'id'          => 'int:pk:ai',
            'report_id'   => 'int:default=0',
            'author_type' => "varchar(20):default=customer", // customer|subsite|admin
            'author_id'   => 'int:default=0',
            'content'     => 'text',
            'created_at'  => 'datetime:null',
        ],
        'indexes' => [['report_id']],
    ],

    /* ---------------- 展示广告位 ---------------- */
    'ad_slot' => [
        'comment' => '展示广告位（一个位可放多张素材）',
        'columns' => [
            'id'           => 'int:pk:ai',
            'name'         => 'varchar(80)',
            'position'     => "varchar(20):default=goods",   // home 首页|goods 优享财务系统展示页
            'layout'       => "varchar(20):default=goods",   // banner 横幅|goods 商品卡
            'subtitle'     => 'varchar(255):null',
            'sort'         => 'int:default=0',
            'status'       => 'tinyint:default=1',
            'created_at'   => 'datetime:null',
            'updated_at'   => 'datetime:null',
        ],
        'indexes' => [['position'], ['status'], ['sort']],
    ],

    /* ---------------- 售卖品主图 / 详情页图片 ---------------- */
    'marketing_image' => [
        'comment' => '售卖品主图与产品详情页图片（后台管理员制作）',
        'columns' => [
            'id'         => 'int:pk:ai',
            'kind'       => "varchar(20):default=main",   // main 商品主图|detail 产品详情页
            'layout'     => "varchar(20):default=hero",   // hero 大标题|price 价格突出|poster 详情长图
            'title'      => 'varchar(80)',
            'subtitle'   => 'varchar(160):null',
            'tag'        => 'varchar(40):null',
            'price'      => 'varchar(40):null',           // 允许「面议」等非数字文案
            'price_note' => 'varchar(60):null',
            'features'   => 'text:null',                  // 一行一项，详情页渲染为清单
            'cta'        => 'varchar(60):null',
            'brand'      => 'varchar(60):null',           // 留空取站点名称
            'bg'         => "varchar(20):default=blue",   // 渐变底色白名单
            'sort'       => 'int:default=0',
            'status'     => 'tinyint:default=1',
            'created_at' => 'datetime:null',
            'updated_at' => 'datetime:null',
        ],
        'indexes' => [['kind'], ['status'], ['sort']],
    ],

    'ad_item' => [
        'comment' => '展示广告位素材',
        'columns' => [
            'id'         => 'int:pk:ai',
            'slot_id'    => 'int:default=0',
            'title'      => 'varchar(120)',
            'subtitle'   => 'varchar(255):null',
            'tag'        => 'varchar(40):null',        // 角标，如「起步即用」「热销」
            'subgroup'   => 'varchar(40):null',        // 二级分组（对应参考页 spg），用于前台筛选
            'price'      => 'decimal(12,2):default=0',
            'cycle'      => "varchar(20):default=forever", // forever|month|quarter|semiyear|year，或「365d」表示按天数
            'image'      => 'varchar(255):null',
            'bg'         => "varchar(20):default=blue",   // 无图片时的渐变底色（复用横幅配色）
            'btn_text'   => 'varchar(40):null',
            'btn_url'    => 'varchar(255):null',
            'sort'       => 'int:default=0',
            'status'     => 'tinyint:default=1',
            'created_at' => 'datetime:null',
            'updated_at' => 'datetime:null',
        ],
        'indexes' => [['slot_id'], ['status'], ['sort']],
    ],

    /* ---------------- 招聘（职位 + 简历投递） ---------------- */
    'career_job' => [
        'comment' => '招聘职位（投递简历表单的「可发职位」）',
        'columns' => [
            'id'          => 'int:pk:ai',
            'category'    => 'varchar(40):null',              // 职位方向，如「产品与设计」
            'title'       => 'varchar(120)',
            'city'        => 'varchar(40):null',
            'job_type'    => "varchar(20):default=fulltime",  // fulltime|parttime|intern
            'experience'  => 'varchar(40):null',
            'education'   => 'varchar(40):null',
            'salary'      => 'varchar(40):null',              // 允许「面议」等文案
            'headcount'   => 'int:default=1',
            'description' => 'text:null',                     // 岗位职责（一行一项）
            'requirement' => 'text:null',                     // 任职要求（一行一项）
            'contact'     => 'varchar(120):null',             // 该职位接收简历的邮箱，留空用全局邮箱
            'sort'        => 'int:default=0',
            'status'      => 'tinyint:default=1',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
        ],
        'indexes' => [['status'], ['sort'], ['category']],
    ],

    'resume' => [
        'comment' => '简历投递记录',
        'columns' => [
            'id'            => 'int:pk:ai',
            'sn'            => 'varchar(40):unique',          // 投递编号，供应聘者查询进度
            'job_id'        => 'int:default=0',
            'job_title'     => 'varchar(120):null',
            'name'          => 'varchar(60)',
            'gender'        => 'varchar(10):null',
            'birth_year'    => 'int:default=0',
            'phone'         => 'varchar(30)',
            'email'         => 'varchar(120)',
            'city'          => 'varchar(60):null',
            'education'     => 'varchar(40):null',
            'experience'    => 'varchar(40):null',
            'current_company' => 'varchar(120):null',
            'portfolio'     => 'varchar(255):null',           // 作品集 / 主页链接
            'file_path'     => 'varchar(255):null',           // 附件简历相对路径
            'file_name'     => 'varchar(160):null',           // 原始文件名
            'message'       => 'text:null',                   // 自我介绍 / 求职留言
            'source'        => "varchar(20):default=web",     // web 门户|about 关于我们
            'status'        => "varchar(20):default=new",     // new|screening|interview|offer|hired|rejected
            'note'          => 'text:null',                   // 后台处理备注（对投递人可见）
            'admin_id'      => 'int:default=0',
            'ip'            => 'varchar(46):null',
            'created_at'    => 'datetime:null',
            'updated_at'    => 'datetime:null',
        ],
        'indexes' => [['job_id'], ['status'], ['created_at'], ['ip']],
    ],

    /* ---------------- 新闻公告中心 ---------------- */
    'news' => [
        'comment' => '新闻 / 公告',
        'columns' => [
            'id'          => 'int:pk:ai',
            'category'    => "varchar(20):default=notice",    // notice 公告|news 新闻|update 版本更新|activity 活动
            'title'       => 'varchar(200)',
            'summary'     => 'varchar(500):null',
            'cover'       => 'varchar(255):null',
            'content'     => 'text:null',
            'tags'        => 'varchar(200):null',             // 逗号分隔
            'source'      => 'varchar(120):null',
            'is_top'      => 'tinyint:default=0',
            'status'      => 'tinyint:default=1',             // 1 发布|0 草稿
            'views'       => 'int:default=0',                 // 浏览量（原子自增）
            'published_at'=> 'datetime:null',
            'created_at'  => 'datetime:null',
            'updated_at'  => 'datetime:null',
        ],
        'indexes' => [['status'], ['category'], ['is_top'], ['published_at']],
    ],

    /* ---------------- 搜索引擎推送 ---------------- */
    'seo_push_log' => [
        'comment' => '搜索引擎推送记录（百度/神马/IndexNow）',
        'columns' => [
            'id'         => 'int:pk:ai',
            'channel'    => 'varchar(20)',                  // baidu|shenma|indexnow
            'ok'         => 'tinyint:default=0',
            'http_code'  => 'int:default=0',
            'url_count'  => 'int:default=0',
            'message'    => 'varchar(500):null',
            'source'     => "varchar(20):default=manual",    // manual 手动|scheduled 定时
            'created_at' => 'datetime:null',
        ],
        'indexes' => [['channel'], ['ok'], ['created_at']],
    ],

    /* ---------------- 推广商（分销） ---------------- */
    'promoter' => [
        'comment' => '推广商',
        'columns' => [
            'id'              => 'int:pk:ai',
            'customer_id'     => 'int:default=0',                   // 所属客户账号（上传收款码的人）
            'name'            => 'varchar(80)',                      // 推广人 / 推广商名称
            'phone'           => 'varchar(30):null',
            'contact'         => 'varchar(60):null',                 // 联系方式（微信 / QQ 等）
            'commission_rate' => 'decimal(6,4):default=0',           // 平台抽佣比例，0.1500 = 15%
            'alipay_qr'       => 'varchar(255):null',                // 支付宝收款码（uploads/promoter/..）
            'alipay_name'     => 'varchar(80):null',                 // 支付宝收款人
            'wechat_qr'       => 'varchar(255):null',                // 微信收款码（uploads/promoter/..）
            'wechat_name'     => 'varchar(80):null',                 // 微信收款人
            'status'          => "varchar(20):default=pending",      // pending|active|disabled
            'remark'          => 'varchar(255):null',
            'order_count'     => 'int:default=0',
            'order_amount'    => 'decimal(14,2):default=0',          // 累计成交金额
            'settle_amount'   => 'decimal(14,2):default=0',          // 累计已结推广商金额
            'commission_total' => 'decimal(14,2):default=0',          // 累计平台佣金
            'approved_at'     => 'datetime:null',
            'created_at'      => 'datetime:null',
            'updated_at'      => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['name']],
    ],

    'promoter_product' => [
        'comment' => '推广商推广产品',
        'columns' => [
            'id'              => 'int:pk:ai',
            'promoter_id'     => 'int:default=0',
            'name'            => 'varchar(160)',                     // 产品名称
            'amount'          => 'decimal(12,2):default=0',           // 产品金额（用户实付）
            'commission_rate' => 'decimal(6,4):default=0',            // 佣金比例（空=跟随推广商默认）
            'settle_amount'   => 'decimal(12,2):default=0',           // 结算金额 = 金额 ×(1-佣金比例)
            'pay_methods'     => "varchar(30):default=alipay,wechat", // 可用收款方式
            'status'          => "varchar(20):default=active",        // active|offline
            'sort'            => 'int:default=0',
            'remark'          => 'varchar(255):null',
            'created_at'      => 'datetime:null',
            'updated_at'      => 'datetime:null',
        ],
        'indexes' => [['promoter_id'], ['status'], ['sort']],
    ],

    'promoter_order' => [
        'comment' => '推广订单（用户向推广商付款，平台抽佣）',
        'columns' => [
            'id'              => 'int:pk:ai',
            'sn'              => 'varchar(40):unique',
            'promoter_id'     => 'int:default=0',
            'product_id'      => 'int:default=0',
            'product_name'    => 'varchar(160):null',                 // 下单时的产品名快照
            'customer_id'     => 'int:default=0',                     // 下单用户（可为游客 0）
            'buyer'           => 'varchar(60):null',
            'contact'         => 'varchar(120):null',
            'amount'          => 'decimal(12,2):default=0',            // 订单金额
            'commission_rate' => 'decimal(6,4):default=0',             // 平台佣金比例快照
            'commission_amount' => 'decimal(12,2):default=0',          // 平台佣金
            'settle_amount'   => 'decimal(12,2):default=0',            // 推广商应得
            'pay_method'      => "varchar(20):default=alipay",         // alipay|wechat
            'trade_no'        => 'varchar(80):null',                   // 推广商回填的收款流水号
            'proof_image'     => 'varchar(255):null',                  // 付款凭证
            'status'          => "varchar(20):default=pending",        // pending|submitted|settled|rejected
            'admin_remark'    => 'varchar(255):null',
            'created_at'      => 'datetime:null',
            'confirmed_at'    => 'datetime:null',                      // 推广商确认收款
            'settled_at'      => 'datetime:null',                      // 平台结算完成
        ],
        'indexes' => [['promoter_id'], ['product_id'], ['status'], ['customer_id'], ['sn']],
    ],

    /* ---------------- 代理中心 ---------------- */
    'agent_apply' => [
        'comment' => '代理申请（客户提交，后台审核后开通代理账号）',
        'columns' => [
            'id'            => 'int:pk:ai',
            'customer_id'   => 'int:default=0',
            'apply_type'    => "varchar(20):default=personal",   // personal|company
            'subject_name'  => 'varchar(120)',                   // 申请人/企业名称
            'contact_name'  => 'varchar(60):null',               // 联系人
            'phone'         => 'varchar(30):null',
            'email'         => 'varchar(120):null',
            'company'       => 'varchar(160):null',
            'license_no'    => 'varchar(64):null',               // 营业执照/统一社会信用代码
            'domain'        => 'varchar(120):null',              // 拟绑定代理域名
            'channel'       => 'varchar(120):null',              // 推广渠道说明
            'expect_sales'  => 'decimal(12,2):default=0',        // 预估月销售额
            'remark'        => 'text:null',
            'admin_remark'  => 'varchar(255):null',
            'status'        => "varchar(20):default=pending",    // pending|approved|rejected
            'realname_at'   => 'datetime:null',                  // 提交时的实名状态快照时间
            'agent_id'      => 'int:default=0',                  // 审核通过后生成的 agent.id
            'reviewed_at'   => 'datetime:null',
            'reviewer_id'   => 'int:default=0',
            'created_at'    => 'datetime:null',
            'updated_at'    => 'datetime:null',
        ],
        'indexes' => [['customer_id'], ['status'], ['created_at']],
    ],
];
