import fs from 'fs';
import path from 'path';
import { chromium, Browser, BrowserContext } from 'playwright';
import { postTwitter, postTikTok, postFacebook, postInstagram } from '../src/automation/posters';
import { ChromeConnection } from '../src/automation/browser-helper';

const TMP_MEDIA_DIR = path.resolve(__dirname, 'tmp-posters-test');

describe('Automation Posters Strict-Mode & Scoping Tests', () => {
  let browser: Browser;
  let context: BrowserContext;
  let dummyVideoPath: string;
  let dummyImagePath: string;

  beforeAll(async () => {
    fs.mkdirSync(TMP_MEDIA_DIR, { recursive: true });
    dummyVideoPath = path.join(TMP_MEDIA_DIR, 'dummy.mp4');
    dummyImagePath = path.join(TMP_MEDIA_DIR, 'dummy.jpg');
    fs.writeFileSync(dummyVideoPath, 'dummy video binary content');
    fs.writeFileSync(dummyImagePath, 'dummy image binary content');

    browser = await chromium.launch({ headless: true });
    context = await browser.newContext();
  });

  afterAll(async () => {
    await browser.close();
    try {
      fs.rmSync(TMP_MEDIA_DIR, { recursive: true, force: true });
    } catch {}
  });

  test('postTwitter resolves modal dialog textarea when both dialog and timeline are present without strict mode error', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <div class="timeline">
              <div data-testid="tweetTextarea_0">Timeline Textarea (background)</div>
              <button data-testid="tweetButtonInline">Inline Tweet</button>
            </div>
            <div role="dialog">
              <div data-testid="tweetTextarea_0" contenteditable="true"></div>
              <button id="post-btn" data-testid="tweetButton">Post</button>
            </div>
            <script>
              document.getElementById("post-btn").addEventListener("click", () => {
                document.querySelector("[role=dialog]").remove();
                const toast = document.createElement("div");
                toast.setAttribute("data-testid", "toast");
                const link = document.createElement("a");
                link.setAttribute("href", "/user/status/1999888777");
                link.textContent = "View";
                toast.appendChild(link);
                document.body.appendChild(toast);
              });
            </script>
          </body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Automated test tweet with strict mode check',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.platform).toBe('twitter');
    expect(res.externalPostId).toBe('1999888777');
    expect(res.externalPostUrl).toBe('https://x.com/user/status/1999888777');
    expect(res.external_id).toBe('1999888777');
    expect(res.external_url).toBe('https://x.com/user/status/1999888777');
  });

  test('postTwitter gracefully falls back to generated status url if toast link is missing', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <div role="dialog">
              <div data-testid="tweetTextarea_0" contenteditable="true"></div>
              <button id="post-btn" data-testid="tweetButton">Post</button>
            </div>
            <script>
              document.getElementById("post-btn").addEventListener("click", () => {
                document.querySelector("[role=dialog]").remove();
              });
            </script>
          </body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Fallback test tweet',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.externalPostUrl).toMatch(/https:\/\/x\.com\/status\/tw-\d+/);
    expect(res.externalPostId).toMatch(/tw-\d+/);
  });

  test('postFacebook handles multiple textboxes and buttons without strict mode violation', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <div role="button">What's on your mind</div>
            <!-- Multiple textboxes in DOM -->
            <div role="textbox" contenteditable="true">Background comment box</div>
            <div role="dialog">
              <div role="textbox" contenteditable="true">Modal composer</div>
              <div role="button" aria-label="Post">Post In Dialog</div>
            </div>
            <div role="button" aria-label="Post">Post In Timeline</div>
          </body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postFacebook({
      platform: 'facebook',
      copy: 'Facebook post with strict mode prevention',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.platform).toBe('facebook');
    expect(res.externalPostUrl).toMatch(/https:\/\/facebook\.com\/fb-\d+/);
  });

  test('postTikTok handles multiple file inputs and caption boxes without strict mode violation', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <input type="file" accept="video/mp4" />
            <input type="file" />
            <div contenteditable="true">Caption 1</div>
            <div class="notranslate" contenteditable="true">Caption 2</div>
            <button data-e2e="post_video_drop">Post</button>
            <button>Post</button>
            <script>
              document.querySelector("[data-e2e=post_video_drop]").addEventListener("click", () => {
                const msg = document.createElement("div");
                msg.textContent = "Your video has been uploaded";
                document.body.appendChild(msg);
              });
            </script>
          </body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTikTok({
      platform: 'tiktok',
      copy: 'TikTok viral video #strictmode',
      mediaPath: dummyVideoPath,
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.platform).toBe('tiktok');
    expect(res.externalPostUrl).toMatch(/https:\/\/www\.tiktok\.com\/@creator\/video\/tt-\d+/);
  });

  test('postInstagram handles multiple buttons and media upload without strict mode violation', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <a role="link">Create</a>
            <input type="file" />
            <input type="file" />
            <button>Next</button>
            <div role="button">Next</div>
            <div aria-label="Write a caption" contenteditable="true"></div>
            <button>Share</button>
            <div role="button">Share</div>
          </body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postInstagram({
      platform: 'instagram',
      copy: 'Instagram photo caption #tested',
      mediaPath: dummyImagePath,
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.platform).toBe('instagram');
    expect(res.externalPostUrl).toMatch(/https:\/\/instagram\.com\/p\/ig-\d+/);
  });
});
