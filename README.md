# PressHub AI Co-Pilot

Enterprise AI Newsroom, Editorial Co-Pilot & Multimedia Publishing Pipeline for WordPress.

[![Version](https://img.shields.io/badge/version-2.19.0-blue.svg)](presshub-ai-editor/presshub-ai-editor.php)
[![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4.svg)](https://php.net)
[![PHP Unit Tests](https://img.shields.io/badge/PHP%20Tests-101%20passed%20%7C%20100%25-brightgreen.svg)](presshub-ai-editor/tests/)
[![Social Autopost Tests](https://img.shields.io/badge/Social%20Tests-337%20passed%20%7C%20100%25-brightgreen.svg)](presshub-ai-editor/tests/)
[![Worker Tests](https://img.shields.io/badge/Worker%20Tests-76%20passed%20%7C%20100%25-brightgreen.svg)](presshub-social-worker/tests/)
[![Jest Workflow Tests](https://img.shields.io/badge/Jest%20Tests-81%20passed%20%7C%20100%25-brightgreen.svg)](presshub-workflow/tests/)
[![CI](https://github.com/eeftychiou/presshub/actions/workflows/ci.yml/badge.svg)](https://github.com/eeftychiou/presshub/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-orange.svg)](presshub-ai-editor/presshub-ai-editor.php)

---

## 🌟 Overview

**PressHub AI Co-Pilot** transforms WordPress into an autonomous, AI-driven newsroom and multimedia production suite. Spanning from multi-source web/RSS news gathering to editorial quality scoring, bilingual daily briefings, multi-host podcast script generation, and broadcast-quality text-to-speech synthesis, PressHub AI provides news organizations and content creators with an enterprise-grade automated editorial workflow.

Built with a modular **Provider Store** (supporting OpenAI, Anthropic, Google Gemini, and Google Cloud Vertex AI/TTS), **4-layer enterprise observability**, and strict architectural principles (**Settings-First**, **Zero-Silent-Fallback**, **Clean-Slate**), PressHub AI ensures total transparency, deterministic control, and reliability.

---

## 🚀 Core Subsystems & Features

```
┌────────────────────────────────────────────────────────────────────────┐
│                        PressHub AI Newsroom                            │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │
      ┌────────────────────────────┼────────────────────────────┐
      ▼                            ▼                            ▼
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Daily Briefing  │     │ Podcast Producer │     │  In-Editor AI    │
│     Pipeline     │     │   & TTS Studio   │     │     Co-Pilot     │
├──────────────────┤     ├──────────────────┤     ├──────────────────┤
│ • Morning & Noon │     │ • Multi-Host Dialogue│ │ • Draft Assistant│
│ • RSS & Web Scrape│    │ • Nameless Hosts │     │ • 0-100 Scorecard│
│ • Context Ingest │     │ • Spoken Dates   │     │ • Chat Intents   │
│ • Image Cycling  │     │ • Audio-Only Post│     │ • Imagen Sideload│
│ • Social Meta    │     │ • Script Editor  │     │ • Block Revision │
│ • Stage Triggers │     │ • Audio Re-Gen   │     │ • Revision Logs  │
└────────┬─────────┘     └────────┬─────────┘     └────────┬─────────┘
         │                        │                        │
         └────────────────────────┼────────────────────────┘
                                  ▼
┌────────────────────────────────────────────────────────────────────────┐
│                  Decoupled Provider Store & Engine                     │
│  • OpenAI    • Anthropic Claude    • Google Gemini    • Google Cloud   │
├────────────────────────────────────────────────────────────────────────┤
│                  4-Layer Observability & CLI Diagnostics               │
│  Layer 1: Structured  │ Layer 2: Token Log │ Layer 3: Audit │ L4: Core │
└────────────────────────────────────────────────────────────────────────┘
```

### 0. 🛟 Backup before every update (`PressHub_AI_Update_Backup`, 2.53.0)
Before WordPress updates PressHub, the plugin saves its settings (`presshub_*` options), its database tables (`{prefix}presshub_*`) and the plugin files it is about to replace to `wp-content/presshub-backups/` (deny-all, newest 5 kept, see **Settings → Site & system → Logging & retention**). If the backup fails, the update is stopped. **PressHub → Backups** lists them, takes one by hand and restores one (a backup of the current state is taken first). Restore steps and the manual fallback are in `docs/backups.md`.

### 1. 📰 Automated Daily Briefing Pipeline (Morning Edition & Noon News Update)
- **Autonomous News Harvester (`PressHub_AI_News_Harvester`)**: Concurrently scrapes and processes articles from configured RSS feeds and direct web URLs. Features domain politeness delays, per-domain concurrency limits, total execution time budgets, lead-article extraction, and HTML sanitization. Manages edition-partitioned raw snapshots (`raw-articles.json` vs `raw-articles-noon.json`).
- **Press Release Inbox (`PressHub_AI_Press_Release_Inbox`, `PressHub_AI_Imap_Client`, `PressHub_AI_Mime_Parser`)**: Before each harvest, reads a dedicated mailbox (e.g. `press@presshub.cy`) over IMAP with a pure-PHP client, turns each email into a press-release article (Word attachments read locally, PDF and scanned images read by Gemini), and moves it to a processed folder. The curator attributes press releases to their sender and fences them against prompt injection. The mailbox login (server, user name, password, Test connection) is under PressHub → Settings → Connections → Press inbox; folders, allowed senders and limits are under Daily Briefing → Sources & harvesting. See `architecture.md` §4.26.
- **Social Monitor (`PressHub_AI_Social_Monitor`)**: Reads the latest posts of X and TikTok accounts and Facebook Pages added as News Sources (types *X (Twitter) Account*, *TikTok Account* and *Facebook Page*) through the local social worker, which browses them with a separate reader account (no paid API). Videos are transcribed by Gemini. Posts join the news pool attributed to the account holder and fenced against prompt injection. Configured under PressHub → Settings → Social listening → Account monitoring. See `architecture.md` §4.27.
- **Intelligent News Curator (`PressHub_AI_News_Curator`)**: Evaluates harvested candidate stories using LLM analysis. Employs semantic article deduplication to prevent repetitive coverage, enforces strict token context limits, and produces comprehensive bilingual (Greek and English) daily briefings.
  - **Noon News Update**: Re-scrapes sources at noon, automatically ingests the morning briefing post as reference context, and generates an updated noon briefing focusing on developments since morning.
  - **Tiered Editorial Memory Prompt Injection (`PressHub_AI_Memory_Context_Builder`)**: Ingests multi-day editorial memory into daily briefing and podcast prompts across 3 tiers (Same-day morning digest for noon updates, recent daily digests within lookback window, and background weekly rollups + gap-filling dailies). Enforces Greek journalistic continuity principles, dynamic context estimator budget shrinking, deterministic story-level trimming, and durable generation record manifest linking.
- **Featured Image Pools & Interactive Media Library Picker**: Configure dedicated pools of Media Library images for Morning briefings (`presshub_ai_briefing_featured_image_ids_morning`) and Noon updates (`presshub_ai_briefing_featured_image_ids_noon`), with automatic fallback. Features an interactive WordPress Media Library picker UI with multi-select modal, thumbnail preview cards, badge IDs, and individual remove/clear actions. Images are cycled at random and assigned as post thumbnails to daily text briefings and podcasts, with intelligent **same-day collision avoidance** preventing duplicate featured images between morning/noon text briefings and podcasts when alternative images exist in the pool.
- **Social Sharing & OpenGraph Optimization (`PressHub_AI_Social_Meta`)**: Emits rich, opengraph.xyz-compliant metadata (`og:title`, `og:description`, `og:image`, `og:site_name`, `og:type=article`, `twitter:card=summary_large_image`, `twitter:title`, `twitter:description`, `twitter:image`, and standard `<meta name="description">`). Supports daily briefings, podcasts, and AI Co-Authored articles with multi-tier image fallback (post thumbnail → inline content image → edition pool → site icon). Includes an automated title length optimizer guaranteeing document titles remain ≤ 60 characters for optimal search snippet and social card display, fully configurable via Settings-First options (`presshub_ai_social_meta_enabled`, `presshub_ai_social_meta_all_posts`).
- **Interactive Briefing Admin (`PressHub_AI_Briefing_Admin`)**: A dedicated WordPress administrative control center providing visibility into each pipeline stage (*Harvest → Curate → Audio/Podcast → Publish*), stage event timelines, manual triggers, single-stage regeneration, and full timezone-aware scheduling. Includes interactive tabs to toggle between **Morning Edition** and **Noon Update**, dynamic in-browser audio player mounting on synthesis completion, and role-gating strictly for Editors and above (`edit_others_posts`, filterable via `presshub_ai_briefing_cap`).

### 2. 🎙️ Podcast Producer, Script Editor & Audio Regeneration
- **Multi-Host Script Engine (`PressHub_AI_Podcast_Producer`)**: Automatically transforms curated news briefings into dynamic, conversational podcast scripts between multiple hosts:
  - **Nameless Speaker Invariant**: Presenters never awkwardly refer to each other or themselves by name; dialogue progresses naturally through ideas.
  - **Spoken Date Formatting**: All dates are converted to natural spoken-language phrasing (e.g. *"3rd of May 2026"* or *"3 Μαΐου 2026"*).
- **Configurable Title Prefixes**: Separate editorial title prefix options for Morning Podcast (`presshub_ai_briefing_podcast_title_prefix`, default *"Πρωινό Podcast Ενημέρωσης - "*) and Noon Podcast (`presshub_ai_briefing_noon_podcast_title_prefix`, default *"Μεσημβρινό Podcast Ενημέρωσης - "*), configurable from Settings.
- **Clean Audio Post Content & Front-Page Excerpt Suppression**: By default, podcast post content displays exclusively the audio player block (`wp:audio`), and raw dialogue text is suppressed from `post_excerpt` and filtered out of front-page post previews, loop cards, and public RSS feeds (configurable via `presshub_ai_briefing_podcast_include_text`). Social meta tags emit a clean editorial audio description instead of leaking dialogue turns.
- **TikTok Narration for Text-Only Articles (`PressHub_Social_TikTok_Narrator`)**: Articles without podcast audio (e.g. the Daily Brief) are posted to TikTok as a spoken video. The TikTok copy is read aloud with Gemini TTS (hashtags, emojis, links and the copy's own presshub.cy sentence are skipped) and ends with *"Για το πλήρες άρθρο, επισκεφθείτε το presshub.cy"*. Configurable under **PressHub → Settings → Social publishing → Images & video** (`presshub_social_tiktok_narration_enabled`, `_outro`, `_voice`); a TTS failure fails the job instead of posting silently.
- **TikTok Shorts for Podcasts (`PressHub_Social_TikTok_Shorts`)**: Optionally splits Daily Briefing podcast posts into multiple 30–60s hook-first TikTok videos (1–5 shorts, spaced by `presshub_social_tiktok_shorts_spacing_minutes`, off by default via `presshub_social_tiktok_shorts_enabled`). Each short is planned in the background via WP-Cron, read by a single energetic narrator by default (`presshub_social_tiktok_shorts_narrators` 1–2, with their own Gemini TTS voices, speed and "Narration Style" instruction), and rendered natively for the feed with word-synced animated karaoke captions (libass), 9:16 vertical AI story images (`presshub_social_tiktok_shorts_images`, default 3) with dynamic zoom/pan motion, branded progress bar, safe-zone enforcement, and optional mood-matched background music (the plan picks a mood per story; a small library of instrumental tracks per mood is generated once with Google Lyria from the settings page and ducked under the voice, off by default via `presshub_social_tiktok_shorts_music_enabled`).
- **YouTube Shorts and podcast episodes (`PressHub_Social_YouTube`, 2.51.0)**: Posts the generated shorts as YouTube Shorts and each Daily Briefing podcast as a 16:9 episode in a YouTube podcast playlist, through the free YouTube Data API v3 (up to 100 uploads a day). Connect the channel under **PressHub → Settings → Connections → YouTube** (Google OAuth client ID and secret, then *Connect YouTube*); turn it on under **Social publishing → Where to post** and **Shorts → YouTube**. The social worker renders and uploads the videos with a short-lived token from WordPress. See `architecture.md` §4.16.
- **Facebook Reels through the Graph API (2.78.0, worker 1.9.0)**: With Facebook on the **API** engine and the Meta Page ID and access token set, Facebook Reels no longer need the Facebook browser session. The link post still goes through the API; each Reel is still rendered by the worker, which asks WordPress for a Page token (`POST /worker/facebook/token`) and uploads the MP4 to the Page's Reels endpoint (start, upload to rupload.facebook.com, publish with the short's caption), then checks Facebook's processing status. The token needs `pages_manage_posts` (with `pages_read_engagement` and `pages_show_list`). On the automation engine Reels are posted with the browser session as before. A worker tells WordPress it can do this (`capabilities: ['facebook_reels_api']` in the claim), so an older worker never gets an API Reel.
- **Facebook Reels (2.75.0, worker 1.7.0)**: With **Facebook Reels (Shorts)** on (`presshub_social_facebook_shorts_enabled`, Social publishing → Shorts & Reels, default off), every short (podcast stories, and articles when Article Shorts are on) is also posted to the Facebook Page as a Reel, with the short's own caption. The usual Facebook link post is still made; the first Reel follows it by the time between shorts. The worker renders the short and posts the MP4 through its Facebook browser session, waiting (up to 5 minutes) for the upload before clicking Post. A failed Facebook short fails instead of falling back, since the link post already went out. Needs Facebook on the automation engine in Pull Mode.
- **Two-minute daily brief video (2.82.0)**: **Podcast Shorts Format** (`presshub_social_tiktok_shorts_format`, Social publishing → Shorts & Reels, default *Daily brief*) makes each Daily Briefing podcast ONE vertical video of about two minutes covering the day's 3 to 4 main stories (6 to 8 before 2.85.0), instead of Shorts per Podcast one-story videos. It goes to every shorts platform that is on (TikTok, Instagram Reels, YouTube Shorts, Facebook Reels on the automation engine) and gets no photo slides. The script is written by **Daily Brief: Script Prompt** (asset `social_tiktok_brief.txt`) as an intro, one part per story with its own image description, and an outro, capped by **Daily Brief Script Length** (default 300 words, at most 380 to stay under YouTube's three-minute limit for Shorts). Each story's image is shown while that story is read. The Graph API takes Facebook Reels of up to 90 seconds, so on the Facebook API engine the brief gets no Reel (a WARNING). *One short per story* keeps the previous behaviour. Articles are not affected.
- **One shorts pipeline for every platform (2.83.0, worker 1.11.0)**: TikTok, Instagram Reels, YouTube Shorts and Facebook Reels now share one story schedule: each story goes out on every platform within a few minutes (TikTok first, then Instagram, YouTube and Facebook, 5 minutes apart), and the worker renders each story's video once and posts that same file everywhere instead of rendering it per platform. Every short ends with "Ολόκληρη η ενημέρωση στο presshub.cy".
- **X video posts (2.86.0, worker 1.12.0)**: With **X Video Posts** on (`presshub_social_twitter_shorts_enabled`, Social publishing → Shorts & Reels → Where, default off), X gets the shorts the worker already renders for the other platforms, as native video. An article's X post carries the article short instead of its image, with the same X text and link (with Article Shorts on; if the short cannot be made it goes out as the usual image post). A podcast keeps its X link post and gets its daily brief as one extra video post after it (no per-story shorts on X, so no extra tweets per story). X plays videos of up to 2 minutes 20 seconds on a standard account, so a longer brief is cancelled with the reason (filter `presshub_social_twitter_max_video_seconds` for X Premium). The worker posts the MP4 with the X browser session and waits (up to 5 minutes) for X to upload it before posting. Every X video passes the social post gate. Needs X on the automation engine in Pull Mode; only a worker that announces `twitter_video` gets X videos.
- **TikTok story posts and full podcast (2.85.0)**: with the daily brief, TikTok gets three kinds of post from each podcast: the full podcast (about 10 minutes, all the day's news), the daily brief video (about 2 minutes, the main stories) and TikTok Story Posts (default 3): instant news of 30 to 60 seconds, one story each, each a video short or photo slides (TikTok Story Post Format, default random). The story posts are planned apart from the brief and go through the story ledger like every short. All three settings are in Settings → Social publishing → Shorts & Reels.
- **Social post gate (2.84.0)**: every social post, on every platform (and any added later), passes one gate before anything is spent on it and again before it goes out. Its first check is the story ledger: a story a platform already posted inside Story Window (default 36 hours) goes out there again only with a new development, at most Most Posts per Story per Platform times (default 2); both are in Settings → Social publishing → Platforms → Social post gate. Articles are tagged with their story by one small AI call (Social copy model) only when something was posted in the window, before any copy or images are made; shorts and the daily brief video see the stories already posted and the editorial memory's stories in their plan, a podcast whose edition has nothing that may go out again gets no plan call, and repeats are left out before any script, voice or render. The worker claim and API dispatch check again with no AI call. A stopped post stays in the Social Queue as cancelled with the reason; a manual repost skips the gate. The written daily brief gets no video posts (its podcast tells the same stories), and photo slides only go to TikTok when TikTok gets no video of the post.
- **Social posts matrix**: what each kind of post becomes on each platform, the settings behind each format, and the gate are in [docs/social-posts-matrix.md](docs/social-posts-matrix.md) and on PressHub → Help (with the site's live settings).
- **TikTok photo slides (2.81.0, worker 1.10.0, #333)**: With **TikTok Photo Slides** on (`presshub_social_tiktok_slides_enabled`, Social publishing → Shorts & Reels, default on), every short (podcast stories, and articles with Article Shorts on) is also posted to TikTok as a photo post, halfway between two shorts. The slides show the hook and the short's script as text over the story images (no voice): a title slide, the script cut into slides of about **Words per Photo Slide** words (default 30) up to **Most Photo Slides per Post** (default 10), and a closing slide. The sound comes from TikTok's own library (a photo post cannot carry our audio): the worker searches TikTok's sound picker with the words for the story's mood (**TikTok Photo Slides: Sound Searches**, `mood: search words`) and uses the first result; a business account is only offered sounds cleared for business use. When that fails the post keeps the sound TikTok adds and WordPress logs a WARNING. Slide jobs are handed only to a worker that announces `tiktok_photo_slides` (1.10.0+). Video shorts are unchanged.
- **Fewer speech requests per episode, one audio job per edition (2.74.0)**: A temporary setting for a low daily Gemini speech limit, **Settings → Daily Briefing → Voices → Speech requests per episode** (`presshub_ai_briefing_tts_request_mode`): *One request per turn* (default, as before, about 40 requests for an episode), *Join back-to-back lines by the same voice*, or *Two voices per request* (a few turns of both hosts in one request, with the host names kept in the text as `Name: line` and matched to `multiSpeakerVoiceConfig`, so Gemini picks each line's voice; about 8 to 10 requests for an episode). It applies to the Greek podcast and its English and Turkish versions. Requests hold at most `presshub_ai_briefing_tts_request_max_chars` characters (default 1500). A joined request that fails, or comes back much shorter than its text, is voiced again turn by turn (not after a daily quota error). Captions keep every turn; inside a joined request the turn times are shared out by text length. Also, only one audio job voices an edition at a time (a lock per date and edition, renewed after every request and taken over after 10 minutes without one): on 19/11 two jobs started together and sent the same turns twice.
- **Unconfirmed claims reach social posts and Shorts (2.73.0)**: Social copy and the Shorts plan now get, besides a policy fiche's framing, up to 3 of its claims marked unchecked, contested or false (with the counter-point), under a header that says not to state them as fact, even when the source text does. Before, they got the framing only, so a Shorts script could repeat a claim the fiche marks as unconfirmed.
- **No double counting (2.72.0)**: Each podcast speech request is one row in Usage & logs (it was logged twice: once under the model and once under the voice name). Memory digests, weekly rollups and people extraction each leave one generation record instead of two (a duplicate "Other LLM call" is gone); their prompts are in that record and no longer also in prompts.log. Adding the "Διαβάστε επίσης" list to a briefing no longer builds the same edition's memory digest a second time.
- **Fact checks in the editorial memory (2.80.0)**: When a published briefing reports that a fact-checker (for example FactCheck Cyprus) or an authority found a claim, photo, video or slogan false, altered or misleading, the daily memory digest keeps it as a fact check (claim, verdict, who checked it, what they found, 2 to 5 match phrases, link). For `presshub_ai_fact_check_days` days (default 30) every AI task whose material repeats one of those phrases gets it in its instructions: the briefing, podcast, co-author, co-pilot, QA gate (lowers the score when the text repeats it as true), social copy, Shorts and the Trending in Cyprus summary, which also says which trending post repeats it; the roundup then shows a "Έλεγχος γεγονότων" note under that post, written from the stored check. Memory lists them under "Fact checks in memory" with a Remove button. Switch and limits in Settings → Editorial knowledge (`presshub_ai_fact_checks_enabled`, `presshub_ai_fact_checks_budgets`). Before this, the trending summary got no editorial knowledge at all and was told to use only the posts, so a debunked "Free Cyprus" graffiti photo topped the 21/11 roundup without a word.
- **Every cut recorded (2.67.0)**: When an AI call's material is cut to fit a limit (article text for social copy, Shorts, post images, SEO and image analysis, the co-pilot's article, fetched source pages, the number of source URLs, trending post texts in the trending summary, the known people list of the people extraction), its generation record lists it under "Left out because of limits" → Material, with the limit and the full length, and the log notes it. A cut is recorded only on the call that sends the text (every platform, every retry), never on another call. Trending, the press-release inbox and the social monitor log what their limits leave out. Settings → Editorial knowledge shows, per task, which published policy fiches fit their limit.
- **Lost podcast steps queued again (2.65.0)**: The podcast script and audio run as their own background steps after the briefing. When WordPress loses such a step from its list of scheduled events before it runs (on 19/11/2026 the noon script step vanished this way, so no noon podcast was made), PressHub now notices within a few minutes and queues it again, up to twice, with a warning in the log. If it is lost a third time the log says so as an error, and the step can be run from the Daily Briefing Hub. Scheduling a step also reads the current list first, so a long-running briefing no longer writes back an old copy. Synthesize Audio on the Daily Briefing Hub now updates the edition's existing podcast post instead of publishing a second one and sending it to every platform again.
- **Post image plans recorded and retried, complete action filter (2.64.0)**: The AI call that describes the AI images of a social post now works like the shorts script call: it shows in Generation records as "Post image plan (social)", a misplaced bracket is repaired, an unusable or empty answer is asked for once more with the reason, and an unusable answer is marked as an error on its record and Usage & logs row. The Usage & logs action filter now lists every action found in the log (social copy per platform, trending summary, image calls, and any new ones), with readable names.
- **Lead story text on podcast posts (2.63.0)**: The morning and noon podcast posts open with a short paragraph about the day's top story, taken from the lead of that edition's **published** written brief (the text before its first heading, whole sentences up to 60 words). The home page card shows the same text instead of an empty "Read more"; the post's own excerpt stays the SEO description. Older podcast posts show it on the card too, read from their brief (cached). Turn it off under **PressHub → Settings → Daily Briefing → Podcast lead story text** (`presshub_ai_podcast_lead_enabled`, on by default); developers can also use the `presshub_ai_podcast_lead_enabled` and `presshub_ai_podcast_lead_text` filters.
- **One table for automatic image templates (2.66.0)**: Settings → Writing & QA → Featured images → Automatic images lists the five automatic post types (morning and noon briefing, morning and noon podcast, Trending in Cyprus) with a prompt template picker for each and the image source each uses now. Noon editions get their own templates, and the Trending in Cyprus roundup can now get an AI image (Social listening → Trending → Roundup featured image).
- **Podcast episodes as MP3 (2.68.0)**: Gemini voices come back as WAV, so each morning and noon episode used to be a WAV file of about 32 MB for 11 minutes, which is slow to start on a phone. The social worker on your PC (Pull Mode) now converts each new episode to a mono MP3 with its bundled ffmpeg (64 kbps by default: about 5 MB), and the post, its social jobs and its YouTube episode use the MP3; the WAV attachment is deleted a day later. Episodes made while the worker is off are converted when it comes back, and a failed conversion is tried three times before the episode is left as WAV. Settings under **PressHub → Settings → Daily Briefing → Voices & audio**: **Podcast MP3 files** (`presshub_ai_podcast_mp3_enabled`, on by default) and **Podcast MP3 bitrate** (`presshub_ai_podcast_mp3_bitrate`, 48/64/96/128 kbps). Needs worker 1.6.0; `PRESSHUB_MP3_DISABLED=true` turns it off on a worker.
- **Harvest fixes for two sources (2.59.1)**: Article links with characters such as “–”, “€” or “ϊ” (tothemaonline.com) are now fetched instead of failing as "Invalid URL", and pages whose only title is the site name (socialistikiekfrasi.com) take the headline shown in large type, so their articles are no longer thrown away as shallow pages.
- **Short scripts recorded and retried (2.59.0)**: The AI call that writes the TikTok and YouTube short scripts shows in Generation records as "Shorts script (TikTok/YouTube)" (the voice-over records are now "Shorts voice (TikTok/YouTube)") and in Usage & logs under the action filter of the same name. When its answer cannot be used, both are marked as errors with the reason, and the job's error names the records. A misplaced bracket in the AI's JSON is repaired (and noted on the record), a cut-off answer is never patched into fewer shorts, and an unusable or empty answer is asked for once more, with the reason, before the short fails.
- **Article and memory cuts recorded (2.58.0)**: When the briefing curation shortens articles to the per-article limit or leaves articles out because of the maximum article count, the generation record says how many, by how much and which (Overview → "Left out because of limits", and the Articles tab with each article's full and sent length), and the log gets one line. Memory entries left out to fit the memory budget are summarised in the same section.
- **Enhanced YouTube episodes (2.54.0, worker 1.4.0)**: Each podcast episode on YouTube shows the story images of that briefing's shorts, one topic at a time, with captions styled like the shorts, a title card per topic, and a chapter list in the description. The timing comes from a caption timeline saved when the podcast audio is made (`_presshub_podcast_timeline`). An episode waits for the shorts' images (Wait for Story Images, default 90 minutes), and the feature has its own switch (Episodes with Story Images and Captions, on by default). An episode over 15 minutes waits until YouTube lets the channel upload long videos, which needs channel verification.
- **English and Turkish podcast episodes (`PressHub_AI_Podcast_Translations`, 2.55.0, worker 1.5.0)**: After each edition's Greek podcast is voiced, the languages turned on under **Settings → Daily Briefing → Other languages** (`presshub_ai_briefing_podcast_languages`, default none) are made one after the other in their own cron steps: the Greek script is translated in one call (prompt `assets/prompts/podcast/translate_script.txt`, translator's notes per language in `presshub_ai_briefing_podcast_language_notes`), then voiced with the podcast's voices and a delivery style in that language (`presshub_ai_briefing_podcast_language_voices` overrides them). Nothing is published on the site: once the Greek post is published, each voiced language goes to YouTube as its own episode, with its own title, description, captions and playlist (`presshub_social_youtube_podcast_language_playlists`, under **Shorts → YouTube**), and the images of the Greek episode's matching topics.
- **Podcast Dialogue Editor Metabox & One-Click Audio Regeneration**: Editors can review and refine spoken scripts in the **"Podcast Audio & Dialogue Editor"** metabox on podcast edit screens, and click **"Regenerate Audio from Script"** to re-synthesize and stitch the episode in real time.
- **Resilient Audio Synthesis & Cron Error Isolation**: Synthesizer accepts nullable custom scripts and automatically shifts parameters (e.g. `synthesize_podcast($date, 'noon')`), while briefing crons encapsulate generation and synthesis in try/catch blocks with structured logging to eliminate unhandled runtime fatal errors.
- **Broadcast Audio Synthesizer & Gemini 3.8 TTS REST Architecture (`PressHub_AI_Audio_Synthesizer` & `PressHub_AI_API_Client`)**:
  - **Zero Narrated Instructions**: Passes clean verbatim dialogue turns in `contents[0].parts[].text` without prepended prompt directives, eliminating prompt narration on modern models (`gemini-3.8-flash-tts` / `gemini-3.8-flash-lite-tts`).
  - **Official Gemini 3.8 TTS REST Standard (`structured` — Recommended)**: Conforms strictly to Google's official Gemini 3.8 TTS REST specification. Turn-level `speech_metadata.style` carries delivery emotion, speaking rate (`0.85x - 1.25x`), and voice pitch tuning (`-5.0 to +5.0 semitones`) without top-level system instructions. Inactive settings (e.g. system instructions) dynamically grey out in the UI with informative badges.
  - **Hybrid / System Instruction Override Mode (`hybrid_instruction`)**: Passes both turn-level `speech_metadata.style` and top-level `systemInstruction` with resolved speaker persona and rate placeholders.
  - **Unified Turn-by-Turn Synthesis & Voice Identity Isolation**: Synthesizes turns individually via `synthesize_turn()` with dedicated single-speaker `voiceConfig` across 1, 2, and 3 hosts, completely eliminating voice identity bleeding and multiSpeaker drift.
  - **Resumable Turn Disk Cache**: Deterministically caches each synthesized WAV/MP3 chunk (`tts-cache/{date}/turn_{hash}`), allowing failed or interrupted runs to resume instantly without re-calling the API for completed turns.
  - **Settings-First Pauses & Throttling**: Configurable turn pauses (`presshub_ai_briefing_tts_turn_pause_ms`, default 250ms), topic pauses (`presshub_ai_briefing_tts_topic_pause_ms`, default 600ms), and inter-turn request delays (`presshub_ai_briefing_tts_request_delay_ms`, default 250ms) with intro/transition/outro SFX stitching.
  - **Rate Limiting, Retries, Watchdog & Dialogue Colon Protection**: Paces Gemini TTS to a per-minute budget (`presshub_ai_briefing_tts_requests_per_minute`, default 9), waits the server's retry delay on HTTP 429 (capped), retries 5xx with exponential backoff (`retry_backoff_ms * 2^attempt`), never retries a per-day quota, guards against killed cron processes with an advance watchdog (`presshub_ai_arm_synthesis_watchdog`, minimum 20m), and retries a failed scheduled synthesis once after configured delay. Tightened regex prevents mid-sentence colons in Greek dialogue from being misidentified as speaker turns.
  - **Zero-Silent-Fallback Enforcement**: Strict validation across all TTS entry points; functions default to empty voice strings and throw explicit `missing_tts_voice` or `missing_tts_model` `WP_Error`s, completely removing silent code defaults.
  - **RIFF WAV Header Handling**: Detects RIFF headers on incoming audio bytes from Gemini 3.8 to prevent double-wrapping WAV headers.
- **Gemini Speech API JSON Inspector & Live Voice Testing Studio**:
  - Live preview container in Daily Briefing settings renders the exact JSON request body sent to Gemini's speech endpoint, updating dynamically as voices, speed, pitch, delivery style, or test scripts change.
  - Interactive testing studio allows editors to audition 1-host and 2-host audio synthesis with immediate in-browser audio playback, execution metrics (duration ms, byte size), and download capabilities before publishing. Accessible directly from the Daily Briefing Hub Audio Synthesis Stage Card.

### 3. ✍️ In-Editor Editorial Co-Pilot
- **AI Draft Generation & Large-Context Ingestion**: Accelerates content creation by synthesizing comprehensive drafts from raw source URLs, interview transcripts, or bullet notes. Supported by a configurable Settings-First source character cap (`presshub_ai_coauthor_max_source_chars`, default: 200,000 characters, up to 1,000,000 characters), enabling long investigative dossiers, books, or multi-document transcripts to be processed without artificial limits.
- **4-Key Editorial Scorecard & Legal Exposure Assessment**: Evaluates drafts using a structured 4-key rubric: overall publication readiness score (0–100), legal exposure rating badge (`Low`, `Medium`, or `High`), 2–3 sentence editorial feedback, and targeted legal concern alerts highlighting potential defamation or unverified claims.
- **QA publish gate (2.47.0)**: before publication one AI review checks a post three ways, each with its own threshold: editorial quality (fails hold the post), legal risk (defamation, unattributed allegations, privacy, minors and victims, hate speech, copied material; each issue quoted with a fix; alert, hold or off) and SEO (measured checks plus the AI's score; alert or hold). The editor gets one email per version naming each failed check, and every review is saved on **PressHub → QA reviews**. Since 2.48.0 every publish path is checked: author and editor posts, scheduled posts, the Daily Briefing text story and podcast, and the Trending roundup (legal and SEO only). Automated posts are reviewed after their tags, excerpt and image are added; a missing excerpt or tags is filled from the review's suggestions. Editors can release a held post with **Publish anyway** and a reason, which is recorded on the review and in the audit log. Since 2.50.0 held posts are listed on **PressHub → Held by QA** (with a notice on the dashboard and Posts list), the Posts list has a QA column with one badge per check, and the editor shows the three verdicts, the review history and **Publish anyway**.
- **Block-Level Gutenberg Editing**: Modifies individual Gutenberg block attributes (`attributes.content` / `attributes.text`) without resetting document state, supporting existing article revisions.

### 4. 🖼️ AI Featured Image Generation & Studio Engine
- **Photojournalistic Editorial Realism**: Analyzes article content and generates visual prompts adhering to 35mm documentary photography aesthetics with natural lighting and realistic textures, strictly barring "AI plastic" looks, surreal saturation, or typography/text in the synthesized image.
- **Configurable Provider & Models**: Powered by Google Gemini (`gemini-3.1-flash-image` via multimodal `generateContent`) or OpenAI (`dall-e-3` via `images/generations`) with configurable aspect ratios (`16:9`, `1:1`, `4:3`). The image model is determined directly by the selected AI provider's configured model in the Provider Store.
- **Featured image settings**: Control prompt analysis templates and aspect ratios under **PressHub → Settings → Writing & QA → Featured images** (`admin.php?page=presshub-ai&section=writing-qa#featured-images`); the image prompt and image generation providers are rows of **PressHub → Settings → AI models**.
- **Featured Image Studio prompt templates (2.57.0)**: The templates editors pick in the studio (built-ins: Editorial photo, illustration, cartoon, still life, landscape/aerial; plus saved ones in `presshub_ai_image_prompt_templates`) are listed under **Settings → Writing & QA → Featured images → Prompt templates**: add, edit, copy and delete saved templates there, view the built-ins, and edit Editorial photo in the Article Analysis Prompt Template field above it. Changes save at once (AJAX `presshub_ai_save_image_prompt_template`, `presshub_ai_update_image_prompt_template`, `presshub_ai_delete_image_prompt_template`).
- **Daily Briefing Hub Selection & Resilient Pool Fallback**: Briefings and podcast production can independently select between the Featured Image Pool or Automatic AI Generation, with automatic, graceful fallback to the image pool if synthesis fails or times out. The studio template for each automatic post is picked in one table, **Settings → Writing & QA → Featured images → Automatic images**: Morning briefing, Noon briefing, Morning podcast, Noon podcast and Trending in Cyprus (`presshub_ai_briefing_image_template`, `presshub_ai_briefing_noon_image_template`, `presshub_ai_briefing_podcast_image_template`, `presshub_ai_briefing_noon_podcast_image_template`, `presshub_ai_social_trending_image_template`; default Editorial photo, and Editorial cartoon for Trending in Cyprus so a picture drawn from social posts is not taken for a news photo). The table also shows each post's current image source, linking to where that is set; Daily Briefing → Publishing shows the templates read-only. The Trending in Cyprus roundup keeps the top post's picture unless **Social listening → Trending → Roundup featured image** is set to an AI image (`presshub_ai_social_trending_image_source`), drawn in a background job so the worker's request is not held up; the roundup is held as pending and released with its social posts once the image is in place, or with the top post's picture if drawing fails or takes over 15 minutes. A template in use cannot be deleted, and a missing one falls back to Editorial photo with a warning in the log.
- **Post Editor "AI Featured Image Studio"**: Embedded inside the post editor metabox, allowing journalists to:
  - Draft photojournalistic visual prompts from current article content with one click.
  - Review and edit the prompt in an editable textarea (persisted in post meta `_presshub_ai_image_prompt`).
  - Synthesize and regenerate images directly into the WordPress Media Library with live preview.
  - Assign generated images as the article's official featured image with one click.
- **SEO, Auto-Tagging & Media Metadata (`PressHub_AI_SEO`)**: Generates relevant no-people editorial image prompts (using podcast dialogue scripts when needed), populates descriptive Media Library titles/alt text/captions/descriptions/filenames, assigns tags/excerpts/focus keyphrases automatically on briefings/podcasts and co-author drafts, and adds an advisory SEO score & checklist to the AI Editorial Review.

### 5. 🎛️ Instruction Presets & Prompt Studio
### 6. 🧠 Editorial Memory Stream & Story Continuity (Issue #132, #134)
- **Editorial Continuity**: Multi-day storyline tracking across daily briefings and weekly digests.
- **Daily Memory Digest Builder**: Automatically scheduled in the background (`wp_schedule_single_event`, 30s delay) after briefing text post creation or update (`PressHub_AI_Memory_Digest_Builder`). Extracts structured JSON stories (`assets/prompts/memory/daily_digest.txt`), enforces story continuity by feeding the previous 7 days' story keys/titles, and retries once on schema/validation errors with the error message in Greek.
- **Deterministic Podcast Lines**: `PressHub_AI_Podcast_Producer::save_script()` automatically parses speaker turns and updates `podcast.opening_line` and `podcast.closing_line` in the daily digest deterministically without any extra LLM call.
- **Backfill Workflow**: Batch extracts daily memory digests across existing briefing posts over a configurable date range (via Briefing Hub UI, background single-step cron, or `wp presshub memory backfill [--from=...] [--to=...] [--force]`). Idempotent and resumable, strictly preserving human editor edits.
- **Weekly Background Rollups (Issue #136)**: A daily cron (`presshub_ai_memory_build_rollups`) compresses every completed ISO week inside the background window into one weekly rollup (`PressHub_AI_Memory_Rollup_Builder`, Greek prompt `assets/prompts/memory/weekly_rollup.txt`, `briefing_memory` module): a compact background narrative plus the week's threads under their existing story keys. It reads the daily digests as edited by humans, records missing days, retries once on invalid output, then stores `failed` with the error. Editing a digest marks its rollup `stale`; stale rollups are rebuilt only on request (the memory editor, or `wp presshub memory rollups --from=YYYY-MM-DD --to=YYYY-MM-DD [--force]` after a backfill), and a rebuild never overwrites an edited rollup.
- **Edit-Protection Invariant**: Human editor revisions (`origin='edited'`) in `wp_presshub_ai_memory_entries` are preserved across regenerations; editors can deliberately reset to generated content via `reset_to_generated()`.
- **Stale Propagation**: Revisions or exclusions to daily digests automatically mark dependent weekly rollups as `stale`.
- **Clean Slate Post Deletion**: Deleting a daily briefing post automatically deletes associated daily memory entries and cleans links in `wp_presshub_ai_memory_entry_links`.
- **Settings Reference**:
  - `presshub_ai_memory_enabled`: Enable or disable the editorial memory stream (default: `true`).
  - `presshub_ai_memory_daily_days`: Number of recent days covered as daily story digests (bounds: 0–14, default: 7).
  - `presshub_ai_memory_background_days`: Background window covered as weekly rollups (bounds: 0–90, clamped >= daily days, default: 30).
  - `presshub_ai_memory_briefing_budget_chars`: Maximum character budget for memory context injected into daily briefing prompt (bounds: 0–60,000, default: 12,000).
  - `presshub_ai_memory_podcast_budget_chars`: Maximum character budget for memory context injected into podcast prompt (bounds: 0–60,000, default: 6,000).
  - `presshub_ai_memory_retention_days`: Retention period before old memory entries and links are pruned (bounds: 30–730, default: 180).
  - Module `briefing_memory`: Dedicated AI provider and model routing for memory digests and weekly rollups (`presshub_ai_briefing_memory_provider`, `presshub_ai_briefing_memory_model`).


### 7. 🔎 Generation Inspector (Issue #133)
- **Where to find it**: open any Daily Briefing or podcast post in the editor (block or classic) and scroll to the **Generation Inspector** box, or go to **PressHub → Generation records** to browse records by date range, kind and status and open any of them. Editors need the `edit_others_posts` capability.
- **Every AI call is recorded (#140)**: besides briefing text and podcast scripts, records exist for co-author drafts, editorial scorecards, copilot chat, research reports, generated images, every other AI call (classify intent, URL summaries, titles, social copy, connection tests) and TTS tests. They appear in the same box on the post they belong to (a copilot chat without a post is listed only under **Generation records**). Chat, draft, scorecard and research records can contain unpublished work: editors see all of them, authors see only their own.
- **What it shows** for each generation step (briefing text, podcast script and the kinds above; regenerations keep the earlier records, pick one in the selector): the settings that were really used (provider, model and where each came from, temperature, max tokens, timeout, preset, token usage and estimate, truncation), the exact **system prompt** and **user prompt** (with a copy button and character count), the **articles** sent and dropped with the reason and the caps, the **memory** that was injected (or that memory was disabled), the raw **response**, and for podcasts the **TTS** settings plus a **TTS calls** table (one row per call: model, fallback model, voice, text length and hash, HTTP status, error, duration, audio length, the request without audio or keys, and the topic and stitched summaries). **Raw JSON** downloads the whole record.
- **Debug files are now optional**: the prompt and TTS payload debug files (Settings, both titled **Raw debug dump (developer use)**) stay off by default, because generation records already keep this information for editors. Records are pruned after `presshub_ai_generation_record_retention_days` (default 90).
- **Read-only and safe**: nothing can be edited here; scraped text is always shown as plain text. Large fields load only when their tab is opened, so the editor stays fast. Posts created before records were introduced show "No generation record".

- **Multi-Tier Presets**: Admin-curated global defaults, author-specific profiles, author defaults, and per-request metabox dropdown selection. Presets append specialized instructions to base system prompts without overwriting foundational editorial safety constraints.
- **Externalized Prompt Assets**: Prompts are stored as modular text files under `presshub-ai-editor/assets/prompts/` (e.g. `default_greek_briefing.txt`, `podcast_style_two_hosts_greek.txt`, `article_image_analysis_prompt.txt`) and dynamically managed via `PressHub_AI_Prompt_Loader`.
- **Admin Prompt Studio**: Inspect, customize, and reset prompt templates directly within WordPress settings without editing source code.


### 8. 🧠 Memory Editor (Issue #137)
- **Where to find it**: **PressHub → Memory** (editors need `edit_others_posts`). The Generation Inspector's Memory tab links straight to the entry it used.
- **Workflow**: check the preview of what the next morning briefing will receive; spot red-highlighted days that have no digest; open a digest with **Review / edit**, correct a story's title, development, status or entities, add or delete stories, or untick *Include in memory*, then **Save changes**. The entry is marked **edited**, a diff shows what changed against the generated version, and automatic regeneration never overwrites your text. Use **Reset to generated** to undo, **Exclude entry** to keep a whole day out of memory, **Regenerate digest** to refresh the generated version from the briefing post, and **View generation record** to see the prompt behind it.
- **Weekly rollups**: you can edit a rollup's narrative. Editing a daily digest marks its rollup **stale**; a notice offers **Rebuild rollup** once weekly rollups are available.

---

## 🏛️ Architectural Principles

PressHub AI adheres strictly to enterprise software engineering standards:

### ⚙️ Settings-First Principle
> **No operational limit, threshold, cap, time budget, batch size, retry count, or rate limit that affects user-visible behavior may be hardcoded inside business logic.**

Every parameter is registered in the WordPress Options API with strict sanitization bounds, exposed via `PressHub_AI_Settings_Storage`, and rendered in the admin settings UI. Where a setting lives and how it is added, changed or removed is fixed by the binding [settings design principles](docs/settings-design-principles.md), checked when a change is designed and again before it is merged. Module-level token limits and HTTP timeouts enforce minimum safe thresholds (e.g. `max_tokens ≥ 64`, `timeout ≥ 10s`) ensuring unrendered form submissions or empty inputs never corrupt model execution parameters.

### 🛡️ Zero-Silent-Fallback Principle
> **No business logic, API client, prompt resolver, or pipeline stage may silently fall back to a hardcoded model, prompt, or operational parameter when configuration is missing or failing.**

Missing keys, unconfigured models, or failed API calls halt immediately with explicit `WP_Error` objects or descriptive exceptions, logging full diagnostic details to structured logs. Hidden code fallbacks and silent multi-model retry loops are prohibited.

### 🧹 Clean-Slate Architecture
> **The Provider Store is the sole source of truth. No legacy option shadowing or backward-compatibility baggage.**

All provider parameters, keys and default models resolve through `PressHub_AI_Provider_Store` (the `presshub_ai_configured_providers` option, edited in Settings → Connections). Settings that are no longer read are removed through the settings clean-up (`PressHub_Settings_Cleanup`: a copy of all settings first, then a report on Usage & logs), never left in resolution cascades; see the [settings design principles](docs/settings-design-principles.md) §4.3.

---

## 🪵 Enterprise Observability & 4-Layer Logging

PressHub AI implements a 4-layer logging architecture to provide total transparency into every automated decision, token cost, and administrative action:

| Layer | Purpose | Target / Storage | Inspection Tool |
|---|---|---|---|
| **Layer 1: Structured Logs** | Subsystem operations, harvesting steps, TTS synthesis, HTTP events | `wp-content/uploads/presshub-ai/presshub-debug-<suffix>.log` (protected folder, random file name; see **Log storage and privacy**) | `php dev-env/scripts/tail-logs.php --source=presshub` |
| **Layer 2: Token Telemetry** | Model IDs, prompt/completion tokens, latency, cost, and JSON metadata | `wp_presshub_ai_token_logs` | `php dev-env/scripts/view-token-logs.php` |
| **Layer 3: Security & Audit** | Settings updates, provider credential changes, and admin actions | `wp_presshub_ai_audit_log` | `php dev-env/scripts/query-db.php "SELECT * FROM wp_presshub_ai_audit_log"` |
| **Layer 4: WordPress Core** | PHP runtime warnings, fatal tracebacks, cURL/environment issues | `wp-content/debug.log` | `php dev-env/scripts/tail-logs.php --source=wp` |
| **Generation Records** | Everything that went into each briefing and podcast-script generation: exact system and user prompts, the real provider/model/temperature/max tokens/timeout, the articles sent and dropped (with the reason), memory used, the response, token usage, and the TTS settings used for the podcast audio. Linked to the post via `_presshub_generation_record_ids`; kept for `presshub_ai_generation_record_retention_days` (default 90). Recorded always, independent of the debug-log toggles. | `wp_presshub_ai_generation_records` | `php dev-env/scripts/query-db.php "SELECT id, kind, briefing_date, edition, status, post_id FROM wp_presshub_ai_generation_records ORDER BY id DESC LIMIT 5"` |

**Secrets and provider errors:** API keys and Bearer tokens are masked automatically in every log layer (`PressHub_AI_Secret_Masker`). Provider API failures (OpenAI, Anthropic, Gemini, custom endpoints, Imagen, TTS) appear in the plugin log viewer as `ERROR` lines with the provider, model, action, HTTP code and trace ID.

**Audit log behind a proxy:** the audit log records the visitor IP from `REMOTE_ADDR`. If the site sits behind a trusted reverse proxy or CDN, add a filter so the real client IP is used, for example:

```php
add_filter( 'presshub_ai_client_ip_header', fn() => 'HTTP_CF_CONNECTING_IP' );
```

Only set this behind a proxy you control: otherwise clients can spoof the header.

### Log storage and privacy

The three diagnostic log files (application log, LLM prompts & responses, TTS payloads) are written by `PressHub_AI_Log_Paths` to `wp-content/uploads/presshub-ai/` under random file names (`presshub-debug-<suffix>.log`, `presshub-ai-prompts-<suffix>.jsonl`, `presshub-ai-tts-<suffix>.jsonl`). The plugin keeps `.htaccess` (Apache/LiteSpeed), `index.php` and `web.config` (IIS) deny files in that folder and never overwrites an existing one. **PressHub → Settings → Site & system → Logging & retention** shows whether the folder can be opened from the web (re-checked every 12 hours, or on demand with **Re-check now**).

- **nginx** ignores `.htaccess`. Add a deny rule to the server block:

  ```nginx
  location ~* /wp-content/uploads/presshub-ai/ { deny all; }
  ```

- **Keep logs outside the website** by defining a writable folder in `wp-config.php`:

  ```php
  define( 'PRESSHUB_AI_LOG_DIR', '/home/example/presshub-logs' );
  ```

  If the folder is missing or not writable, file logging stops and an admin notice names the constant; logs never fall back to uploads.
- **Retention**: rotated log files are deleted after `presshub_ai_log_file_retention_days` (default 14), token logs after `presshub_ai_token_log_retention_days` (default 60) and audit logs after `presshub_ai_audit_log_retention_days` (default 90). **Clear logs** also removes rotated copies.
- The social worker rotates `logs/worker.log` at `PRESSHUB_LOG_MAX_BYTES` (default 10 MB) and keeps `PRESSHUB_LOG_MAX_FILES` old copies (default 5).

### CLI Observability Cheat-Sheet

```bash
# Stream all PressHub logs in real time
php dev-env/scripts/tail-logs.php --follow

# Filter logs by error level
php dev-env/scripts/tail-logs.php --level=ERROR

# Inspect aggregate LLM token usage, latencies, and provider breakdowns
php dev-env/scripts/view-token-logs.php --stats

# View recent token logs with detailed JSON request/response metadata
php dev-env/scripts/view-token-logs.php --detail=1

# Query the SQLite database directly
php dev-env/scripts/query-db.php "SELECT provider, model, total_tokens, duration_ms FROM wp_presshub_ai_token_logs ORDER BY id DESC LIMIT 5"
```

---

## ⚙️ Settings Reference Guide

Open **PressHub → Settings** (`admin.php?page=presshub-ai&section=<slug>`). The page shows one section at a time, chosen from the list on the left (a drop-down on narrow screens), and each section has its own **Save**, which writes only that section. The search box above the list finds a setting by its name, help text or old name and opens the section that holds it. Every option and its section are listed in [`docs/settings-inventory.md`](docs/settings-inventory.md). The rules for adding, moving or removing a setting are in [`docs/settings-design-principles.md`](docs/settings-design-principles.md).

- **Connections**: every key, password and account in one place: AI providers (speech uses the provider chosen in AI models), Google Cloud, the press inbox login, social platform accounts, the automation worker (mode, secret, heartbeat, logins, Regenerate secret) and the plugin update token. Each connection shows its last test result with the time it was checked; loading the page makes no remote request, and Test refreshes only what you clicked. A setup check at the top (also on the Daily Briefing Hub) lists what blocks PressHub from working, each with a link to its fix, or says "Everything is connected". Saved keys are never shown: Replace key opens an empty field, and leaving it empty keeps the saved key.
- **AI models**: one table of which provider and model each AI task uses (Co-author, Editorial QA, Copilot, Briefing curator, Podcast script, Memory, Image prompt, Image generation, Social copy, Speech, Music), with the default model, each task's temperature, max tokens and timeout in its row, a list of known models per provider plus "Other model ID…", and a check that refuses a model of the wrong type (for example a speech model for social copy). Each feature shows the model it uses, with a link to its row.
- **Writing & QA**: drafting, the QA gate (editorial score, legal check and what a failed check does) and its prompt, SEO (including the SEO check's minimum score), featured images, and a link to Presets. Every QA review, with its scores and feedback, is listed on **PressHub → QA reviews**.
- **Daily Briefing**: schedule, sources and harvesting (including the Press Release Inbox folders and filters), text story, podcast script, voices and audio, publishing.
- **Editorial knowledge**: policy and stakeholder fiches, editorial memory. **PressHub → Help** (2.49.0) explains how the editorial agenda, policy fiches, people fiches and memory shape what the AI writes, which AI task gets each one (read from these settings), user stories, when to use the agenda and when a policy fiche, and the limits of each. Since 2.52.0 policy fiches get 1,200 characters in social copy and the TikTok short plan (was 800), under a shorter header, so a fiche's framing actually reaches those posts; a saved 800 is raised once, any other saved value is kept. The limits now count the themes and fiches themselves, not the fixed instructions around them, and anything a limit leaves out or shortens is recorded: the generation record lists it under "Left out because of limits" and the log notes it. Since 2.67.0 the same holds for the material of every AI call (article text for social copy, Shorts, post images, SEO and image analysis, the co-pilot's article, fetched source pages and the number of source URLs), and Settings → Editorial knowledge shows which published policy fiches fit each task.
- **Social publishing**: platforms, post copy and its prompts, images and video (including TikTok narration), Shorts, the queue.
- **Social listening**: account monitoring and the Trending in Cyprus roundup.
- **Site & system**: link previews (OpenGraph), logging and retention (log level, debug dumps, generation records and other retention periods), rate limits, diagnostics, and settings export and import.
- **Clean-up (2.45.0)**: unused settings (a second GitHub token, three X OAuth1 keys and the old podcast prompts 1–3) are deleted on upgrade after a copy of all settings is saved; the report and the copy are on PressHub → Usage & logs.
- **Old API keys (2.46.0)**: PressHub reads keys only from the providers in Connections. The old single API key, the old Gemini key and the separate speech key are copied into a matching provider if none has them, then removed; the Speech key field is gone from Connections. Each clean-up run has its own report and copy on Usage & logs.
- **Hidden old settings (2.71.0)**: 22 settings that were stored but never shown are removed after the copy is saved. The old provider choice and the per-provider model, temperature, max tokens and timeout go (each provider in Connections has its own, and AI models picks the provider per task). The OpenAI organization and the Anthropic API version are copied onto those providers, and the Connections dialog now has "Organization ID" and "API version" fields. The per-platform social switches are folded into the enabled platforms list. The old morning title prefix and the old single image pool are copied into the podcast title prefix and the Morning pool when those are not set. Saving a provider in Connections now keeps its custom HTTP headers.

- **Defaults, translations and uninstall (2.70.0)**:
  - Every setting shows its default with Reset to default, except prompts, which use Load default template.
  - Setting names and help in Settings search appear in the site's language.
  - Deleting the plugin removes every PressHub setting, store and saved key, on every site of a network.

- **Saving without JavaScript (2.68.1)**: with JavaScript off, a section's Save still saves only that section, through the same checks as the normal Save, and returns to it with a notice. It no longer posts the whole option group to options.php, which ignored Social settings and reset unposted checkboxes in other sections.
- **Settings audit and search (2.69.0)**: these now write an audit event to Usage & logs (never with a secret value):
  - Regenerate worker secret
  - the Social prompts reset
  - the Presets editor (site-wide presets, taxonomy and role defaults)
  - the editorial agenda
  - people fiche edits and merges
  - the Shorts music library

  Settings search also finds API keys, tokens and passwords by name and opens their Replace key button.
- **Defaults, translations and uninstall (2.70.0)**:
  - Every setting shows its default with Reset to default, except prompts, which use Load default template.
  - Setting names and help in Settings search appear in the site's language.
  - Deleting the plugin removes every PressHub setting, store and saved key, on every site of a network.

Old links (`?tab=…` of the former AI and Social settings pages) redirect to the section that holds the tab.

---

## 💻 Local Development & Testing

The repository features a self-contained local WordPress development environment powered by the official WordPress Core SQLite engine (zero external MySQL/MariaDB or Docker requirements).

### Environment Layout

```
presshub/
├── presshub-ai-editor/          # Core WordPress plugin (PHP)
│   ├── includes/                # Business logic, modules, Provider Store
│   ├── assets/                  # Sidebar UI, admin JS/CSS, prompt assets
│   └── tests/                   # 73 isolated PHP unit test suites
├── presshub-workflow/           # TypeScript workflow & MCP server
│   ├── src/                     # MCP scorecard server & sync utilities
│   └── tests/                   # 6 Jest test suites (81 tests)
├── dev-env/                     # Local SQLite WordPress runtime & CLI tools
│   ├── scripts/                 # tail-logs, view-token-logs, query-db, setup
│   └── wordpress/               # Local WordPress installation
└── docs/                        # Specifications, plans, review records
```

### 1. Starting the Dev Server
```bash
# Start local WordPress instance at http://127.0.0.1:8888
php dev-env/scripts/server.php
```
- **Front-end**: `http://127.0.0.1:8888`
- **WP Admin**: `http://127.0.0.1:8888/wp-admin/` (User: `admin`, Password: `password123`)
- **Live Code Sync**: The plugin directory is dynamically linked via a filesystem junction (`mklink /J`) directly to `presshub-ai-editor/`.

### 2. Running PHP Unit Tests
The PHP test suite runs in isolation without external database dependencies:
```bash
cd presshub-ai-editor
php tests/run-all-tests.php

# PressHub Social Auto-Post PHP unit tests (20 suites, 202 assertions):
php tests/run-social-tests.php

# PressHub Social Worker TypeScript build & Jest tests (5 suites, 76 tests):
cd ../presshub-social-worker
npm test
npm run build
```
*Executes all **84 AI Editor test suites**, **19 Social Auto-Post test suites (171 tests)**, and **5 Social Worker suites (76 tests including 4-tier progressive Twitter architecture with CreateTweet network interception)** with 100% pass rate.*

### 3. Running TypeScript & MCP Workflow Tests
The workflow package integrates Model Context Protocol (MCP) servers and editorial automation:
```bash
cd presshub-workflow
npm install
npm test
npx tsc --noEmit
```
*Executes all **6 Jest test suites (81 tests)** with strict TypeScript type-checking.*

### 4. Running WP-CLI Commands
```bash
# Via Windows batch wrapper
dev-env\bin\wp plugin list
dev-env\bin\wp eval "echo get_option('presshub_ai_log_level');"

# Or via PHP
php dev-env/bin/wp-cli.phar --path=dev-env/wordpress <command>
```

---

## 📦 Requirements & Installation

### Requirements
- **WordPress**: `≥ 6.0` (tested up to `6.7`)
- **PHP**: `≥ 7.4`
- **API Keys**: At least one active provider key (OpenAI, Anthropic Claude, or Google Gemini / Google Cloud Vertex AI & TTS).

### Installation Steps
1. Download the latest release zip (`presshub-ai-editor-x.x.x.zip`) from the [GitHub Releases](https://github.com/eeftychiou/presshub/releases) tab.
2. In your WordPress admin dashboard, navigate to **Plugins → Add New → Upload Plugin**.
3. Choose the downloaded zip file and click **Install Now**.
4. Activate **PressHub AI Co-Pilot**.
5. Navigate to **PressHub → Settings → Connections** to add your AI provider keys. The setup check at the top lists anything that still needs attention.

### Automatic Updates
The plugin embeds an integrated GitHub update checker. Releases published on the `main` branch with attached zip assets are automatically detected in WordPress. For private repositories, configure the optional GitHub Personal Access Token under **PressHub → Settings → Connections → Plugin updates**.

### Reliable Background Scheduling (WP-Cron)
For production sites, high-reliability background research and automated daily briefing harvesting should be bound to a system cron daemon rather than relying on web visitor traffic:

```bash
# In wp-config.php:
define( 'DISABLE_WP_CRON', true );

# In crontab (running every minute):
* * * * * wp cron event run --due-now --path=/var/www/html >/dev/null 2>&1
```

---

## 📄 License

This plugin is licensed under the **GPL-2.0-or-later** license. See the license declaration in `presshub-ai-editor/presshub-ai-editor.php` for complete terms.
