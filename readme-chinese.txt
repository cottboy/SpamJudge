=== SpamJudge ===
Contributors: cottboy
Tags: ai, llm, spam, spam-comments, anti-spam
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

使用 AI 大模型自动检测和过滤垃圾评论，支持兼容 OpenAI 格式的 API。

== Description ==

SpamJudge 使用 AI 大模型自动检测和过滤垃圾评论。

= 特征 =

* 支持任何兼容 OpenAI 格式的 API
* 自定义 AI 提示词，根据网站特点调整评分标准
* 可配置的分数阈值，灵活控制过滤强度
* 详细的日志记录，追踪每条评论的处理过程

= 工作流程 =

1. 访客提交评论
2. 插件拦截评论，发送给 AI 进行评分
3. AI 返回 0-100 的分数（0=垃圾，100=优质）
4. 根据分数和阈值自动处理评论：
   * 分数 >= 阈值：通过
   * 分数 < 阈值：根据设置移到垃圾或移到待审核
   * 超时/错误：根据设置移到待审核或直接通过
5. 记录详细日志供管理员查看

= 当前版本默认系统提示词 =

`
You are a spam comment detection system. Your ONLY task is to output a single number between 0 and 100.

SCORING RULES:
- 0-20: Obvious spam (ads, malicious links, gibberish)
- 21-40: Likely spam (suspicious links, bot-like comments)
- 41-60: Uncertain (short comments, borderline content)
- 61-80: Likely legitimate (relevant, thoughtful)
- 81-100: Clearly legitimate (detailed, helpful, on-topic)

CRITICAL INSTRUCTIONS:
1. Output ONLY a number (0-100)
2. NO explanations
3. NO additional text
4. NO punctuation
5. Just the number

Example valid outputs: 85
Example INVALID outputs: "Score: 85", "85 points", "I think it's 85"

If you output anything other than a single number, the system will fail.
`

== Installation ==

= 自动安装 =

1. 登录 WordPress 管理后台
2. 进入"插件" > "添加插件"
3. 搜索 "SpamJudge"
4. 点击"立即安装"
5. 安装完成后点击"启用"

= 手动安装 =

1. 下载插件 zip 文件
2. 登录 WordPress 管理后台
3. 进入"插件" > "添加插件" > "上传插件"
4. 选择下载的 zip 文件
5. 点击"立即安装"
6. 安装完成后点击"启用"

= FTP 安装 =

1. 解压插件 zip 文件
2. 通过 FTP 上传 `spamjudge` 文件夹到 `/wp-content/plugins/` 目录
3. 登录 WordPress 管理后台
4. 进入"插件"页面
5. 找到 "SpamJudge" 并点击"启用"

== Frequently Asked Questions ==

= 需要付费吗？ =

插件本身是免费的，但你需要一个 AI API 密钥，大多数 AI API 服务需要付费使用，根据使用的 token 数计费。

= 评论数据会被发送到哪里？ =

评论数据会被发送到你配置的 API 进行评分，请确保使用可信的 API 服务商并查看其隐私政策，插件本身不会收集或存储任何数据到第三方服务器。

= 提交评论时会额外增加多少等待时间？ =

增加3秒左右，具体根据使用的服务提供商以及模型有关，使用非思考模型可有效降低等待时间。

= 所有选项都配置好了，依旧请求失败怎么办？ =

请检查 API 端点后面是否添加了/v1/chat/completions。

== Screenshots ==

1. 日志界面
2. 设置界面

== Changelog ==

= 1.0.0（2025-11-01） =
首个版本发布

== Upgrade Notice ==

= 1.0.0 =
首个版本发布
