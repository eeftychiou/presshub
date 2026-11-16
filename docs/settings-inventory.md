# PressHub settings inventory

> Generated from `presshub-ai-editor/includes/settings-registry-data.php` by
> `php presshub-ai-editor/scripts/settings-inventory.php`. Do not edit by hand:
> change the registry and run the script. CI fails when this file is stale.

## Summary

| Page | Options | Not shown on screen | Secrets |
|---|---|---|---|
| Settings → PressHub AI | 203 | 27 | 5 |
| PressHub Social → Settings | 82 | 4 | 9 |
| **Total** | **285** | | |

**Scheduled for removal:** 4 option(s), see the last section. They are still registered and keep their stored values until the clean-up phase (#237).

## Settings → PressHub AI

### Tab `providers` (17)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_ai_provider` | AI Provider | string | not shown on screen |
| `presshub_ai_api_key` | API Key | string | not shown on screen, secret, masked |
| `presshub_ai_remove_api_key` | Remove api key | boolean | not shown on screen, removes `presshub_ai_api_key` |
| `presshub_ai_model_openai` | Model (Openai) | string | not shown on screen |
| `presshub_ai_temperature_openai` | Temperature (Openai) | number | not shown on screen |
| `presshub_ai_max_tokens_openai` | Max Tokens (Openai) | integer | not shown on screen |
| `presshub_ai_timeout_openai` | Timeout (Openai) | integer | not shown on screen |
| `presshub_ai_model_anthropic` | Model (Anthropic) | string | not shown on screen |
| `presshub_ai_temperature_anthropic` | Temperature (Anthropic) | number | not shown on screen |
| `presshub_ai_max_tokens_anthropic` | Max Tokens (Anthropic) | integer | not shown on screen |
| `presshub_ai_timeout_anthropic` | Timeout (Anthropic) | integer | not shown on screen |
| `presshub_ai_model_gemini` | Model (Gemini) | string | not shown on screen |
| `presshub_ai_temperature_gemini` | Temperature (Gemini) | number | not shown on screen |
| `presshub_ai_max_tokens_gemini` | Max Tokens (Gemini) | integer | not shown on screen |
| `presshub_ai_timeout_gemini` | Timeout (Gemini) | integer | not shown on screen |
| `presshub_ai_openai_org` | OpenAI Organization ID (optional) | string | not shown on screen |
| `presshub_ai_anthropic_version` | Anthropic API Version | string | not shown on screen |

### Tab `coauthor` (14)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_ai_fetch_urls` | Fetch and extract source URLs server-side before prompting (recommended — models cannot… | boolean | checkbox |
| `presshub_ai_coauthor_provider` | Active Co-Author Provider | string |  |
| `presshub_ai_coauthor_model` | Custom Model Override | string |  |
| `presshub_ai_coauthor_temperature` | Sampling Temperature | number |  |
| `presshub_ai_coauthor_max_tokens` | Maximum Output Tokens | integer |  |
| `presshub_ai_coauthor_timeout` | Request Timeout (seconds) | integer |  |
| `presshub_ai_coauthor_max_source_chars` | Max Source Material Characters | integer |  |
| `presshub_ai_qa_enabled` | Enable QA Review System | integer | checkbox |
| `presshub_ai_qa_include_briefings` | Include Daily Briefings | integer | checkbox |
| `presshub_ai_qa_min_score` | Minimum Passing Score | integer |  |
| `presshub_ai_qa_notify_editor` | Notify Editor on Failures | integer | checkbox |
| `presshub_ai_qa_editor_email` | Editor Notification Email | string |  |
| `presshub_ai_qa_article_prompt` | Article QA Review Prompt Template | string |  |
| `presshub_ai_qa_briefing_prompt` | Daily Briefing QA Review Prompt Template | string |  |

### Tab `advanced` (19)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_ai_debug_prompts` | Raw debug dump: prompts | boolean | checkbox |
| `presshub_ai_remove_google_cloud_api_key` | Remove google cloud api key | boolean | not shown on screen, removes `presshub_ai_google_cloud_api_key` |
| `presshub_ai_remove_github_token` | Remove github token | boolean | not shown on screen, removes `presshub_ai_github_token` |
| `presshub_ai_github_token` | GitHub Token (optional) | string | secret, masked |
| `presshub_ai_google_cloud_api_key` | Google Cloud API Key (Imagen/TTS) | string | secret, masked |
| `presshub_ai_gcloud_project_id` | Google Cloud Project ID (Imagen) | string |  |
| `presshub_ai_imagen_region` | Imagen Region | string |  |
| `presshub_ai_rate_limit_enabled` | Enable Per-User Rate Limit | boolean | checkbox |
| `presshub_ai_rate_limit_per_hour` | Requests per Window | integer |  |
| `presshub_ai_rate_limit_window_seconds` | Window Length (seconds) | integer |  |
| `presshub_ai_research_retention_days` | Research Log Retention (days) | integer |  |
| `presshub_ai_log_level` | Diagnostic Log Level | string |  |
| `presshub_ai_log_tts_payloads` | Raw debug dump: TTS payloads | boolean | checkbox |
| `presshub_ai_generation_record_retention_days` | Generation Record Retention (days) | integer |  |
| `presshub_ai_log_file_retention_days` | Log File Retention (days) | integer |  |
| `presshub_ai_token_log_retention_days` | Token Log Retention (days) | integer |  |
| `presshub_ai_audit_log_retention_days` | Audit Log Retention (days) | integer |  |
| `presshub_ai_social_meta_enabled` | Enable Social Metadata (OpenGraph & Twitter Cards) | boolean | checkbox |
| `presshub_ai_social_meta_all_posts` | Enable for All Published Posts | boolean | checkbox |

### Tab `briefing` (137)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_ai_briefing_tts_api_key` | Speech Generation API Key (Google AI Studio / Gemini) | string | not shown on screen, secret, masked |
| `presshub_ai_remove_briefing_tts_api_key` | Remove briefing tts api key | boolean | not shown on screen, removes `presshub_ai_briefing_tts_api_key` |
| `presshub_ai_briefing_tts_engine` | Speech engine | string |  |
| `presshub_ai_briefing_tts_model` | Voice Generation AI Model | string |  |
| `presshub_ai_briefing_tts_timeout` | Speech AI Timeout (seconds) | integer |  |
| `presshub_ai_briefing_sources` | News Source URLs | array |  |
| `presshub_ai_briefing_schedule_enabled` | Enable Scheduled Briefing Hub | integer | checkbox |
| `presshub_ai_briefing_harvest_time` | Morning Harvest Time | string |  |
| `presshub_ai_briefing_generation_time` | Generation Trigger Time | string |  |
| `presshub_ai_briefing_noon_schedule_enabled` | Enable Noon Briefing Schedule | integer | checkbox |
| `presshub_ai_briefing_noon_harvest_time` | Noon Harvest Time | string |  |
| `presshub_ai_briefing_noon_generation_time` | Noon Generation Time | string |  |
| `presshub_ai_social_monitor_enabled` | Monitor Social Accounts | string | checkbox |
| `presshub_ai_social_monitor_interval_min` | Scan Schedule | string |  |
| `presshub_ai_social_monitor_max_age_hours` | Scan Schedule | string |  |
| `presshub_ai_social_monitor_max_posts_per_account` | Post Limits | string |  |
| `presshub_ai_social_monitor_max_posts_per_harvest` | Post Limits | string |  |
| `presshub_ai_social_monitor_x_list_url` | X List (optional) | string |  |
| `presshub_ai_social_monitor_transcribe_videos` | Videos | string | checkbox |
| `presshub_ai_social_monitor_max_video_seconds` | Videos | string |  |
| `presshub_ai_social_monitor_max_video_mb` | Videos | string |  |
| `presshub_ai_social_trending_enabled` | Publish Trending Roundup | string | checkbox |
| `presshub_ai_social_trending_interval_hours` | Publish Trending Roundup | string |  |
| `presshub_ai_social_trending_platform_x` | Platforms | string | checkbox |
| `presshub_ai_social_trending_platform_tiktok` | Platforms | string | checkbox |
| `presshub_ai_social_trending_platform_instagram` | Platforms | string | checkbox |
| `presshub_ai_social_trending_platform_facebook` | Platforms | string | checkbox |
| `presshub_ai_social_trending_keywords` | Search Terms (X, TikTok) | string |  |
| `presshub_ai_social_trending_hashtags` | Hashtags (Instagram, TikTok) | string |  |
| `presshub_ai_social_trending_facebook_pages` | Facebook Pages | string |  |
| `presshub_ai_social_trending_category` | Roundup Post | string |  |
| `presshub_ai_social_trending_post_mode` | Roundup Post | string |  |
| `presshub_ai_social_trending_post_status` | Roundup Post | string |  |
| `presshub_ai_social_trending_title` | Roundup Post | string |  |
| `presshub_ai_social_trending_max_items` | Selection | string |  |
| `presshub_ai_social_trending_max_per_platform` | Selection | string |  |
| `presshub_ai_social_trending_max_age_hours` | Selection | string |  |
| `presshub_ai_social_trending_min_engagement` | Selection | string |  |
| `presshub_ai_social_trending_excluded_words` | Excluded Words | string |  |
| `presshub_ai_social_trending_briefing_enabled` | Use in the Briefing | string | checkbox |
| `presshub_ai_social_trending_briefing_count` | Use in the Briefing | string |  |
| `presshub_ai_press_inbox_enabled` | Read Press Release Inbox | string | checkbox |
| `presshub_ai_press_inbox_host` | Incoming Mail Server (IMAP) | string |  |
| `presshub_ai_press_inbox_port` | Port | string |  |
| `presshub_ai_press_inbox_encryption` | Incoming Mail Server (IMAP) | string |  |
| `presshub_ai_press_inbox_verify_ssl` | Incoming Mail Server (IMAP) | string | checkbox |
| `presshub_ai_press_inbox_username` | Mailbox Username | string |  |
| `presshub_ai_press_inbox_password` | Mailbox Password | string | secret, masked |
| `presshub_ai_remove_press_inbox_password` | Remove press inbox password | string | not shown on screen, removes `presshub_ai_press_inbox_password` |
| `presshub_ai_press_inbox_folder` | Folders | string |  |
| `presshub_ai_press_inbox_processed_folder` | Move used emails to | string |  |
| `presshub_ai_press_inbox_allowed_senders` | Allowed Senders | string |  |
| `presshub_ai_press_inbox_max_messages` | Inbox Limits | string |  |
| `presshub_ai_press_inbox_max_age_hours` | Inbox Limits | string |  |
| `presshub_ai_press_inbox_max_attachment_mb` | Inbox Limits | string |  |
| `presshub_ai_press_inbox_timeout` | Inbox Limits | string |  |
| `presshub_ai_press_inbox_extract_attachments` | Inbox Limits | string | checkbox |
| `presshub_ai_harvest_time_budget` | Harvest Execution Time Budget (seconds) | integer |  |
| `presshub_ai_curation_max_articles` | Maximum Articles Sent to Curation LLM | integer |  |
| `presshub_ai_curation_max_chars_per_article` | Maximum Characters per Article (Curation) | integer |  |
| `presshub_ai_briefing_text_preset` | Text Instruction Preset | string |  |
| `presshub_ai_briefing_podcast_preset` | Podcast dialogue preset | string |  |
| `presshub_ai_briefing_target_duration` | Target Podcast Duration | string |  |
| `presshub_ai_briefing_host_count` | Podcast Presenters Count | integer |  |
| `presshub_ai_briefing_host_female` | Presenter 1 Name | string |  |
| `presshub_ai_briefing_host_male` | Presenter 2 Name | string |  |
| `presshub_ai_briefing_host_tertiary` | Presenter 3 Name | string |  |
| `presshub_ai_briefing_voice_female` | Presenter 1 Voice (TTS) | string |  |
| `presshub_ai_briefing_voice_male` | Presenter 2 Voice (TTS) | string |  |
| `presshub_ai_briefing_voice_tertiary` | Presenter 3 Voice (TTS) | string |  |
| `presshub_ai_briefing_audio_split_by_topic` | Split Audio Synthesis by Topic | integer | checkbox |
| `presshub_ai_briefing_audio_transition_sfx` | Topic Transition SFX | string |  |
| `presshub_ai_briefing_audio_intro_sfx` | Podcast Intro SFX | string |  |
| `presshub_ai_briefing_audio_outro_sfx` | Podcast Outro SFX | string |  |
| `presshub_ai_briefing_voice_speed` | Speaking Rate / Speed | number |  |
| `presshub_ai_briefing_voice_pitch` | Voice Pitch Tuning | number |  |
| `presshub_ai_briefing_tts_style` | Speaking Delivery Style (logosAI) | string |  |
| `presshub_ai_briefing_tts_custom_style` | Custom Speaking Style Prompt | string |  |
| `presshub_ai_briefing_tts_payload_mode` | Gemini Speech Payload Structure | string |  |
| `presshub_ai_briefing_tts_system_instruction` | Gemini Speech System Instruction | string |  |
| `presshub_ai_briefing_tts_turn_pause_ms` | Turn Pause Duration (ms) | integer |  |
| `presshub_ai_briefing_tts_topic_pause_ms` | Topic Transition Pause (ms) | integer |  |
| `presshub_ai_briefing_tts_request_delay_ms` | Inter-Turn Throttle Delay (ms) | integer |  |
| `presshub_ai_briefing_tts_max_retries` | Speech API Max Retries (429/5xx) | integer |  |
| `presshub_ai_briefing_tts_retry_backoff_ms` | Speech API Retry Backoff (ms) | integer |  |
| `presshub_ai_briefing_tts_requests_per_minute` | Speech API Requests per Minute | integer |  |
| `presshub_ai_briefing_tts_max_retry_wait_ms` | Speech API Max Retry Wait (ms) | integer |  |
| `presshub_ai_briefing_tts_cron_retry_delay_min` | Scheduled Synthesis Retry Delay (min) | integer |  |
| `presshub_ai_briefing_text_category` | Text Briefing Category | integer |  |
| `presshub_ai_briefing_podcast_category` | Podcast Category | integer |  |
| `presshub_ai_briefing_text_status` | Text Post Status | string |  |
| `presshub_ai_briefing_text_title_prefix` | Text Story Title Prefix | string |  |
| `presshub_ai_briefing_text_title_date_format` | Text Story Title Date Format | string |  |
| `presshub_ai_briefing_noon_text_title_prefix` | Noon Text Story Title Prefix | string |  |
| `presshub_ai_briefing_podcast_title_prefix` | Morning Podcast Title Prefix | string |  |
| `presshub_ai_briefing_morning_podcast_title_prefix` | Briefing morning podcast title prefix | string | not shown on screen |
| `presshub_ai_briefing_noon_podcast_title_prefix` | Noon Podcast Title Prefix | string |  |
| `presshub_ai_briefing_featured_image_ids_morning` | Morning Featured Image Pool | string |  |
| `presshub_ai_briefing_featured_image_ids_noon` | Noon Featured Image Pool | string |  |
| `presshub_ai_briefing_featured_image_ids` | Featured Image Pool (Attachment IDs) | string | not shown on screen |
| `presshub_ai_briefing_featured_image_source` | Text Briefing Featured Image Source | string |  |
| `presshub_ai_briefing_podcast_featured_image_source` | Podcast Featured Image Source | string |  |
| `presshub_ai_briefing_podcast_include_text` | Include Transcript in Podcast Post | integer |  |
| `presshub_ai_briefing_podcast_status` | Podcast Post Status | string |  |
| `presshub_ai_briefing_text_prompt` | Curator System Prompt | string |  |
| `presshub_ai_briefing_podcast_prompt_1` | Podcast Dialogue System Prompt (1 Host) | string | not shown on screen |
| `presshub_ai_briefing_podcast_prompt_2` | Podcast Dialogue System Prompt (2 Hosts) | string | not shown on screen |
| `presshub_ai_briefing_podcast_prompt_3` | Podcast Dialogue System Prompt (3 Hosts) | string | not shown on screen |
| `presshub_ai_briefing_text_provider` | Curator AI Provider | string |  |
| `presshub_ai_briefing_text_model` | Curator Model Override | string |  |
| `presshub_ai_briefing_text_temperature` | Curator temperature | number |  |
| `presshub_ai_briefing_text_max_tokens` | Curator Maximum Output Tokens | integer |  |
| `presshub_ai_briefing_text_timeout` | Curator timeout (s) | integer |  |
| `presshub_ai_briefing_podcast_provider` | Scriptwriter AI Provider | string |  |
| `presshub_ai_briefing_podcast_model` | Scriptwriter Model Override | string |  |
| `presshub_ai_briefing_podcast_temperature` | Scriptwriter temperature | number |  |
| `presshub_ai_briefing_podcast_max_tokens` | Scriptwriter Maximum Output Tokens | integer |  |
| `presshub_ai_briefing_podcast_timeout` | Scriptwriter timeout (s) | integer |  |
| `presshub_ai_briefing_podcast_tts_provider` | Speech AI Provider (logosAI) | string |  |
| `presshub_ai_memory_enabled` | Enable Editorial Memory | integer | checkbox |
| `presshub_ai_memory_daily_days` | Recent Memory Window (days) | integer |  |
| `presshub_ai_memory_background_days` | Background Memory Window (days) | integer |  |
| `presshub_ai_memory_briefing_budget_chars` | Briefing Memory Budget (chars) | integer |  |
| `presshub_ai_memory_podcast_budget_chars` | Podcast Memory Budget (chars) | integer |  |
| `presshub_ai_memory_retention_days` | Memory Retention Window (days) | integer |  |
| `presshub_ai_briefing_memory_provider` | Memory Model Provider | string |  |
| `presshub_ai_briefing_memory_model` | Memory Model Override | string |  |
| `presshub_ai_briefing_podcast_style` | Active Dialogue Style | string |  |
| `presshub_ai_briefing_podcast_prompt_1_default_greek_chat` | Briefing podcast prompt 1 default greek chat | string |  |
| `presshub_ai_briefing_podcast_prompt_2_default_greek_chat` | Briefing podcast prompt 2 default greek chat | string |  |
| `presshub_ai_briefing_podcast_prompt_3_default_greek_chat` | Briefing podcast prompt 3 default greek chat | string |  |
| `presshub_ai_briefing_podcast_prompt_1_bbc_broadcasting_standards` | Briefing podcast prompt 1 bbc broadcasting standards | string |  |
| `presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards` | Briefing podcast prompt 2 bbc broadcasting standards | string |  |
| `presshub_ai_briefing_podcast_prompt_3_bbc_broadcasting_standards` | Briefing podcast prompt 3 bbc broadcasting standards | string |  |
| `presshub_ai_briefing_podcast_prompt_1_conversational_news_reporting` | Briefing podcast prompt 1 conversational news reporting | string |  |
| `presshub_ai_briefing_podcast_prompt_2_conversational_news_reporting` | Briefing podcast prompt 2 conversational news reporting | string |  |
| `presshub_ai_briefing_podcast_prompt_3_conversational_news_reporting` | Briefing podcast prompt 3 conversational news reporting | string |  |

### Tab `images` (11)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_ai_image_feature_enabled` | Enable Feature | integer |  |
| `presshub_ai_image_prompt_provider` | Article Analysis AI Provider | string |  |
| `presshub_ai_image_prompt_model` | Article Analysis Model Override | string |  |
| `presshub_ai_image_generation_provider` | Image Generation AI Provider | string |  |
| `presshub_ai_image_aspect_ratio` | Default Aspect Ratio | string |  |
| `presshub_ai_image_analysis_prompt` | Article Analysis Prompt Template | string |  |
| `presshub_ai_image_metadata_enabled` | AI Image Metadata | integer | checkbox |
| `presshub_ai_seo_auto_enabled` | Automatic Tags & SEO | integer | checkbox |
| `presshub_ai_briefing_seo_enhance_enabled` | Daily Briefing SEO Structure | integer | checkbox |
| `presshub_ai_seo_max_tags` | Maximum Tags per Post | integer |  |
| `presshub_ai_seo_review_enabled` | SEO Score in Editorial Review | integer | checkbox |

### Tab `copilot` (5)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_ai_copilot_provider` | Active Copilot Provider | string |  |
| `presshub_ai_copilot_model` | Custom Model Override | string |  |
| `presshub_ai_copilot_temperature` | Sampling Temperature | number |  |
| `presshub_ai_copilot_max_tokens` | Maximum Output Tokens | integer |  |
| `presshub_ai_copilot_timeout` | Request Timeout (seconds) | integer |  |

## PressHub Social → Settings

### Tab `general` (12)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_social_auto_publish_enabled` | Auto-Publish on Post | boolean | checkbox |
| `presshub_social_enabled_platforms` | Active Platforms | array | checkbox |
| `presshub_social_enabled_twitter` | Enabled twitter | boolean | not shown on screen |
| `presshub_social_enabled_facebook` | Enabled facebook | boolean | not shown on screen |
| `presshub_social_enabled_instagram` | Enabled instagram | boolean | not shown on screen |
| `presshub_social_enabled_tiktok` | Enabled tiktok | boolean | not shown on screen |
| `presshub_social_copy_mode` | Copy Generation Mode | string |  |
| `presshub_social_github_token` | GitHub Personal Access Token (Auto-Updates) | string | secret, masked, **unused** |
| `presshub_social_engine_twitter` | Twitter Engine | string |  |
| `presshub_social_engine_facebook` | Facebook Engine | string |  |
| `presshub_social_engine_instagram` | Instagram Engine | string |  |
| `presshub_social_engine_tiktok` | Tiktok Engine | string |  |

### Tab `ai` (10)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_social_ai_provider` | AI Provider | string |  |
| `presshub_social_ai_model` | Model Override | string |  |
| `presshub_social_ai_temperature` | Temperature | string |  |
| `presshub_social_ai_max_tokens` | Max Tokens | integer |  |
| `presshub_social_ai_timeout` | Request Timeout | integer |  |
| `presshub_social_post_images_enabled` | Generate Images for Posts | boolean | checkbox |
| `presshub_social_post_images_twitter` | X / Twitter Images | integer |  |
| `presshub_social_post_images_facebook` | Facebook Images | integer |  |
| `presshub_social_post_images_instagram` | Instagram Image | integer |  |
| `presshub_social_post_images_tiktok` | TikTok Image | integer |  |

### Tab `queue` (6)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_social_max_attempts` | Max Retry Attempts | integer |  |
| `presshub_social_dispatch_interval` | Dispatch Interval | integer |  |
| `presshub_social_batch_size` | Batch Size | integer |  |
| `presshub_social_retry_base_delay` | Retry Base Delay | integer |  |
| `presshub_social_alert_email_enabled` | Enable Failure Email Alerts | boolean | checkbox |
| `presshub_social_alert_email_address` | Alert Recipient Email | string |  |

### Tab `worker` (28)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_social_worker_mode` | Worker Connection Mode | string |  |
| `presshub_social_worker_url` | Worker Service Endpoint | string |  |
| `presshub_social_worker_secret` | Worker Secret Token | string | secret, masked |
| `presshub_social_worker_timeout` | Worker Timeout | integer |  |
| `presshub_social_twitter_max_chars` | X/Twitter Max Characters | integer |  |
| `presshub_social_instagram_card_width` | Instagram Card Width | integer |  |
| `presshub_social_instagram_card_height` | Instagram Card Height | integer |  |
| `presshub_social_tiktok_video_width` | TikTok Video Width | integer |  |
| `presshub_social_tiktok_video_height` | TikTok Video Height | integer |  |
| `presshub_social_tiktok_video_duration` | TikTok Video Duration | integer |  |
| `presshub_social_tiktok_narration_enabled` | TikTok Narration (TTS) | boolean | checkbox |
| `presshub_social_tiktok_narration_outro` | TikTok Narration Outro | string |  |
| `presshub_social_tiktok_narration_voice` | TikTok Narration Voice | string |  |
| `presshub_social_tiktok_shorts_enabled` | TikTok Shorts | boolean | checkbox |
| `presshub_social_instagram_shorts_enabled` | Instagram Reels (Shorts) | boolean | checkbox |
| `presshub_social_tiktok_shorts_count` | Shorts per Podcast | integer |  |
| `presshub_social_tiktok_shorts_spacing_minutes` | Time Between Shorts | integer |  |
| `presshub_social_tiktok_shorts_max_words` | Short Script Length | integer |  |
| `presshub_social_tiktok_shorts_images` | Story Images per Short | integer |  |
| `presshub_social_tiktok_shorts_caption_words` | Short Post Text Length | integer |  |
| `presshub_social_tiktok_shorts_narrators` | Short Narrators | integer |  |
| `presshub_social_tiktok_shorts_voice_1` | Short Narrator Voice | string |  |
| `presshub_social_tiktok_shorts_voice_2` | Second Narrator Voice | string |  |
| `presshub_social_tiktok_shorts_voice_speed` | Short Voice Speed | integer |  |
| `presshub_social_tiktok_shorts_music_enabled` | Shorts Background Music | boolean | checkbox |
| `presshub_social_tiktok_shorts_music_volume` | Music Volume | integer |  |
| `presshub_social_tiktok_shorts_music_tracks` | Music Tracks per Mood | integer |  |
| `presshub_social_tiktok_shorts_music_model` | Music Model | string |  |

### Tab `credentials` (10)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_social_meta_page_id` | Facebook Page ID | string |  |
| `presshub_social_meta_access_token` | Meta Page Access Token | string | secret, masked |
| `presshub_social_instagram_account_id` | Instagram Account ID | string |  |
| `presshub_social_twitter_bearer_token` | Twitter Bearer Token | string | secret, masked |
| `presshub_social_twitter_api_key` | Twitter API Key | string | secret, masked, **unused** |
| `presshub_social_twitter_api_secret` | Twitter API Secret | string | secret, masked, **unused** |
| `presshub_social_twitter_access_token` | Twitter Access Token | string | secret, masked |
| `presshub_social_twitter_access_secret` | Twitter Access Secret | string | secret, masked, **unused** |
| `presshub_social_tiktok_access_token` | TikTok Access Token | string | secret, masked |
| `presshub_social_tiktok_open_id` | TikTok Creator Open ID | string |  |

### Tab `prompts` (16)

| Option | Label | Type | Notes |
|---|---|---|---|
| `presshub_social_prompt_system` | System Prompt (All Platforms) | string |  |
| `presshub_social_prompt_post_images` | AI Post Images: Planning Prompt | string |  |
| `presshub_social_prompt_post_image_style` | AI Post Images: Image Prompt | string |  |
| `presshub_social_prompt_tiktok_shorts_image_style` | TikTok Shorts: Image Prompt | string |  |
| `presshub_social_prompt_tiktok_shorts_image_moods` | TikTok Shorts: Image Styles per Mood | string |  |
| `presshub_social_prompt_tiktok_shorts_tts_style` | TikTok Shorts: Narration Style | string |  |
| `presshub_social_prompt_tiktok_shorts_music` | TikTok Shorts: Music Prompts | string |  |
| `presshub_social_prompt_tiktok_shorts` | TikTok Shorts: Script Prompt | string |  |
| `presshub_social_prompt_twitter` | X/Twitter Caption Prompt | string |  |
| `presshub_social_template_twitter` | X/Twitter Static Fallback Template | string |  |
| `presshub_social_prompt_facebook` | Facebook Caption Prompt | string |  |
| `presshub_social_template_facebook` | Facebook Static Fallback Template | string |  |
| `presshub_social_prompt_instagram` | Instagram Caption Prompt | string |  |
| `presshub_social_template_instagram` | Instagram Static Fallback Template | string |  |
| `presshub_social_prompt_tiktok` | TikTok Caption Prompt | string |  |
| `presshub_social_template_tiktok` | TikTok Static Fallback Template | string |  |

## Scheduled for removal

| Option | Page / tab | Why it is unused |
|---|---|---|
| `presshub_social_github_token` | social / general | Plugin updates use the AI GitHub token (presshub_ai_github_token); get_github_token() in Social storage is never called. |
| `presshub_social_twitter_api_key` | social / credentials | X OAuth1 consumer key: no code reads it. Posting to X uses the bearer token or the worker. |
| `presshub_social_twitter_api_secret` | social / credentials | X OAuth1 consumer secret: no code reads it. |
| `presshub_social_twitter_access_secret` | social / credentials | X OAuth1 access token secret: no code reads it. |
