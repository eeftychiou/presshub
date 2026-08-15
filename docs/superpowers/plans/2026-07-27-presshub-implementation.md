# PressHub Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the PressHub website structure and the local AI editorial workflow automation scripts using Test-Driven Development.

**Architecture:** We will configure the remote WordPress site using `royal-mcp` tools (categories, menus, custom CSS). For the AI Editorial Workflow, since direct custom plugin installation is restricted, we will build a local Node.js automation service (`presshub-workflow`) that uses the MCP `@modelcontextprotocol/sdk` to interface with `royal-mcp` (WordPress) and `model-router` (AI models) to perform co-authoring and reviews.

**Tech Stack:** WordPress (remote via `royal-mcp`), Node.js, Jest (TDD), TypeScript, `@modelcontextprotocol/sdk`.

## Global Constraints

- Code must strictly follow Test-Driven Development (TDD): Write failing test -> Verify failure -> Write minimal code -> Verify pass -> Refactor.
- Target directory for the local automation script is `presshub-workflow/`.
- Must use existing MCP tools for all WP interactions.

---

### Task 1: WordPress Structure & Categories Configuration

**Files:**
- Modify: WordPress Remote State via `royal-mcp`

**Interfaces:**
- Consumes: Nothing
- Produces: 5 configured categories in WordPress (Europe, In Context, Opinion, Podcasts, Stories)

- [ ] **Step 1: Check existing categories**
Run MCP tool `wp_get_categories` to see existing categories.

- [ ] **Step 2: Create required categories**
Run MCP tool `wp_create_term` for each missing category:
1. "Europe" (taxonomy: category)
2. "In Context" (taxonomy: category)
3. "Opinion" (taxonomy: category)
4. "Podcasts" (taxonomy: category)
5. "Stories" (taxonomy: category)

---

### Task 2: WordPress Navigation Menu & Appearance

**Files:**
- Modify: WordPress Remote State via `royal-mcp`

**Interfaces:**
- Consumes: Categories from Task 1
- Produces: Primary navigation menu and basic site info

- [ ] **Step 1: Update site info**
Run MCP tool `wp_update_option` for `blogname` ("PressHub") and `blogdescription` ("An ultra-minimalist digital publication").

- [ ] **Step 2: Create Menu**
Run MCP tool `wp_get_menus` and if no primary menu exists, create one or update existing to include the 5 categories.

- [ ] **Step 3: Update Custom CSS**
Run MCP tool `wp_update_custom_css` with minimalist overrides:
```css
body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #fafafa; color: #111; }
h1, h2, h3, h4, h5, h6 { font-family: 'Georgia', serif; font-weight: normal; }
.site-title { font-weight: bold; text-transform: uppercase; letter-spacing: 2px; }
.site-header { border-bottom: 2px solid #000; padding-bottom: 20px; }
.entry-content { max-width: 720px; margin: 0 auto; line-height: 1.6; }
```

---

### Task 3: Setup Node.js AI Workflow Project (TDD Foundation)

**Files:**
- Create: `presshub-workflow/package.json`
- Create: `presshub-workflow/tsconfig.json`
- Create: `presshub-workflow/jest.config.js`

**Interfaces:**
- Consumes: Nothing
- Produces: A ready-to-test Node.js environment

- [ ] **Step 1: Initialize Project**
```bash
mkdir -p presshub-workflow/src presshub-workflow/tests
cd presshub-workflow
npm init -y
npm install --save-dev typescript @types/node jest ts-jest @types/jest
```

- [ ] **Step 2: Configure TypeScript and Jest**
```bash
cd presshub-workflow
npx tsc --init
npx ts-jest config:init
```

---

### Task 4: AI Editorial Scorecard Logic (TDD)

**Files:**
- Create: `presshub-workflow/src/editor.ts`
- Create: `presshub-workflow/tests/editor.test.ts`

**Interfaces:**
- Consumes: Raw text draft
- Produces: `generateScorecard(content: string): Promise<{ score: number, feedback: string }>`

- [ ] **Step 1: Write the failing test**
Create `presshub-workflow/tests/editor.test.ts`:
```typescript
import { generateScorecard } from '../src/editor';

describe('AI Editor', () => {
    it('returns a scorecard with a score and feedback', async () => {
        const result = await generateScorecard('This is a test draft article.');
        expect(result).toHaveProperty('score');
        expect(result).toHaveProperty('feedback');
        expect(typeof result.score).toBe('number');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**
```bash
cd presshub-workflow
npx jest tests/editor.test.ts
```
Expected: FAIL with "Cannot find module '../src/editor'"

- [ ] **Step 3: Write minimal implementation**
Create `presshub-workflow/src/editor.ts`:
```typescript
export async function generateScorecard(content: string) {
    // In actual implementation, this will call the model-router MCP tool.
    // For now, return minimal stub to pass the test.
    return { score: 85, feedback: 'Good start. Needs more context.' };
}
```

- [ ] **Step 4: Run test to verify it passes**
```bash
cd presshub-workflow
npx jest tests/editor.test.ts
```
Expected: PASS

- [ ] **Step 5: Commit**
```bash
cd presshub-workflow
git init
git add src tests package.json tsconfig.json jest.config.js
git commit -m "feat: TDD setup and initial AI Editor stub"
```

---

### Task 5: AI Co-Author Source Processor (TDD)

**Files:**
- Create: `presshub-workflow/src/coauthor.ts`
- Create: `presshub-workflow/tests/coauthor.test.ts`

**Interfaces:**
- Consumes: Source materials array (links, text)
- Produces: `processSources(sources: string[]): Promise<string>`

- [ ] **Step 1: Write the failing test**
Create `presshub-workflow/tests/coauthor.test.ts`:
```typescript
import { processSources } from '../src/coauthor';

describe('AI Co-Author', () => {
    it('combines multiple sources into a single context string', async () => {
        const sources = ['Note 1: Economy is up.', 'Note 2: Markets are stable.'];
        const result = await processSources(sources);
        expect(result).toContain('Economy is up');
        expect(result).toContain('Markets are stable');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**
```bash
cd presshub-workflow
npx jest tests/coauthor.test.ts
```
Expected: FAIL with "Cannot find module"

- [ ] **Step 3: Write minimal implementation**
Create `presshub-workflow/src/coauthor.ts`:
```typescript
export async function processSources(sources: string[]): Promise<string> {
    return sources.join('\n\n');
}
```

- [ ] **Step 4: Run test to verify it passes**
```bash
cd presshub-workflow
npx jest tests/coauthor.test.ts
```
Expected: PASS

- [ ] **Step 5: Commit**
```bash
cd presshub-workflow
git add src/coauthor.ts tests/coauthor.test.ts
git commit -m "feat: AI coauthor source processor"
```

---

### Task 6: WordPress Polling Sync Service (TDD)

**Files:**
- Create: `presshub-workflow/src/sync.ts`
- Create: `presshub-workflow/tests/sync.test.ts`

**Interfaces:**
- Consumes: `generateScorecard`
- Produces: Sync loop that checks for posts with status `draft` and updates them.

- [ ] **Step 1: Write the failing test**
Create `presshub-workflow/tests/sync.test.ts`:
```typescript
import { processDrafts } from '../src/sync';

describe('WordPress Sync', () => {
    it('processes a draft post and returns the updated post data', async () => {
        // Mocking the MCP WP fetch
        const mockDrafts = [{ id: 1, content: { raw: 'Test content' } }];
        const fetchDrafts = jest.fn().mockResolvedValue(mockDrafts);
        const updatePost = jest.fn().mockResolvedValue(true);
        
        const processedCount = await processDrafts(fetchDrafts, updatePost);
        expect(processedCount).toBe(1);
        expect(updatePost).toHaveBeenCalled();
    });
});
```

- [ ] **Step 2: Run test to verify it fails**
```bash
cd presshub-workflow
npx jest tests/sync.test.ts
```
Expected: FAIL

- [ ] **Step 3: Write minimal implementation**
Create `presshub-workflow/src/sync.ts`:
```typescript
export async function processDrafts(fetchDrafts: () => Promise<any[]>, updatePost: (id: number, data: any) => Promise<boolean>) {
    const drafts = await fetchDrafts();
    let count = 0;
    for (const draft of drafts) {
        await updatePost(draft.id, { status: 'pending' });
        count++;
    }
    return count;
}
```

- [ ] **Step 4: Run test to verify it passes**
```bash
cd presshub-workflow
npx jest tests/sync.test.ts
```
Expected: PASS

- [ ] **Step 5: Commit**
```bash
cd presshub-workflow
git add src/sync.ts tests/sync.test.ts
git commit -m "feat: WordPress draft sync logic"
```
