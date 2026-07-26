<?php

return [

    // 系统设置开关：设为 'disabled' 时禁止通过接口修改系统设置（SystemController）
    'system_setting' => env('SYSTEM_SETTING'),

    // 许可证显示开关：设为 'hidden' 时隐藏系统许可证信息（Doo::license）
    'system_license' => env('SYSTEM_LICENSE'),

    // 演示账号：登录页展示的演示账号（SystemController::demo）
    'demo_account' => env('DEMO_ACCOUNT'),

    // 演示密码：登录页展示的演示账号密码（SystemController::demo）
    'demo_password' => env('DEMO_PASSWORD'),

    // 管理员密码修改开关：设为 'disabled' 时禁止修改管理员密码（User 模型）
    'password_admin' => env('PASSWORD_ADMIN'),

    // 创始人密码修改开关：设为 'disabled' 时禁止修改创始人密码（User 模型）
    'password_owner' => env('PASSWORD_OWNER'),

    // Manticore 全文搜索服务主机（ManticoreBase）
    'search_host' => env('SEARCH_HOST', 'search'),

    // Manticore 全文搜索服务端口（ManticoreBase）
    'search_port' => env('SEARCH_PORT', 9306),

    // 文件回收站自动清空天数（DeleteTmpTask）
    'auto_empty_file_recycle' => env('AUTO_EMPTY_FILE_RECYCLE', 365),

    // 临时文件自动清理天数（DeleteTmpTask）
    'auto_empty_temp_file' => env('AUTO_EMPTY_TEMP_FILE', 30),

    // Persistent upload backend. Set to s3 only after S3_* is configured and historical uploads are migrated.
    'file_storage_disk' => env('FILE_STORAGE_DISK', 'local'),

    // 在线授权：YeYing AppStore 授权中心地址（OnlineLicense；测试可指向开发环境）
    'online_license_appstore_url' => env('ONLINE_LICENSE_APPSTORE_URL', 'https://appstore.yeying.pub'),

    // 应用市场内网地址：容器部署默认走 appstore 服务名；本机直跑可指向宿主机暴露端口
    'appstore_internal_url' => env('APPSTORE_INTERNAL_URL', 'http://appstore'),

    // AI 网关内网地址：容器部署默认经 nginx 转发；本机直跑时可按需改到单独暴露的 AI 网关
    'ai_internal_url' => env('AI_INTERNAL_URL', 'http://nginx'),

    // 前端微应用打开 AppStore 的入口地址；默认走同源 /appstore 反代，本机直跑可改成独立端口
    'appstore_entry_url' => env('APPSTORE_ENTRY_URL', 'appstore/internal?language={system_lang}&theme={system_theme}'),

    // YeYing community runtime implementation. It does not require an external binary.
    'runtime_driver' => env('RUNTIME_DRIVER', 'yeying'),

    // GnuPG 可执行文件路径；为空时自动从 PATH 和常见安装路径查找
    'gpg_binary' => env('GPG_BINARY'),

    // 钱包登录允许的 EVM chain ID
    'wallet_chain_id' => env('WALLET_CHAIN_ID', '1'),

    // 在线授权：租约剩余不足该天数时触发续期（OnlineLicense）
    'online_license_renew_within_days' => env('ONLINE_LICENSE_RENEW_WITHIN_DAYS', 20),

    // 在线授权：租约剩余不足该天数时在提醒（OnlineLicense）
    'online_license_warn_days' => env('ONLINE_LICENSE_WARN_DAYS', 7),

    // 在线授权：冻结（租约过期）后到吊销的宽限天数（OnlineLicense）
    'online_license_grace_days' => env('ONLINE_LICENSE_GRACE_DAYS', 14),

];
