# 参与贡献

这是一个个人项目，功能范围刻意保持很小。提交改动前请先看一眼下面的说明。

---

## 这个项目**不**打算做的事

为了避免白费功夫，先说明哪些 PR 大概率不会被合并：

- 用户注册、多用户、权限分级
- 评论、点赞、标签、相册
- 图片压缩、转码、加水印（上传的原图不做处理）
- 引入 Composer 依赖或前端构建步骤（当前零依赖）
- 数据库换成 MySQL 或其他需要单独安装的服务

如果要做上面这些，建议自行 fork —— 那是你的项目了。

---

## 欢迎的改动

- 修复缺陷
- 安全加固
- 提升性能（尤其是不牺牲可读性的那种）
- 文档纠正：写得不对、过时、有歧义的地方
- 兼容性改进（其他 Web 服务器、PHP 小版本、非 Windows 环境）

---

## 提交前请确认

**代码风格**

- PHP 文件保留 `declare(strict_types=1)`
- 所有输出经 `e()` 转义，所有 SQL 用预处理语句
- 注释说明**为什么**，而不是复述代码做了什么

**`.ps1` 脚本的编码**

Windows PowerShell 5.1 在没有 BOM 时按 ANSI 解读 `.ps1`，中文会变乱码并导致语法错误。
因此脚本必须满足其一：

- 纯 ASCII（推荐 —— 所有现有测试脚本都是这种）
- 或保存为 **UTF-8 带 BOM**

**跑一遍检查**

```powershell
# 环境预检
powershell -ExecutionPolicy Bypass -File tests/preflight.ps1 -Base http://你的地址

# 功能与安全用例
powershell -ExecutionPolicy Bypass -File tests/run-tests.ps1 -Base http://你的地址 -Password 你的密码

# 文档完整性
powershell -ExecutionPolicy Bypass -File tests/check-docs.ps1
```

---

## 提交信息

用 [Conventional Commits](https://www.conventionalcommits.org/) 的写法，一句话说清做了什么：

```text
fix: reject uploads whose MIME type disagrees with the extension
feat: show free disk space on the backup page
docs: correct the nginx location priority explanation
```

---

## 安全问题

如果发现的是安全缺陷，请不要直接开公开 issue —— 那等于把问题告诉所有人。
先开一个 issue 说明「发现一个安全问题，需要私下沟通」即可，我会回复联系方式。
