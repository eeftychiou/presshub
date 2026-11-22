# Social posts matrix: what is made, where and when

Plugin 2.84.0. This file describes what each kind of post becomes on each social platform, the settings behind each format, and the social post gate. The same matrix, with the site's live settings next to it, is on **PressHub → Help → Social posts: what is made, where and when** (`PressHub_AI_Help_Admin::section_social()`). When you change the posting rules in code, update both.

## Kinds of post

| Kind | How it is recognised | What triggers its social posts |
|---|---|---|
| Article | Any published post with text that is not a briefing | First publish (Auto-Publish on Post), the Social box, or Re-post / Retry in the Social Queue |
| Trending roundup | An article with `_presshub_social_trending_key` | The trending roundup run; its post mode (daily, rolling, each) decides whether a new post or an update is made |
| Written daily brief | `_presshub_briefing_type` = `text` | The morning and noon briefing run |
| Podcast | `_presshub_briefing_type` = `podcast`, with `_presshub_podcast_script` | The morning and noon briefing run, after the written brief |

## Master matrix

Read a cell from left to right. The post gets the first option whose condition holds. "Plus" means both go out. A video or slides never replace the Facebook link post, or a podcast's post on X. Only an article's X post can carry its short instead of its image (X Video Posts, 2.85.0).

| Platform | Article (also trending roundups) | Written daily brief | Podcast |
|---|---|---|---|
| X | Post with link. With X Video Posts and Article Shorts on, it carries the article short instead of its image (the image post if the short cannot be made) | Post with link | Post with link. Plus the daily brief video if X Video Posts are on and Podcast Shorts Format is daily brief (not posted when longer than 2 min 20 s; no per-story shorts on X) |
| Facebook | Link post. Plus 1 Reel if Facebook Reels and Article Shorts are on | Link post only | Link post. Plus Reels if Facebook Reels are on: the daily brief video (browser engine only, because the Graph API takes Reels up to 90 s) or one Reel per story |
| Instagram | 1 Reel if Instagram Reels and Article Shorts are on. Otherwise the classic image card | Nothing | Reels if Instagram Reels are on: the daily brief video or one per story. Otherwise the classic image card |
| TikTok | 1 short if TikTok Shorts and Article Shorts are on. Otherwise photo slides if Photo Slides and Article Shorts are on. Otherwise the classic video card | Nothing | If TikTok Shorts are on: the daily brief video, or one short per story. Otherwise photo slides (only in the one-short-per-story format, with Photo Slides on). Otherwise the classic post with the full podcast |
| YouTube | 1 YouTube Short if YouTube Shorts and Article Shorts are on. Otherwise nothing | Nothing | The full episode (plus the English and Turkish episodes) if Podcast Episodes are on. Plus the daily brief video, or one short per story, if YouTube Shorts are on |

- **Podcast Shorts Format** decides how a podcast is turned into video. The options are one daily brief video of about 2 minutes (the default), or one short per story, with Shorts per Podcast setting how many (default 3).
- **No story goes to TikTok as both a video and photo slides.** Slides only go out where TikTok gets no video of that post, and they replace its classic post.
- **The written daily brief gets no video.** Its podcast, published after it, tells the same stories in video. The filter `presshub_social_briefing_text_video` turns this off (code only).

## Formats and their conditions

| Format | Settings that must be on | Engine and worker | Fallback when a condition fails |
|---|---|---|---|
| Post with link / link post | Platform active | API or browser engine | None. The platform is skipped if it is not configured (WARNING) |
| Classic post | Platform active, and no video or slides for this post on this platform | Worker renders the TikTok video card and the Instagram image card | Not applicable |
| Article short / Reel | Platform Shorts switch, Article Shorts, a non-empty Article Shorts prompt | Pull Mode and the automation engine. Facebook can also use the API engine with Meta Page ID and token | Classic post (WARNING in the log) |
| Podcast short (one per story) | Platform Shorts switch, Podcast Shorts Format = one per story | As above | Full podcast as the classic post (WARNING) |
| Daily brief video | Platform Shorts switch, Podcast Shorts Format = daily brief, Daily Brief Script Length (default 300 words, about 2 min) | As above. Facebook needs the browser engine | Facebook: no Reel (WARNING). Others: as above |
| TikTok photo slides | TikTok Photo Slides, and TikTok gets no video of the post. For articles, also Article Shorts. For podcasts, only in the one-per-story format | Pull Mode, TikTok automation engine, worker 1.10.0 or later | Classic post. The job waits for a capable worker |
| YouTube podcast episode | YouTube Podcast Episodes. English and Turkish Playlists for the translations | Pull Mode, a connected channel, a worker that can upload | Not posted to YouTube |

## The social post gate

`PressHub_Social_Post_Gate` is the single place every post on every platform passes through, including any platform added later. It runs a list of checks, which the filter `presshub_social_post_gate_checks` can extend. The first check that returns a reason stops the post. A stopped post stays in the Social Queue as cancelled, with `Not posted: <reason>`. Re-post in the Social Queue skips the gate (`metadata.gate_bypass`); Retry and Dispatch now check it again. At the queue and plan stages, other posts' waiting jobs count as already posted, so two posts queued close together do not both tell a story.

1. **Queue.** In `PressHub_Social_Dispatcher::enqueue_for_post()`, every platform is checked before any AI copy, caption, image or shorts plan is made. This includes an article's shorts. Before the check, a single-story post is tagged with its story by `tag_post()`. That is one Social copy model call, and it is made only when something was posted inside the window.
2. **Plan.** In `PressHub_Social_TikTok_Shorts::get_plan()`:
   - `edition_blocked()` skips the plan call when no story of the edition's memory digest may go out again.
   - The plan prompt lists what was already posted.
   - `check_stories()` drops a story only when it repeats on every platform the plan serves.
3. **Out.** `check_job()` runs in the worker claim and in `dispatch_job()`. It makes no AI call and compares the job against what was published or is being posted since.

**First check: the story ledger** (`PressHub_Social_Story_Check`). The ledger is read from the queue, so there is no separate store. It looks at each platform separately.

- Inside **Story Window**, a story that a platform already posted goes out there again only with a real new development. It also needs a change in the editorial memory (`dev_hash`) when the memory knows the story.
- A story the memory marks resolved is not retold.
- No story goes out more than **Most Posts per Story per Platform** times on one platform inside the window.
- A daily brief video is stopped only when fewer than two of its stories are left. Otherwise the repeated stories are cut from its script.

## Settings that decide it

| Setting | Where | Default |
|---|---|---|
| Active Platforms, engines | Social publishing → Platforms | — |
| Story Window (`presshub_social_story_repeat_hours`) | Social publishing → Platforms → Social post gate | 36 hours (0 = off) |
| Most Posts per Story per Platform (`presshub_social_story_max_posts`) | Social publishing → Platforms → Social post gate | 2 |
| TikTok Shorts, Instagram Reels, Facebook Reels, YouTube Shorts | Social publishing → Shorts & Reels | Off |
| Article Shorts | Social publishing → Shorts & Reels | On |
| TikTok Photo Slides | Social publishing → Shorts & Reels | On |
| Podcast Shorts Format | Social publishing → Shorts & Reels | Daily brief video |
| Shorts per Podcast | Social publishing → Shorts & Reels | 3 |
| Daily Brief Script Length | Social publishing → Shorts & Reels | 300 words |
| YouTube Podcast Episodes | Social publishing → Shorts & Reels (YouTube) | Off |
| Worker mode | Connections → Automation worker | Pull |
