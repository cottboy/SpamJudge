=== SpamJudge ===
Contributors: cottboy
Tags: ai, llm, spam, spam-comments, anti-spam
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Using AI large language models to automatically detect and filter spam comments, powered by the built-in WordPress AI Client.

== Description ==

SpamJudge uses AI large language models to automatically detect and filter spam comments.

= Features =

* Powered by the built-in WordPress AI Client (WordPress 7.0+), no need to configure API endpoints or keys in the plugin
* AI provider credentials are managed centrally by WordPress under "Settings → AI Credentials"
* Customizable AI prompts to adjust scoring criteria based on the characteristics of the website
* Configurable score thresholds for flexible control over filtering intensity
* Detailed logging to track the processing of each comment

= Workflow =

1. Visitor submits a comment
2. The plugin intercepts the comment and sends it to the AI for scoring
3. The AI returns a score between 0 and 100 (0 = spam, 100 = high quality)
4. The comment is automatically processed based on the score and threshold:
   * Score >= threshold: approved
   * Score < threshold: moved to spam or moved to moderation based on settings
   * Timeout/error: moved to moderation or directly approved based on settings
5. Detailed logs are recorded for administrators to review

= Default system prompt in the current version =

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

= Automatic installation =

1. Log in to the WordPress admin dashboard
2. Navigate to "Plugins" > "Add New"
3. Search for "SpamJudge"
4. Click "Install Now"
5. After installation is complete, click "Activate"

= Manual installation =

1. Download the plugin zip file
2. Log in to the WordPress admin dashboard
3. Go to "Plugins" > "Add New" > "Upload Plugin"
4. Select the downloaded zip file
5. Click "Install Now"
6. After the installation is complete, click "Activate"

= FTP Installation =

1. Unzip the plugin zip file
2. Upload the `spamjudge` folder to the `/wp-content/plugins/` directory via FTP
3. Log in to the WordPress admin dashboard
4. Go to the "Plugins" page
5. Locate "SpamJudge" and click "Activate"

== Frequently Asked Questions ==

= Does it require payment? =

The plugin itself is free, but the AI provider you configure in WordPress requires an API key. Most AI API services require payment based on the number of tokens used.

= Where do I configure the AI provider? =

Go to "Settings → AI Credentials" in the WordPress admin dashboard and configure at least one AI provider that supports text generation. No provider settings are needed inside the plugin itself.

= Where will the comment data be sent? =

Comment data will be sent to the AI provider you configured in WordPress for scoring. Make sure to use a trusted AI provider and review its privacy policy. The plugin itself does not collect or store any data on third-party servers.

= How much additional wait time will be added when submitting a comment? =

It adds about 3 seconds, depending on the service provider and model used. Using a non-thinking model can effectively reduce wait time.

== Screenshots ==

1. Log interface
2. Settings interface

== Changelog ==

= 1.2.0（2026-09-19） =
* Switched to the built-in WordPress AI Client (WordPress 7.0+), no more provider/endpoint/key settings in the plugin
* AI provider credentials are now managed by WordPress under "Settings → AI Credentials"

= 1.1.0（2025-12-03） =
* Compatible with the /v1/responses endpoint
* Endpoint URL auto-completion
* Deprecation of "temperature"

= 1.0.0（2025-11-01） =
* First version

== Upgrade Notice ==

= 1.2.0 =
Requires WordPress 7.0+. The plugin now uses the built-in WordPress AI Client; configure your AI provider under "Settings → AI Credentials".
