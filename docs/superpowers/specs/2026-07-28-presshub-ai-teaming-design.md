# PressHub AI Teaming (Journalist & Editor) Design

**Date**: 2026-07-28  
**Status**: Approved Design  
**Target Platform**: WordPress (via `royal-mcp` & WordPress environment)

---

## 1. Overview & Objectives
Expanding the `presshub-ai-editor` plugin to support a deep "Journalist-AI teaming" experience. This includes interactive chat, async deep research capabilities, image generation, and a multimodal auto-reporter, all centralized in a unified Gutenberg Sidebar.

We will implement this using a **Fully Self-Contained WordPress Plugin** approach. All business logic, API communication, and background queuing will run within the WordPress plugin itself, using plain JavaScript/`wp.element` (no compilation step) in the Gutenberg editor and standard PHP on the backend.

---

## 2. Architecture: Unified AI Assistant Panel

### UI Component (Gutenberg Sidebar)
- **Registration**: Registered in [assets/sidebar.js](file:///C:/Users/User/Antigravity/Presshub/presshub-ai-editor/assets/sidebar.js) using the WordPress plugin and Gutenberg APIs (`wp.plugins`, `wp.editPost`, `wp.element`, `wp.components`).
- **Chat UI**: Built using standard WordPress components (`wp.components` like `TextControl`, `Button`, `Spinner`, `PanelBody`, etc.). It hosts a scrollable message log showing user messages, AI messages, and actionable widgets.
- **Actionable Cards**:
  - **Research Card**: Shows a progress spinner while the cron task is running. Once finished, displays an **"Insert Research Summary"** button.
  - **Image Card**: Shows a thumbnail of the generated image and an **"Insert Image Block"** button.
  - **Auto-Reporter Card**: Shows a native HTML5 `<audio>` player with the generated TTS report and an **"Insert Audio Block"** button.
- **Editor Integration**: All "Insert" actions insert blocks directly at the current cursor position in the block editor canvas using Gutenberg state dispatch (`wp.data.dispatch('core/editor').insertBlocks`).

---

## 3. Intents, Routing & API Client

### AJAX Endpoint (`presshub_ai_chat`)
A unified AJAX routing endpoint on the WordPress backend:
1.  **Security**: Nonce and `edit_posts` capability checks.
2.  **Intent Classification**: Calls Gemini (using the main key) with a system instruction to classify the user's prompt into one of: `chat`, `research`, `image`, or `report`.
3.  **Routing**:
    - **`chat`** (Default): Synchronous prompt to Gemini, returning inline text response.
    - **`research`**: Creates a custom post type (`presshub_research`) with `pending` status, schedules a WP-Cron task, and returns `{"status": "researching", "research_id": CPT_ID}`.
    - **`image`**: Calls Google Cloud Imagen API, downloads the generated image, uploads it to the Media Library, and returns the attachment details.
    - **`report`**: Transcribes/summarizes media using Gemini, generates a script, calls Google Cloud TTS, imports the audio to the Media Library, and returns the audio attachment details.

### API Credentials (PressHub AI Settings)
We will add separate settings inputs in the PressHub AI Settings page (`class-settings.php`):
- `presshub_ai_google_cloud_api_key`: API key for Google Cloud services (Imagen and Cloud TTS).
- `presshub_ai_api_key` remains the primary key for the Gemini models.

### API Client Extensions (`PressHub_AI_API_Client`)
- `classify_intent( $prompt )`: Gemini call to classify the prompt.
- `generate_image_via_imagen( $prompt )`: Calls Google Cloud Imagen API using `presshub_ai_google_cloud_api_key`, saves binary output to a temporary file, and imports it to WordPress via `media_handle_sideload()`.
- `generate_audio_via_tts( $text )`: Calls Google Cloud Text-to-Speech API, decodes base64 response, saves to a temporary file, and imports it to WordPress via `media_handle_sideload()`.

---

## 4. Asynchronous Background Research

### Custom Post Type (`presshub_research`)
A hidden custom post type registered in the plugin to persist research records:
- **Status Meta**: `_research_status` (`pending`, `processing`, `completed`, `failed`).
- **Prompt Meta**: `_research_prompt`.
- **Source Post Meta**: `_associated_post_id`.
- **Post Content**: Holds the final synthesized Markdown/HTML research report.

### WP-Cron Worker
Schedules a one-off task using `wp_schedule_single_event( time(), 'presshub_ai_do_research', [ $research_id ] )`.
- **Execution (`presshub_ai_do_research` hook)**:
  1. Updates CPT status to `processing`.
  2. Synthesizes post context, instructions, and source notes using Gemini 1.5 Pro.
  3. Writes the synthesized research report to the CPT's `post_content`.
  4. Updates CPT status to `completed`.
  5. On error, updates status to `failed` and saves the error message.

### Polling Endpoint (`presshub_ai_check_research`)
An AJAX endpoint queried by the Gutenberg sidebar every 3-5 seconds to check research progress, returning status and content upon completion.
