

# REST-Api-with-Slim-PHP

基于 Slim PHP 框架构建的 RESTful API 项目。

## 项目简介

本项目是一个现代化、可扩展的 RESTful API，使用 Slim PHP 4 框架开发。提供了完整的 API 骨架，包含数据库集成、JWT 认证、邮件发送、日志记录等核心功能。

## 核心特性

- **Slim PHP 框架**：轻量级框架，高效的路由和中间件管理
- **RESTful 架构**：遵循 REST 设计原则，确保 API 的可扩展性和可维护性
- **数据库集成**：支持 MySQL 数据库，包含数据库日志中间件
- **JWT 认证**：内置 JWT 认证机制，支持令牌生成和验证
- **邮件发送**：提供邮件发送功能
- **CORS 支持**：跨域资源共享支持
- **自定义响应**：灵活的响应处理和 JSON 编码选项
- **日志记录**：数据库日志处理器
- **单元测试**：完整的 PHPUnit 测试用例

## 软件架构

```
REST-Api-with-Slim-PHP/
├── public/                 # Web│   ├── index.php          入口目录
 # 入口文件
│   └── .htaccess          # Apache 重写规则
├── src/
│   ├── App/               # 核心应用组件
│   │   ├── App.php        # 应用主类
│   │   ├── Container.php  # 依赖注入容器
│   │   ├── Cors.php       # CORS 处理
│   │   ├── Database.php   # 数据库连接
│   │   ├── Routes.php     # 路由定义
│   │   ├── Middlewares.php # 中间件
│   │   └── ...
│   ├── Controller/        # 控制器
│   │   └── Home.php       # 首页控制器
│   └── Common/            # 公共工具类
│       ├── auth.php       # JWT 认证
│       └── mail.php       # 邮件发送
├── tests/integration/     # 集成测试
├── db/                    # 数据库文件
│   └── northwind.sql      # Northwind 数据库示例
├── composer.json          # Composer 依赖配置
├── .env.example           # 环境变量示例
└── phpunit.xml            # PHPUnit 配置
```

## 环境要求

- PHP 8.0 或更高版本
- Composer
- Web 服务器（Apache/Nginx）
- MySQL 数据库

## 安装步骤

1. **克隆项目**
   ```bash
   git clone https://gitee.com/sekihin/REST-Api-with-Slim-PHP.git
   ```

2. **进入项目目录**
   ```bash
   cd REST-Api-with-Slim-PHP
   ```

3. **安装依赖**
   ```bash
   composer install
   ```

4. **配置环境变量**
   ```bash
   cp .env.example .env
   ```
   
   根据实际情况修改 `.env` 文件中的数据库配置：
   ```env
   DB_HOST=localhost
   DB_NAME=your_database_name
   DB_USER=your_database_user
   DB_PASS=your_database_password
   ```

5. **导入数据库**
   ```bash
   # 导入示例数据库（如果需要）
   mysql -u your_database_user -p your_database_name < db/northwind.sql
   ```

6. **启动开发服务器**
   ```bash
   php -S localhost:8080 -t public
   ```

## API 接口文档

### 基础信息

- **API 名称**：slim4-api-skeleton
- **API 版本**：1.1.0
- **基础路径**：`/`

### 端点列表

| 端点 | 方法 | 描述 |
|------|------|------|
| `/` | GET | API 帮助信息 |
| `/status` | GET | 获取 API 状态 |
| `/users` | GET | 获取用户列表 |
| `/users/{id}` | GET | 获取指定用户 |
| `/users` | POST | 创建新用户 |
| `/users/{id}` | PUT | 更新用户信息 |
| `/users/{id}` | DELETE | 删除用户 |

## 使用示例

### 创建用户

```bash
curl -X POST http://localhost:8080/users \
  -H "Content-Type: application/json" \
  -d '{"name": "张三", "email": "zhangsan@example.com"}'
```

### 获取用户

```bash
curl -X GET http://localhost:8080/users/1
```

### 获取 API 状态

```bash
curl -X GET http://localhost:8080/status
```

## 测试

项目包含完整的 PHPUnit 测试用例，运行测试：

```bash
./vendor/bin/phpunit
```

## 贡献指南

欢迎贡献代码！请遵循以下步骤：

1. Fork 本仓库
2. 创建您的特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交您的更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 创建一个 Pull Request

## 开源许可证

本项目采用 MIT 许可证。

## 鸣谢

- [Slim PHP Framework](https://www.slimframework.com/)
- [Gitee](https://gitee.com/) - 代码托管平台