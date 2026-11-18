# PressHub settings inventory

> Generated from `presshub-ai-editor/includes/settings-registry-data.php` by
> `php presshub-ai-editor/scripts/settings-inventory.php`. Do not edit by hand:
> change the registry and run the script. CI fails when this file is stale.

Every option belongs to one section of PressHub → Settings (`admin.php?page=presshub-ai&section=<slug>`), and that section's Save writes it. The Stored as column gives the option's former page and tab (`ai` options were on the AI settings page, `social` options on Social settings).

## Summary

| Section | Options | Not shown on screen | Secrets |
|---|---|---|---|
| Connections (`connections`) | 48 | 25 | 14 |
| AI models (`ai-models`) | 37 | 0 | 0 |
| Writing & QA (`writing-qa`) | 16 | 0 | 0 |
| Daily Briefing (`daily-briefing`) | 78 | 5 | 0 |
| Editorial knowledge (`editorial-knowledge`) | 14 | 0 | 0 |
| Social publishing (`social-publishing`) | 63 | 4 | 0 |
| Social listening (`social-listening`) | 32 | 0 | 0 |
| Site & system (`site-system`) | 14 | 0 | 0 |
| **Total** | **302** | | |

**Scheduled for removal:** 4 option(s), see the last section. They are still registered and keep their stored values until the clean-up phase (#237).

## Connections (`connections`, 48)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_provider` | Active AI provider | string |  | ai / providers | not shown on screen, was: AI Provider |
| `presshub_ai_api_key` | Legacy API key | string |  | ai / providers | not shown on screen, secret, masked, was: API Key |
| `presshub_ai_remove_api_key` | Remove api key | boolean |  | ai / providers | not shown on screen, removes `presshub_ai_api_key` |
| `presshub_ai_remove_google_cloud_api_key` | Remove google cloud api key | boolean |  | ai / advanced | not shown on screen, removes `presshub_ai_google_cloud_api_key` |
| `presshub_ai_remove_github_token` | Remove github token | boolean |  | ai / advanced | not shown on screen, removes `presshub_ai_github_token` |
| `presshub_ai_model_openai` | OpenAI model (legacy) | string |  | ai / providers | not shown on screen, was: Model (Openai) |
| `presshub_ai_temperature_openai` | OpenAI temperature (legacy) | number | ratio | ai / providers | not shown on screen, was: Temperature (Openai) |
| `presshub_ai_max_tokens_openai` | OpenAI maximum output tokens (legacy) | integer | tokens | ai / providers | not shown on screen, was: Max Tokens (Openai) |
| `presshub_ai_timeout_openai` | OpenAI timeout (s) (legacy) | integer | s | ai / providers | not shown on screen, was: Timeout (Openai) |
| `presshub_ai_model_anthropic` | Anthropic model (legacy) | string |  | ai / providers | not shown on screen, was: Model (Anthropic) |
| `presshub_ai_temperature_anthropic` | Anthropic temperature (legacy) | number | ratio | ai / providers | not shown on screen, was: Temperature (Anthropic) |
| `presshub_ai_max_tokens_anthropic` | Anthropic maximum output tokens (legacy) | integer | tokens | ai / providers | not shown on screen, was: Max Tokens (Anthropic) |
| `presshub_ai_timeout_anthropic` | Anthropic timeout (s) (legacy) | integer | s | ai / providers | not shown on screen, was: Timeout (Anthropic) |
| `presshub_ai_model_gemini` | Gemini model (legacy) | string |  | ai / providers | not shown on screen, was: Model (Gemini) |
| `presshub_ai_temperature_gemini` | Gemini temperature (legacy) | number | ratio | ai / providers | not shown on screen, was: Temperature (Gemini) |
| `presshub_ai_max_tokens_gemini` | Gemini maximum output tokens (legacy) | integer | tokens | ai / providers | not shown on screen, was: Max Tokens (Gemini) |
| `presshub_ai_timeout_gemini` | Gemini timeout (s) (legacy) | integer | s | ai / providers | not shown on screen, was: Timeout (Gemini) |
| `presshub_ai_openai_org` | OpenAI Organization ID (optional) | string |  | ai / providers | not shown on screen |
| `presshub_ai_anthropic_version` | Anthropic API Version | string |  | ai / providers | not shown on screen |
| `presshub_ai_github_token` | GitHub Token (optional) | string |  | ai / advanced | secret, masked |
| `presshub_ai_google_cloud_api_key` | Google Cloud API Key (Imagen/TTS) | string |  | ai / advanced | secret, masked |
| `presshub_ai_gcloud_project_id` | Google Cloud Project ID (Imagen) | string |  | ai / advanced |  |
| `presshub_ai_imagen_region` | Imagen Region | string |  | ai / advanced |  |
| `presshub_ai_briefing_tts_api_key` | Speech Generation API Key (Google AI Studio / Gemini) | string |  | ai / briefing | secret, masked |
| `presshub_ai_remove_briefing_tts_api_key` | Remove briefing tts api key | boolean |  | ai / briefing | not shown on screen, removes `presshub_ai_briefing_tts_api_key` |
| `presshub_ai_press_inbox_host` | Mail server (IMAP host) | string |  | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Incoming Mail Server (IMAP) |
| `presshub_ai_press_inbox_port` | Mail server port | string | port | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Port |
| `presshub_ai_press_inbox_encryption` | Mail server encryption | string |  | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Incoming Mail Server (IMAP) |
| `presshub_ai_press_inbox_verify_ssl` | Verify the mail server certificate | string |  | ai / briefing | checkbox, needs `presshub_ai_press_inbox_enabled` on, was: Incoming Mail Server (IMAP) |
| `presshub_ai_press_inbox_username` | Mailbox Username | string |  | ai / briefing | needs `presshub_ai_press_inbox_enabled` on |
| `presshub_ai_press_inbox_password` | Mailbox Password | string |  | ai / briefing | secret, masked, needs `presshub_ai_press_inbox_enabled` on |
| `presshub_ai_remove_press_inbox_password` | Remove press inbox password | string |  | ai / briefing | not shown on screen, removes `presshub_ai_press_inbox_password` |
| `presshub_ai_press_inbox_timeout` | Mail server timeout (s) | string | s | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Inbox Limits |
| `presshub_social_github_token` | GitHub Personal Access Token (Auto-Updates) | string |  | social / general | not shown on screen, secret, masked, **unused** |
| `presshub_social_worker_mode` | Worker Connection Mode | string |  | social / worker |  |
| `presshub_social_worker_url` | Worker Service Endpoint | string |  | social / worker |  |
| `presshub_social_worker_secret` | Worker Secret Token | string |  | social / worker | secret, masked |
| `presshub_social_worker_timeout` | Worker timeout (s) | integer | s | social / worker | was: Worker Timeout |
| `presshub_social_meta_page_id` | Facebook Page ID | string |  | social / credentials |  |
| `presshub_social_meta_access_token` | Meta Page Access Token | string |  | social / credentials | secret, masked |
| `presshub_social_instagram_account_id` | Instagram Account ID | string |  | social / credentials |  |
| `presshub_social_twitter_bearer_token` | X bearer token | string |  | social / credentials | secret, masked, was: Twitter Bearer Token |
| `presshub_social_twitter_api_key` | X API key (OAuth1) | string |  | social / credentials | not shown on screen, secret, masked, **unused**, was: Twitter API Key |
| `presshub_social_twitter_api_secret` | X API secret (OAuth1) | string |  | social / credentials | not shown on screen, secret, masked, **unused**, was: Twitter API Secret |
| `presshub_social_twitter_access_token` | X access token | string |  | social / credentials | secret, masked, was: Twitter Access Token |
| `presshub_social_twitter_access_secret` | X access secret (OAuth1) | string |  | social / credentials | not shown on screen, secret, masked, **unused**, was: Twitter Access Secret |
| `presshub_social_tiktok_access_token` | TikTok Access Token | string |  | social / credentials | secret, masked |
| `presshub_social_tiktok_open_id` | TikTok Creator Open ID | string |  | social / credentials |  |

## AI models (`ai-models`, 37)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_briefing_tts_engine` | Speech engine | string |  | ai / briefing | was: Voice Synthesis Engine |
| `presshub_ai_briefing_tts_model` | Voice Generation AI Model | string |  | ai / briefing |  |
| `presshub_ai_briefing_tts_timeout` | Speech AI timeout (s) | integer | s | ai / briefing | was: Speech AI Timeout (seconds) |
| `presshub_ai_image_prompt_provider` | Article Analysis AI Provider | string |  | ai / images |  |
| `presshub_ai_image_prompt_model` | Article Analysis Model Override | string |  | ai / images |  |
| `presshub_ai_image_generation_provider` | Image Generation AI Provider | string |  | ai / images |  |
| `presshub_ai_coauthor_provider` | Active Co-Author Provider | string |  | ai / coauthor |  |
| `presshub_ai_coauthor_model` | Co-Author model override | string |  | ai / coauthor | was: Custom Model Override |
| `presshub_ai_coauthor_temperature` | Co-Author temperature | number | ratio | ai / coauthor | was: Sampling Temperature |
| `presshub_ai_coauthor_max_tokens` | Co-Author maximum output tokens | integer | tokens | ai / coauthor | was: Maximum Output Tokens |
| `presshub_ai_coauthor_timeout` | Co-Author timeout (s) | integer | s | ai / coauthor | was: Request Timeout (seconds) |
| `presshub_ai_qa_provider` | Editorial QA provider | string |  | ai / coauthor |  |
| `presshub_ai_qa_model` | Editorial QA model | string |  | ai / coauthor |  |
| `presshub_ai_copilot_provider` | Active Copilot Provider | string |  | ai / copilot |  |
| `presshub_ai_copilot_model` | Copilot model override | string |  | ai / copilot | was: Custom Model Override |
| `presshub_ai_copilot_temperature` | Copilot temperature | number | ratio | ai / copilot | was: Sampling Temperature |
| `presshub_ai_copilot_max_tokens` | Copilot maximum output tokens | integer | tokens | ai / copilot | was: Maximum Output Tokens |
| `presshub_ai_copilot_timeout` | Copilot timeout (s) | integer | s | ai / copilot | was: Request Timeout (seconds) |
| `presshub_ai_briefing_text_provider` | Curator AI Provider | string |  | ai / briefing |  |
| `presshub_ai_briefing_text_model` | Curator Model Override | string |  | ai / briefing |  |
| `presshub_ai_briefing_text_temperature` | Curator temperature | number | ratio | ai / briefing | was: Briefing text temperature |
| `presshub_ai_briefing_text_max_tokens` | Curator Maximum Output Tokens | integer | tokens | ai / briefing |  |
| `presshub_ai_briefing_text_timeout` | Curator timeout (s) | integer | s | ai / briefing | was: Briefing text timeout |
| `presshub_ai_briefing_podcast_provider` | Scriptwriter AI Provider | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_model` | Scriptwriter Model Override | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_temperature` | Scriptwriter temperature | number | ratio | ai / briefing | was: Briefing podcast temperature |
| `presshub_ai_briefing_podcast_max_tokens` | Scriptwriter Maximum Output Tokens | integer | tokens | ai / briefing |  |
| `presshub_ai_briefing_podcast_timeout` | Scriptwriter timeout (s) | integer | s | ai / briefing | was: Briefing podcast timeout |
| `presshub_ai_briefing_podcast_tts_provider` | Speech AI Provider (logosAI) | string |  | ai / briefing |  |
| `presshub_ai_briefing_memory_provider` | Memory Model Provider | string |  | ai / knowledge |  |
| `presshub_ai_briefing_memory_model` | Memory Model Override | string |  | ai / knowledge |  |
| `presshub_social_ai_provider` | AI Provider | string |  | social / ai |  |
| `presshub_social_ai_model` | Model Override | string |  | social / ai |  |
| `presshub_social_ai_temperature` | Temperature | string |  | social / ai |  |
| `presshub_social_ai_max_tokens` | Social copy maximum tokens | integer | tokens | social / ai | was: Max Tokens |
| `presshub_social_ai_timeout` | Social copy timeout (s) | integer | s | social / ai | was: Request Timeout |
| `presshub_social_tiktok_shorts_music_model` | Music Model | string |  | social / shorts | needs `presshub_social_tiktok_shorts_music_enabled` on |

## Writing & QA (`writing-qa`, 16)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_fetch_urls` | Fetch source URLs before prompting | boolean |  | ai / coauthor | checkbox, was: Fetch and extract source URLs server-side before prompting (recommended — models cannot browse web pages). |
| `presshub_ai_image_feature_enabled` | Generate featured images | integer |  | ai / images | checkbox, was: Enable Feature |
| `presshub_ai_image_aspect_ratio` | Default Aspect Ratio | string |  | ai / images |  |
| `presshub_ai_image_analysis_prompt` | Article Analysis Prompt Template | string |  | ai / images |  |
| `presshub_ai_image_metadata_enabled` | AI Image Metadata | integer |  | ai / coauthor | checkbox |
| `presshub_ai_seo_auto_enabled` | Automatic Tags & SEO | integer |  | ai / coauthor | checkbox |
| `presshub_ai_briefing_seo_enhance_enabled` | Daily Briefing SEO Structure | integer |  | ai / coauthor | checkbox |
| `presshub_ai_seo_max_tags` | Tags per post | integer | tags | ai / coauthor | was: Maximum Tags per Post |
| `presshub_ai_seo_review_enabled` | SEO Score in Editorial Review | integer |  | ai / coauthor | checkbox |
| `presshub_ai_coauthor_max_source_chars` | Max Source Material Characters | integer | characters | ai / coauthor |  |
| `presshub_ai_qa_enabled` | Enable QA Review System | integer |  | ai / coauthor | checkbox |
| `presshub_ai_qa_include_briefings` | Include Daily Briefings | integer |  | ai / coauthor | checkbox |
| `presshub_ai_qa_min_score` | Minimum passing QA score | integer | points | ai / coauthor | was: Minimum Passing Score |
| `presshub_ai_qa_notify_editor` | Notify Editor on Failures | integer |  | ai / coauthor | checkbox |
| `presshub_ai_qa_editor_email` | Editor Notification Email | string |  | ai / coauthor |  |
| `presshub_ai_qa_article_prompt` | Article QA Review Prompt Template | string |  | ai / coauthor |  |

## Daily Briefing (`daily-briefing`, 78)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_briefing_sources` | News Source URLs | array |  | ai / briefing |  |
| `presshub_ai_briefing_schedule_enabled` | Enable Scheduled Briefing Hub | integer |  | ai / briefing | checkbox |
| `presshub_ai_briefing_harvest_time` | Morning Harvest Time | string |  | ai / briefing | needs `presshub_ai_briefing_schedule_enabled` on |
| `presshub_ai_briefing_generation_time` | Generation Trigger Time | string |  | ai / briefing | needs `presshub_ai_briefing_schedule_enabled` on |
| `presshub_ai_briefing_noon_schedule_enabled` | Enable Noon Briefing Schedule | integer |  | ai / briefing | checkbox |
| `presshub_ai_briefing_noon_harvest_time` | Noon Harvest Time | string |  | ai / briefing | needs `presshub_ai_briefing_noon_schedule_enabled` on |
| `presshub_ai_briefing_noon_generation_time` | Noon Generation Time | string |  | ai / briefing | needs `presshub_ai_briefing_noon_schedule_enabled` on |
| `presshub_ai_press_inbox_enabled` | Read Press Release Inbox | string |  | ai / briefing | checkbox |
| `presshub_ai_press_inbox_folder` | Folders | string |  | ai / briefing | needs `presshub_ai_press_inbox_enabled` on |
| `presshub_ai_press_inbox_processed_folder` | Move used emails to | string |  | ai / briefing | needs `presshub_ai_press_inbox_enabled` on |
| `presshub_ai_press_inbox_allowed_senders` | Allowed Senders | string |  | ai / briefing | needs `presshub_ai_press_inbox_enabled` on |
| `presshub_ai_press_inbox_max_messages` | Press releases per harvest | string | messages | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Inbox Limits |
| `presshub_ai_press_inbox_max_age_hours` | Oldest press release read (h) | string | h | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Inbox Limits |
| `presshub_ai_press_inbox_max_attachment_mb` | Largest attachment read (MB) | string | MB | ai / briefing | needs `presshub_ai_press_inbox_enabled` on, was: Inbox Limits |
| `presshub_ai_press_inbox_extract_attachments` | Read press release attachments | string |  | ai / briefing | checkbox, needs `presshub_ai_press_inbox_enabled` on, was: Inbox Limits |
| `presshub_ai_harvest_time_budget` | Harvest time budget (s) | integer | s | ai / briefing | was: Harvest Execution Time Budget (seconds) |
| `presshub_ai_curation_max_articles` | Articles sent to the curator | integer | articles | ai / briefing | was: Maximum Articles Sent to Curation LLM |
| `presshub_ai_curation_max_chars_per_article` | Characters per article sent to the curator | integer | characters | ai / briefing | was: Maximum Characters per Article (Curation) |
| `presshub_ai_briefing_text_preset` | Text Instruction Preset | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_preset` | Podcast dialogue preset | string |  | ai / briefing | was: Podcast Dialogue Preset |
| `presshub_ai_briefing_target_duration` | Target Podcast Duration | string |  | ai / briefing |  |
| `presshub_ai_briefing_host_count` | Podcast presenters | integer | presenters | ai / briefing | was: Podcast Presenters Count |
| `presshub_ai_briefing_host_female` | Presenter 1 Name | string |  | ai / briefing |  |
| `presshub_ai_briefing_host_male` | Presenter 2 Name | string |  | ai / briefing |  |
| `presshub_ai_briefing_host_tertiary` | Presenter 3 Name | string |  | ai / briefing |  |
| `presshub_ai_briefing_voice_female` | Presenter 1 Voice (TTS) | string |  | ai / briefing |  |
| `presshub_ai_briefing_voice_male` | Presenter 2 Voice (TTS) | string |  | ai / briefing |  |
| `presshub_ai_briefing_voice_tertiary` | Presenter 3 Voice (TTS) | string |  | ai / briefing |  |
| `presshub_ai_briefing_audio_split_by_topic` | Split Audio Synthesis by Topic | integer |  | ai / briefing | checkbox |
| `presshub_ai_briefing_audio_transition_sfx` | Topic Transition SFX | string |  | ai / briefing |  |
| `presshub_ai_briefing_audio_intro_sfx` | Podcast Intro SFX | string |  | ai / briefing |  |
| `presshub_ai_briefing_audio_outro_sfx` | Podcast Outro SFX | string |  | ai / briefing |  |
| `presshub_ai_briefing_voice_speed` | Podcast speaking rate (×) | number | × | ai / briefing | was: Speaking Rate / Speed |
| `presshub_ai_briefing_voice_pitch` | Podcast voice pitch (semitones) | number | semitones | ai / briefing | was: Voice Pitch Tuning |
| `presshub_ai_briefing_tts_style` | Speaking Delivery Style (logosAI) | string |  | ai / briefing |  |
| `presshub_ai_briefing_tts_custom_style` | Custom Speaking Style Prompt | string |  | ai / briefing |  |
| `presshub_ai_briefing_tts_payload_mode` | Gemini Speech Payload Structure | string |  | ai / briefing |  |
| `presshub_ai_briefing_tts_system_instruction` | Gemini Speech System Instruction | string |  | ai / briefing |  |
| `presshub_ai_briefing_tts_turn_pause_ms` | Turn Pause Duration (ms) | integer | ms | ai / briefing |  |
| `presshub_ai_briefing_tts_topic_pause_ms` | Topic Transition Pause (ms) | integer | ms | ai / briefing |  |
| `presshub_ai_briefing_tts_request_delay_ms` | Inter-Turn Throttle Delay (ms) | integer | ms | ai / briefing |  |
| `presshub_ai_briefing_tts_max_retries` | Speech API Max Retries (429/5xx) | integer | retries | ai / briefing |  |
| `presshub_ai_briefing_tts_retry_backoff_ms` | Speech API Retry Backoff (ms) | integer | ms | ai / briefing |  |
| `presshub_ai_briefing_tts_requests_per_minute` | Speech API Requests per Minute | integer | requests/min | ai / briefing |  |
| `presshub_ai_briefing_tts_max_retry_wait_ms` | Speech API Max Retry Wait (ms) | integer | ms | ai / briefing |  |
| `presshub_ai_briefing_tts_cron_retry_delay_min` | Scheduled Synthesis Retry Delay (min) | integer | min | ai / briefing |  |
| `presshub_ai_briefing_text_category` | Text Briefing Category | integer | category ID | ai / briefing |  |
| `presshub_ai_briefing_podcast_category` | Podcast Category | integer | category ID | ai / briefing |  |
| `presshub_ai_briefing_text_status` | Text Post Status | string |  | ai / briefing |  |
| `presshub_ai_briefing_text_title_prefix` | Text Story Title Prefix | string |  | ai / briefing |  |
| `presshub_ai_briefing_text_title_date_format` | Text Story Title Date Format | string |  | ai / briefing |  |
| `presshub_ai_briefing_noon_text_title_prefix` | Noon Text Story Title Prefix | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_title_prefix` | Morning Podcast Title Prefix | string |  | ai / briefing |  |
| `presshub_ai_briefing_morning_podcast_title_prefix` | Morning podcast title prefix (legacy) | string |  | ai / briefing | not shown on screen, was: Briefing morning podcast title prefix |
| `presshub_ai_briefing_noon_podcast_title_prefix` | Noon Podcast Title Prefix | string |  | ai / briefing |  |
| `presshub_ai_briefing_featured_image_ids_morning` | Morning Featured Image Pool | string |  | ai / briefing |  |
| `presshub_ai_briefing_featured_image_ids_noon` | Noon Featured Image Pool | string |  | ai / briefing |  |
| `presshub_ai_briefing_featured_image_ids` | Featured Image Pool (Attachment IDs) | string |  | ai / briefing | not shown on screen |
| `presshub_ai_briefing_featured_image_source` | Text Briefing Featured Image Source | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_featured_image_source` | Podcast Featured Image Source | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_include_text` | Include transcript in podcast post | integer |  | ai / briefing | checkbox, was: Include Transcript in Podcast Post |
| `presshub_ai_briefing_podcast_status` | Podcast Post Status | string |  | ai / briefing |  |
| `presshub_ai_briefing_text_prompt` | Curator System Prompt | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_1` | Podcast Dialogue System Prompt (1 Host) | string |  | ai / briefing | not shown on screen |
| `presshub_ai_briefing_podcast_prompt_2` | Podcast Dialogue System Prompt (2 Hosts) | string |  | ai / briefing | not shown on screen |
| `presshub_ai_briefing_podcast_prompt_3` | Podcast Dialogue System Prompt (3 Hosts) | string |  | ai / briefing | not shown on screen |
| `presshub_ai_qa_briefing_prompt` | Daily Briefing QA Review Prompt Template | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_style` | Active Dialogue Style | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_1_default_greek_chat` | Briefing podcast prompt 1 default greek chat | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_2_default_greek_chat` | Briefing podcast prompt 2 default greek chat | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_3_default_greek_chat` | Briefing podcast prompt 3 default greek chat | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_1_bbc_broadcasting_standards` | Briefing podcast prompt 1 bbc broadcasting standards | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_2_bbc_broadcasting_standards` | Briefing podcast prompt 2 bbc broadcasting standards | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_3_bbc_broadcasting_standards` | Briefing podcast prompt 3 bbc broadcasting standards | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_1_conversational_news_reporting` | Briefing podcast prompt 1 conversational news reporting | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_2_conversational_news_reporting` | Briefing podcast prompt 2 conversational news reporting | string |  | ai / briefing |  |
| `presshub_ai_briefing_podcast_prompt_3_conversational_news_reporting` | Briefing podcast prompt 3 conversational news reporting | string |  | ai / briefing |  |
| `presshub_ai_podcast_show_notes_enabled` | Podcast show notes | integer |  | ai / briefing | checkbox |

## Editorial knowledge (`editorial-knowledge`, 14)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_memory_enabled` | Enable Editorial Memory | integer |  | ai / knowledge | checkbox |
| `presshub_ai_memory_daily_days` | Recent Memory Window (days) | integer | days | ai / knowledge | needs `presshub_ai_memory_enabled` on |
| `presshub_ai_memory_background_days` | Background Memory Window (days) | integer | days | ai / knowledge | needs `presshub_ai_memory_enabled` on |
| `presshub_ai_memory_briefing_budget_chars` | Briefing Memory Budget (chars) | integer | characters | ai / knowledge | needs `presshub_ai_memory_enabled` on |
| `presshub_ai_memory_podcast_budget_chars` | Podcast Memory Budget (chars) | integer | characters | ai / knowledge | needs `presshub_ai_memory_enabled` on |
| `presshub_ai_policy_fiches_enabled` | Policy fiches in AI calls | integer |  | ai / knowledge | checkbox, needs `presshub_ai_memory_enabled` on |
| `presshub_ai_stakeholders_enabled` | People fiches in AI calls | integer |  | ai / knowledge | checkbox, needs `presshub_ai_memory_enabled` on |
| `presshub_ai_claim_check_enabled` | Claim checks in curation | integer |  | ai / knowledge | checkbox |
| `presshub_ai_pool_cleanup_enabled` | Clean the article pool | integer |  | ai / knowledge | checkbox |
| `presshub_ai_pool_max_age_hours` | Oldest article kept (h) | integer | h | ai / knowledge | needs `presshub_ai_pool_cleanup_enabled` on |
| `presshub_ai_pool_duplicate_similarity` | Duplicate similarity (0.1–1) | number | ratio | ai / knowledge | needs `presshub_ai_pool_cleanup_enabled` on |
| `presshub_ai_policy_fiches_budgets` | Policy fiche context per AI task (characters) | array | characters | ai / knowledge |  |
| `presshub_ai_stakeholders_budgets` | People fiche context per AI task (characters) | array | characters | ai / knowledge |  |
| `presshub_ai_editorial_agenda_budgets` | Editorial agenda context per AI task (characters) | array | characters | ai / knowledge |  |

## Social publishing (`social-publishing`, 63)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_social_auto_publish_enabled` | Auto-Publish on Post | boolean |  | social / general | checkbox |
| `presshub_social_enabled_platforms` | Active Platforms | array |  | social / general | checkbox |
| `presshub_social_enabled_twitter` | Legacy switch: X | boolean |  | social / general | not shown on screen, was: Enabled twitter |
| `presshub_social_enabled_facebook` | Legacy switch: Facebook | boolean |  | social / general | not shown on screen, was: Enabled facebook |
| `presshub_social_enabled_instagram` | Legacy switch: Instagram | boolean |  | social / general | not shown on screen, was: Enabled instagram |
| `presshub_social_enabled_tiktok` | Legacy switch: TikTok | boolean |  | social / general | not shown on screen, was: Enabled tiktok |
| `presshub_social_copy_mode` | Copy Generation Mode | string |  | social / general |  |
| `presshub_social_engine_twitter` | Twitter Engine | string |  | social / general |  |
| `presshub_social_engine_facebook` | Facebook Engine | string |  | social / general |  |
| `presshub_social_engine_instagram` | Instagram Engine | string |  | social / general |  |
| `presshub_social_engine_tiktok` | Tiktok Engine | string |  | social / general |  |
| `presshub_social_max_attempts` | Max Retry Attempts | integer | attempts | social / queue |  |
| `presshub_social_dispatch_interval` | Dispatch interval (s) | integer | s | social / queue | was: Dispatch Interval |
| `presshub_social_batch_size` | Batch Size | integer | posts | social / queue |  |
| `presshub_social_retry_base_delay` | Retry base delay (s) | integer | s | social / queue | was: Retry Base Delay |
| `presshub_social_twitter_max_chars` | X/Twitter Max Characters | integer | characters | social / general |  |
| `presshub_social_instagram_card_width` | Instagram card width (px) | integer | px | social / ai | was: Instagram Card Width |
| `presshub_social_instagram_card_height` | Instagram card height (px) | integer | px | social / ai | was: Instagram Card Height |
| `presshub_social_tiktok_video_width` | TikTok video width (px) | integer | px | social / ai | was: TikTok Video Width |
| `presshub_social_tiktok_video_height` | TikTok video height (px) | integer | px | social / ai | was: TikTok Video Height |
| `presshub_social_tiktok_video_duration` | TikTok video length (s) | integer | s | social / ai | was: TikTok Video Duration |
| `presshub_social_tiktok_narration_enabled` | TikTok Narration (TTS) | boolean |  | social / ai | checkbox |
| `presshub_social_tiktok_narration_outro` | TikTok Narration Outro | string |  | social / ai | needs `presshub_social_tiktok_narration_enabled` on |
| `presshub_social_tiktok_narration_voice` | TikTok Narration Voice | string |  | social / ai | needs `presshub_social_tiktok_narration_enabled` on |
| `presshub_social_post_images_enabled` | Generate Images for Posts | boolean |  | social / ai | checkbox |
| `presshub_social_post_images_twitter` | X / Twitter Images | integer | images | social / ai | needs `presshub_social_post_images_enabled` on |
| `presshub_social_post_images_facebook` | Facebook Images | integer | images | social / ai | needs `presshub_social_post_images_enabled` on |
| `presshub_social_post_images_instagram` | Instagram Image | integer | images | social / ai | needs `presshub_social_post_images_enabled` on |
| `presshub_social_post_images_tiktok` | TikTok Image | integer | images | social / ai | needs `presshub_social_post_images_enabled` on |
| `presshub_social_tiktok_shorts_enabled` | TikTok Shorts | boolean |  | social / shorts | checkbox |
| `presshub_social_instagram_shorts_enabled` | Instagram Reels (Shorts) | boolean |  | social / shorts | checkbox |
| `presshub_social_tiktok_article_shorts_enabled` | Article Shorts | boolean |  | social / shorts | checkbox, needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_count` | Shorts per Podcast | integer | shorts | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_spacing_minutes` | Time between shorts (min) | integer | min | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on, was: Time Between Shorts |
| `presshub_social_tiktok_shorts_max_words` | Short Script Length | integer | words | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_images` | Story Images per Short | integer | images | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_caption_words` | Short Post Text Length | integer | words | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_narrators` | Short Narrators | integer | narrators | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_voice_1` | Short Narrator Voice | string |  | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_voice_2` | Second Narrator Voice | string |  | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on |
| `presshub_social_tiktok_shorts_voice_speed` | Short voice speed (%) | integer | % | social / shorts | needs `presshub_social_tiktok_shorts_enabled` on, was: Short Voice Speed |
| `presshub_social_tiktok_shorts_music_enabled` | Shorts Background Music | boolean |  | social / shorts | checkbox |
| `presshub_social_tiktok_shorts_music_volume` | Music volume (%) | integer | % | social / shorts | needs `presshub_social_tiktok_shorts_music_enabled` on, was: Music Volume |
| `presshub_social_tiktok_shorts_music_tracks` | Music Tracks per Mood | integer | tracks | social / shorts | needs `presshub_social_tiktok_shorts_music_enabled` on |
| `presshub_social_alert_email_enabled` | Enable Failure Email Alerts | boolean |  | social / queue | checkbox |
| `presshub_social_alert_email_address` | Alert Recipient Email | string |  | social / queue |  |
| `presshub_social_prompt_system` | System Prompt (All Platforms) | string |  | social / prompts |  |
| `presshub_social_prompt_post_images` | AI Post Images: Planning Prompt | string |  | social / prompts |  |
| `presshub_social_prompt_post_image_style` | AI Post Images: Image Prompt | string |  | social / prompts |  |
| `presshub_social_prompt_tiktok_shorts_image_style` | TikTok Shorts: Image Prompt | string |  | social / shorts |  |
| `presshub_social_prompt_tiktok_shorts_image_moods` | TikTok Shorts: Image Styles per Mood | string |  | social / shorts |  |
| `presshub_social_prompt_tiktok_shorts_tts_style` | TikTok Shorts: Narration Style | string |  | social / shorts |  |
| `presshub_social_prompt_tiktok_shorts_music` | TikTok Shorts: Music Prompts | string |  | social / shorts |  |
| `presshub_social_prompt_tiktok_article_short` | Article Shorts: Script Prompt | string |  | social / shorts |  |
| `presshub_social_prompt_tiktok_shorts` | TikTok Shorts: Script Prompt | string |  | social / shorts |  |
| `presshub_social_prompt_twitter` | X/Twitter Caption Prompt | string |  | social / prompts |  |
| `presshub_social_template_twitter` | X/Twitter Static Fallback Template | string |  | social / prompts |  |
| `presshub_social_prompt_facebook` | Facebook Caption Prompt | string |  | social / prompts |  |
| `presshub_social_template_facebook` | Facebook Static Fallback Template | string |  | social / prompts |  |
| `presshub_social_prompt_instagram` | Instagram Caption Prompt | string |  | social / prompts |  |
| `presshub_social_template_instagram` | Instagram Static Fallback Template | string |  | social / prompts |  |
| `presshub_social_prompt_tiktok` | TikTok Caption Prompt | string |  | social / prompts |  |
| `presshub_social_template_tiktok` | TikTok Static Fallback Template | string |  | social / prompts |  |

## Social listening (`social-listening`, 32)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_social_monitor_enabled` | Monitor Social Accounts | string |  | ai / social_listening | checkbox |
| `presshub_ai_social_monitor_interval_min` | Social scan interval (min) | string | min | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on, was: Scan Schedule |
| `presshub_ai_social_monitor_max_age_hours` | Oldest social post read (h) | string | h | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on, was: Scan Schedule |
| `presshub_ai_social_monitor_max_posts_per_account` | Social posts per account | string | posts | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on, was: Post Limits |
| `presshub_ai_social_monitor_max_posts_per_harvest` | Social posts per harvest | string | posts | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on, was: Post Limits |
| `presshub_ai_social_monitor_x_list_url` | X List (optional) | string |  | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on |
| `presshub_ai_social_monitor_transcribe_videos` | Transcribe social videos | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_monitor_enabled` on, was: Videos |
| `presshub_ai_social_monitor_max_video_seconds` | Longest video transcribed (s) | string | s | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on, was: Videos |
| `presshub_ai_social_monitor_max_video_mb` | Largest video transcribed (MB) | string | MB | ai / social_listening | needs `presshub_ai_social_monitor_enabled` on, was: Videos |
| `presshub_ai_social_trending_enabled` | Publish trending roundup | string |  | ai / social_listening | checkbox, was: Publish Trending Roundup |
| `presshub_ai_social_trending_interval_hours` | Trending roundup interval (h) | string | h | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Publish Trending Roundup |
| `presshub_ai_social_trending_platform_x` | Trending from X | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_trending_enabled` on, was: Platforms |
| `presshub_ai_social_trending_platform_tiktok` | Trending from TikTok | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_trending_enabled` on, was: Platforms |
| `presshub_ai_social_trending_platform_instagram` | Trending from Instagram | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_trending_enabled` on, was: Platforms |
| `presshub_ai_social_trending_platform_facebook` | Trending from Facebook | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_trending_enabled` on, was: Platforms |
| `presshub_ai_social_trending_keywords` | Search Terms (X, TikTok) | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on |
| `presshub_ai_social_trending_hashtags` | Hashtags (Instagram, TikTok) | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on |
| `presshub_ai_social_trending_facebook_pages` | Facebook Pages | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on |
| `presshub_ai_social_trending_category` | Trending roundup category | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Roundup Post |
| `presshub_ai_social_trending_post_mode` | Trending roundup post mode | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Roundup Post |
| `presshub_ai_social_trending_post_status` | Trending roundup post status | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Roundup Post |
| `presshub_ai_social_trending_title` | Trending roundup title | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Roundup Post |
| `presshub_ai_social_trending_max_items` | Posts in the roundup | string | posts | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Selection |
| `presshub_ai_social_trending_max_per_platform` | Trending posts per platform | string | posts | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Selection |
| `presshub_ai_social_trending_max_age_hours` | Oldest trending post (h) | string | h | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Selection |
| `presshub_ai_social_trending_min_engagement` | Minimum trending engagement | string | engagement points | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Selection |
| `presshub_ai_social_trending_excluded_words` | Excluded Words | string |  | ai / social_listening | needs `presshub_ai_social_trending_enabled` on |
| `presshub_ai_social_trending_summary_enabled` | AI summary at the top of the roundup | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_trending_enabled` on, was: AI summary |
| `presshub_ai_social_trending_briefing_enabled` | Use trending posts in the briefing | string |  | ai / social_listening | checkbox, needs `presshub_ai_social_trending_enabled` on, was: Use in the Briefing |
| `presshub_ai_social_trending_briefing_count` | Trending posts given to the briefing | string | posts | ai / social_listening | needs `presshub_ai_social_trending_enabled` on, was: Use in the Briefing |
| `presshub_ai_social_trending_max_per_account` | Trending posts per account | integer | posts | ai / social_listening | needs `presshub_ai_social_trending_enabled` on |
| `presshub_ai_social_trending_share_after_hour` | Publish trending roundups from (hour) | integer | hour | ai / social_listening | needs `presshub_ai_social_trending_enabled` on |

## Site & system (`site-system`, 14)

| Option | Label | Type | Unit | Stored as | Notes |
|---|---|---|---|---|---|
| `presshub_ai_debug_prompts` | Raw debug dump: prompts | boolean |  | ai / advanced | checkbox, was: Raw debug dump (developer use) |
| `presshub_ai_rate_limit_enabled` | Enable Per-User Rate Limit | boolean |  | ai / advanced | checkbox |
| `presshub_ai_rate_limit_per_hour` | Requests per window | integer | requests | ai / advanced | was: Requests per Window |
| `presshub_ai_rate_limit_window_seconds` | Rate limit window (s) | integer | s | ai / advanced | was: Window Length (seconds) |
| `presshub_ai_research_retention_days` | Research Log Retention (days) | integer | days | ai / advanced |  |
| `presshub_ai_log_level` | Diagnostic Log Level | string |  | ai / advanced |  |
| `presshub_ai_log_tts_payloads` | Raw debug dump: TTS payloads | boolean |  | ai / advanced | checkbox, was: Raw debug dump (developer use) |
| `presshub_ai_generation_record_retention_days` | Generation Record Retention (days) | integer | days | ai / advanced |  |
| `presshub_ai_log_file_retention_days` | Log File Retention (days) | integer | days | ai / advanced |  |
| `presshub_ai_token_log_retention_days` | Token Log Retention (days) | integer | days | ai / advanced |  |
| `presshub_ai_audit_log_retention_days` | Audit Log Retention (days) | integer | days | ai / advanced |  |
| `presshub_ai_social_meta_enabled` | Enable Social Metadata (OpenGraph & Twitter Cards) | boolean |  | ai / advanced | checkbox |
| `presshub_ai_social_meta_all_posts` | Enable for All Published Posts | boolean |  | ai / advanced | checkbox |
| `presshub_ai_memory_retention_days` | Memory Retention Window (days) | integer | days | ai / knowledge | needs `presshub_ai_memory_enabled` on |

## Developer filters

Hooks for developers and hosting. Where a filter has a related setting, the filter adjusts the saved value; the rest stay code-only.

| Filter | Related setting |
|---|---|
| `presshub_ai_audio_script_prompt` | code only |
| `presshub_ai_briefing_cap` | code only |
| `presshub_ai_briefing_claim_check` | `presshub_ai_claim_check_enabled` |
| `presshub_ai_briefing_split_steps` | code only |
| `presshub_ai_briefing_step_max_continuations` | code only |
| `presshub_ai_briefing_step_time_budget_sec` | code only |
| `presshub_ai_briefing_title_max_chars` | code only |
| `presshub_ai_chat_system_prompt` | code only |
| `presshub_ai_classify_intent_prompt` | code only |
| `presshub_ai_client_ip_header` | code only |
| `presshub_ai_composed_chat_system_prompt` | code only |
| `presshub_ai_composed_curator_system_prompt` | code only |
| `presshub_ai_composed_podcast_system_prompt` | code only |
| `presshub_ai_composed_research_system_prompt` | code only |
| `presshub_ai_composed_system_prompt` | code only |
| `presshub_ai_crawl_delay_ms` | code only |
| `presshub_ai_curator_system_prompt` | code only |
| `presshub_ai_debug_prompts` | `presshub_ai_debug_prompts` |
| `presshub_ai_draft_system_prompt` | code only |
| `presshub_ai_draft_user_prompt` | code only |
| `presshub_ai_editorial_agenda_budgets` | `presshub_ai_editorial_agenda_budgets` |
| `presshub_ai_enable_llm_extractor` | code only |
| `presshub_ai_enable_social_meta` | code only |
| `presshub_ai_fetch_source_urls` | `presshub_ai_fetch_urls` |
| `presshub_ai_harvest_max_links_per_source` | code only |
| `presshub_ai_harvest_min_rss_words` | code only |
| `presshub_ai_harvest_min_title_words` | code only |
| `presshub_ai_harvest_min_word_count` | code only |
| `presshub_ai_harvest_time_budget` | `presshub_ai_harvest_time_budget` |
| `presshub_ai_memory_editor_url` | code only |
| `presshub_ai_model_context_window_tokens` | code only |
| `presshub_ai_podcast_host1_name` | code only |
| `presshub_ai_podcast_host2_name` | code only |
| `presshub_ai_podcast_host3_name` | code only |
| `presshub_ai_podcast_producer_system_prompt` | code only |
| `presshub_ai_podcast_show_notes_enabled` | `presshub_ai_podcast_show_notes_enabled` |
| `presshub_ai_podcast_words_per_minute` | code only |
| `presshub_ai_policy_fiches_budgets` | `presshub_ai_policy_fiches_budgets` |
| `presshub_ai_policy_fiches_enabled` | `presshub_ai_policy_fiches_enabled` |
| `presshub_ai_pool_cleanup_enabled` | `presshub_ai_pool_cleanup_enabled` |
| `presshub_ai_pool_duplicate_similarity` | `presshub_ai_pool_duplicate_similarity` |
| `presshub_ai_pool_max_age_hours` | `presshub_ai_pool_max_age_hours` |
| `presshub_ai_pool_min_scraped_words` | code only |
| `presshub_ai_pool_promo_patterns` | code only |
| `presshub_ai_research_system_prompt` | code only |
| `presshub_ai_scorecard_system_prompt` | code only |
| `presshub_ai_scorecard_user_prompt` | code only |
| `presshub_ai_settings_cap` | code only |
| `presshub_ai_social_meta_all_posts` | code only |
| `presshub_ai_stakeholders_budgets` | `presshub_ai_stakeholders_budgets` |
| `presshub_ai_stakeholders_enabled` | `presshub_ai_stakeholders_enabled` |
| `presshub_ai_url_fetcher_headers` | code only |
| `presshub_legacy_admin_redirects` | code only |
| `presshub_settings_registry` | code only |
| `presshub_social_post_image_aspect` | code only |
| `presshub_social_settings_cap` | code only |
| `presshub_social_tiktok_shorts_cancel_superseded` | code only |
| `presshub_social_trending_max_per_account` | `presshub_ai_social_trending_max_per_account` |
| `presshub_social_trending_share_after_hour` | `presshub_ai_social_trending_share_after_hour` |

## Scheduled for removal

| Option | Page / tab | Why it is unused |
|---|---|---|
| `presshub_social_github_token` | social / general | Plugin updates use the AI GitHub token (presshub_ai_github_token); get_github_token() in Social storage is never called. |
| `presshub_social_twitter_api_key` | social / credentials | X OAuth1 consumer key: no code reads it. Posting to X uses the bearer token or the worker. |
| `presshub_social_twitter_api_secret` | social / credentials | X OAuth1 consumer secret: no code reads it. |
| `presshub_social_twitter_access_secret` | social / credentials | X OAuth1 access token secret: no code reads it. |
