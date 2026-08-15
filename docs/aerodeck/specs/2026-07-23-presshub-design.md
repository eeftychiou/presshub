# PressHub WordPress Website & Multi-Modal AI Editorial Workflow Design

**Date**: 2026-07-23  
**Status**: Approved Design  
**Target Platform**: WordPress (via `royal-mcp` & WordPress environment)

---

## 1. Overview & Objectives

PressHub is an ultra-minimalist digital publication website designed for journalists, columnists, and podcasters to publish high-quality pieces across 5 main categories. The platform features an advanced multi-modal AI co-authoring and editorial review pipeline that allows authors to draft, refine, and review content alongside an AI assistant before final human editor approval and publication.

---

## 2. Site Architecture & Category Navigation

### Categories
PressHub organizes all content into 5 primary, easily accessible categories:
1. **Europe**: European news, analysis, and feature pieces.
2. **In Context**: Current affairs and agenda commentary framed in historical and political context.
3. **Opinion**: Op-eds and columns from diverse authors.
4. **Podcasts**: Video podcasts (YouTube embeds) and audio podcasts.
5. **Stories**: Narrative stories, investigative pieces, and longform feature articles.

### Site Header & Navigation
- **Logo**: Clean typographic brand header ("PressHub").
- **Top Navigation Bar**: Sticky, minimalist category bar providing instant top-level access to all 5 categories.
- **Footer**: Minimalist copyright, RSS feed, and author archive links.

---

## 3. Visual Aesthetics & Theme Layout

- **Theme Style**: Ultra-Minimalist Newspaper.
- **Typography**: High contrast monochrome styling with elegant serif headlines and crisp sans-serif body text.
- **Main Layout**: Single-column primary feed for featured and latest articles, accompanied by subtle side widgets for category highlights.
- **Author Byline**: Clean, subtle author name and publication date positioned directly under article titles.

---

## 4. Multi-Modal AI Co-Authoring & Editorial Workflow

### Supported Source Materials
Journalists can attach multiple formats as raw research context:
- 🎙️ **Audio Files** (`.mp3`, `.m4a`, `.wav`) — Interviews, voice notes, press conferences.
- 📹 **Video URLs** — YouTube / Vimeo videos or direct video files.
- 📄 **Documents** (`.pdf`, `.docx`) — Research papers, press releases, reports.
- 🔗 **Web Links & Text Notes** — Online articles, bullet points, background context.

### 4-Stage Co-Authoring & Editorial Pipeline

```
[1. Multi-Modal Source Upload] (.docx, .pdf, audio, video link, web notes)
         │
         ▼
[2. AI Co-Author Initial Draft] ──(Journalist Instructions)
         │
         ▼
[3. Journalist-AI Co-Refinement]
         │
         ▼
[4. On-Demand AI Editorial Review] ──(Generates AI Scorecard)
         │
         ▼
[5. Human Editor Approval & Publish]
```

1. **Source & Instruction Upload**: The journalist pastes/uploads research assets (`.docx`, `.pdf`, audio, video link, web link) into the *PressHub AI Assistant* panel in WP Admin, along with specific framing instructions.
2. **AI Co-Author Draft Generation**: The AI transcribes audio/video, extracts document context, and generates a structured initial draft in the WordPress Block Editor.
3. **Collaborative Refinement**: Journalist edits, verifies quotes, and adjusts tone.
4. **On-Demand AI Editorial Review**: Clicking **"Run AI Review"** executes quality assurance checks (grammar, tone, category fit, clarity) and displays an inline *Editorial Scorecard*.
5. **Human Editor Approval & Publication**: Journalist updates post status to `Pending Review`. The Human Editor inspects the draft + AI Scorecard, approves, and clicks **Publish**.

---

## 5. User Roles & Permission Scoping

- **Journalist / Author**: Can create drafts, upload sources, run AI draft generation & AI review, and mark status as `Pending Review`.
- **AI Co-Author / Editor**: Automated service actor that transcribes sources, drafts content, and generates the editorial scorecard.
- **Human Editor / Administrator**: Full access to review pending posts, inspect AI scorecards, edit content, and publish live.

---

## 6. Content Types & Media Formatting

- **Articles** (*Europe*, *In Context*, *Opinion*, *Stories*): Clean text layout with block formatting, pull quotes, and responsive images.
- **Podcasts** (*Podcasts* category): Features top media banner with YouTube video player embed or native audio player, podcaster attribution, episode notes, and optional transcript block.

---

## 7. Verification & Technical Architecture

- WordPress Categories (`Europe`, `In Context`, `Opinion`, `Podcasts`, `Stories`) created via `royal-mcp` tools.
- Navigation Menu created and assigned to primary theme location.
- Custom PressHub Workflow plugin / scripts (`presshub-workflow`) providing:
  - WP Admin metabox for multi-modal source upload & AI Co-Authoring.
  - On-Demand "Run AI Review" button & Editorial Scorecard renderer.
  - Role-based draft status transitions (`Draft` -> `Pending Review` -> `Published`).
