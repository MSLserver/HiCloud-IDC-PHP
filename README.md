# HiCloud IDC 控制台

一个轻量级的 IDC 控制台系统，基于 **PHP + SQLite** 开发，零依赖、开箱即用，可直接部署到虚拟主机。界面遵循 Google Material Design 3 设计规范（按钮波纹、浮动标签输入框），支持极简黑 / 极简白双主题并默认跟随系统。

### 演示
**演示站**:console.hicloud.l.cd

**可进后台演示站**:console1.hicloud.l.cd(请勿在此网站后台填入敏感信息) 用户名:admin 密码:123456

<img width="1916" height="1079" alt="1" src="https://github.com/MSLserver/HiCloud-IDC-PHP/blob/main/1.png" />
<img width="1916" height="1079" alt="1" src="https://github.com/MSLserver/HiCloud-IDC-PHP/blob/main/2.png" />
<img width="1916" height="1079" alt="1" src="https://github.com/MSLserver/HiCloud-IDC-PHP/blob/main/3.png" />






## 功能特性

### 用户端
- **用户系统**：注册、登录、找回密码（邮箱验证码）
- **每日签到**：签到领积分，奖励数值后台可调
- **兑换码系统**：输入兑换码充值积分，附兑换记录
- **产品订购**：产品列表 → 确认订单页（展示产品信息与所需积分）→ 确认购买，积分扣减、库存控制
- **我的产品**：已购产品列表
- **个人中心**：账号信息、绑定 / 换绑 / 解绑邮箱（SMTP 验证码）、更改密码
- **第三方登录**：对接聚合登录平台（QQ / 微信 / 支付宝 / 微博 / 百度 / 抖音 / 华为 / 小米 / 谷歌 / 微软 / 钉钉 / Gitee / GitHub，渠道后台可勾选），未注册自动建号、已登录可绑定

### 管理后台
- **概览**：注册用户、积分总量、兑换码使用情况、今日签到、订单总数
- **系统设置**：站点名称、签到积分、SMTP 邮箱、聚合登录（AppID / AppKey / 接口地址 / 渠道开关）
- **产品管理**：添加产品（名称 / 描述 / 价格 / 库存，-1 不限）、编辑、删除
- **订单记录**：全站订单查询
- **兑换码**：批量生成（`XXXX-XXXX-XXXX-XXXX` 格式）、删除未使用码
- **用户管理**：积分调整、角色切换、删除用户

### 界面与体验
- Material Design 3 风格组件：按钮波纹点击效果、轮廓式浮动标签输入框、卡片、表格、选项卡
- 主题三态切换：跟随系统（默认）/ 极简白 / 极简黑，`localStorage` 持久化，无闪烁加载
- 可收缩侧边菜单（收缩状态持久化）
- 响应式布局，移动端可用

### 安全
- `password_hash` 密码哈希、PDO 预处理防 SQL 注入
- 全表单 CSRF 令牌校验
- 会话固定攻击防护（登录 / 注册后 `session_regenerate_id`）
- 签到 / 兑换 / 订购均幂等防重复，邮箱验证码 10 分钟过期

## 技术栈

- PHP 8.0+（无需框架，纯原生）
- SQLite（PDO，首次访问自动建库建表，零配置）
- 原生 CSS / JavaScript（无前端依赖）

## 目录结构

```
├── index.php          # 控制台概述（签到、兑换码、我的订单）
├── products.php       # 订购产品
├── order.php          # 确认订单
├── my_products.php    # 我的产品
├── profile.php        # 个人中心（邮箱绑定、更改密码）
├── login.php          # 登录（含第三方登录入口）
├── register.php       # 注册（支持邮箱验证码）
├── forgot.php         # 忘记密码
├── oauth.php          # 第三方聚合登录回调处理
├── logout.php
├── inc.php            # 核心：数据库 / 布局 / SMTP / OAuth / 工具函数
├── admin/index.php    # 管理后台
└── assets/
    ├── style.css      # 双主题 + Material 组件样式
    └── app.js         # 主题切换 / 菜单收缩 / 波纹效果
```

## 安装部署

### 本地运行
将本仓库的文件打包下载并解压，然后在根目录运行命令:

```bash
php -S localhost:8000 -t .
```

访问 `http://localhost:8000`。

### 虚拟主机 / 服务器

1. 确保 PHP ≥ 8.0，并开启扩展：`pdo_sqlite`（或 `pdo_mysql`）、`curl`、`openssl`、`mbstring`
2. 将所有文件上传至站点根目录（如 `public_html/`），并确保目录可写
3. 浏览器访问站点，会自动跳转**安装向导**：选择 SQLite（免配置）或填写 MySQL 数据库信息(虚拟主机推荐)，并设置站点名称与管理员账号
4. 安装完成后自动跳转登录页，使用刚设置的管理员账号登录

> 安装向导会生成 `config.php`（数据库配置）与 `data/installed.lock`（安装锁定文件），请勿删除；如需重新安装，删除这两个文件及 `data.sqlite` 即可。

### 数据库说明

- **SQLite**：默认方案，免配置，数据库文件为 `data.sqlite`，建议配置 Web 服务器禁止直接下载：
  - Apache（`.htaccess`）：`<Files "data.sqlite">Require all denied</Files>`
  - Nginx：`location ~ \.sqlite$ { deny all; }`
- **MySQL**：安装向导中选择 MySQL 并填写连接信息，数据库不存在时将自动创建（utf8mb4）

## 配置说明

均在 管理后台 → 系统设置 中完成：

- **SMTP 邮箱**：开启后注册 / 绑定邮箱 / 找回密码均需邮箱验证码。以 QQ 邮箱为例：服务器 `smtp.qq.com`、端口 `465`、SSL、密码填 SMTP 授权码
- **聚合登录**：默认凭据为占位符 `YOUR_APPID` / `YOUR_APPKEY`（此状态下第三方登录自动关闭），在后台填入平台真实 AppID / AppKey 即启用，并在平台用户中心授权回调域名（回调地址为 `https://你的域名/oauth.php`），按需勾选登录渠道

## 开源协议

[MIT License](LICENSE)
