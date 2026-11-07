import fs from 'fs';
import path from 'path';
import { chromium, Browser, BrowserContext } from 'playwright';
import {
  postTwitter,
  postTikTok,
  postFacebook,
  postInstagram,
  getTwitterWeightedLength,
  sanitizeTwitterCopy,
  getTwitterSnowflakeTimestampMs,
  makeTwitterSnowflakeId,
  TWITTER_URL_WEIGHT,
  TWITTER_MAX_WEIGHTED_CHARS
} from '../src/automation/posters';
import { ChromeConnection } from '../src/automation/browser-helper';
import { markSessionInvalid, clearSessionInvalid, verifySession } from '../src/automation/session-manager';

const TMP_MEDIA_DIR = path.resolve(__dirname, 'tmp-posters-test');

describe('Automation Posters Strict-Mode & Scoping Tests', () => {
  let browser: Browser;
  let context: BrowserContext;
  let dummyVideoPath: string;
  let dummyImagePath: string;

  beforeAll(async () => {
    browser = await chromium.launch({ headless: true });
    context = await browser.newContext();
  });

  beforeEach(() => {
    fs.mkdirSync(TMP_MEDIA_DIR, { recursive: true });
    dummyVideoPath = path.join(TMP_MEDIA_DIR, 'dummy.mp4');
    dummyImagePath = path.join(TMP_MEDIA_DIR, 'dummy.jpg');
    fs.writeFileSync(dummyVideoPath, 'dummy video binary content');
    fs.writeFileSync(dummyImagePath, 'dummy image binary content');
  });

  afterAll(async () => {
    await browser.close();
    try {
      fs.rmSync(TMP_MEDIA_DIR, { recursive: true, force: true });
    } catch {}
  });

  test('postTwitter resolves modal dialog textarea and confirms tweet via direct navigation', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          const composeHtml = `<html><body>
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
          page.goto = async (url: string) => {
            if (url && url.includes('/status/')) {
              await page.setContent(`<html><body><article data-testid="tweet"><div>Automated test tweet with strict mode check</div></article></body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
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

  test('postTwitter resolves tweet via profile lookup when toast is missing and confirms publication', async () => {
    // Timeline matches must be newer than the submission, so the fixture ID carries the current time.
    const freshId = makeTwitterSnowflakeId(Date.now());
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          const composeHtml = `<html><body>
            <a data-testid="AppTabBar_Profile_Link" href="/presshubcy">Profile</a>
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
          page.goto = async (url: string) => {
            if (url && url.includes('/presshubcy')) {
              await page.setContent(`<html><body>
                <article data-testid="tweet">
                  <div>Profile fallback test tweet</div>
                  <a href="/presshubcy/status/${freshId}">Timestamp</a>
                </article>
              </body></html>`);
              return null as any;
            }
            if (url && url.includes(`/status/${freshId}`)) {
              await page.setContent(`<html><body><article data-testid="tweet"><div>Profile fallback test tweet</div></article></body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Profile fallback test tweet',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.externalPostUrl).toBe(`https://x.com/presshubcy/status/${freshId}`);
    expect(res.externalPostId).toBe(freshId);
  });

  test('postTwitter throws descriptive error when post confirmation verification fails', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          const composeHtml = `<html><body>
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
                link.setAttribute("href", "/user/status/9999999999");
                link.textContent = "View";
                toast.appendChild(link);
                document.body.appendChild(toast);
              });
            </script>
          </body></html>`;
          page.goto = async (url: string) => {
            if (url && url.includes('/status/')) {
              await page.setContent(`<html><body><div data-testid="error-detail">This Post doesn’t exist</div></body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    await expect(postTwitter({
      platform: 'twitter',
      copy: 'Failing confirmation tweet',
      browserSession: mockSession
    })).rejects.toThrow(/Twitter post verification failed/);
  });

  test('postFacebook handles multiple textboxes and buttons without strict mode violation', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
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
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
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
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
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

  test('postTwitter detects login onboarding redirect, marks session invalid, and throws re-authentication error', async () => {
    clearSessionInvalid('twitter');
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          page.goto = async () => {
            await page.setContent('<html><body><div class="login-wrapper">See what’s happening<input autocomplete="username" /></div></body></html>');
            return null as any;
          };
          // simulate redirect URL
          page.url = () => 'https://x.com/i/jf/onboarding/web?redirect_after_login=%2Fcompose%2Fpost&mode=login';
          return page;
        }
      } as any,
      close: async () => {}
    };

    await expect(postTwitter({
      platform: 'twitter',
      copy: 'Test tweet on expired session',
      browserSession: mockSession
    })).rejects.toThrow(/Twitter session invalid or expired/);

    const verification = verifySession('twitter');
    expect(verification.valid).toBe(false);
    expect(verification.reason).toMatch(/Session marked invalid/);
  });

  test('postTwitter executes single click and does not dispatch duplicate event cycle when click succeeds', async () => {
    clearSessionInvalid('twitter');
    let clickCount = 0;
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          const composeHtml = `<html><body>
            <div role="dialog">
              <div data-testid="tweetTextarea_0" contenteditable="true"></div>
              <button id="post-btn" data-testid="tweetButton">Post</button>
            </div>
            <script>
              document.getElementById("post-btn").addEventListener("click", () => {
                window.__clickEventsCount = (window.__clickEventsCount || 0) + 1;
                document.querySelector("[role=dialog]").remove();
                const toast = document.createElement("div");
                toast.setAttribute("data-testid", "toast");
                const link = document.createElement("a");
                link.setAttribute("href", "/user/status/3344556677");
                link.textContent = "View";
                toast.appendChild(link);
                document.body.appendChild(toast);
              });
            </script>
          </body></html>`;
          page.goto = async (url: string) => {
            if (url && url.includes('/status/')) {
              await page.setContent('<html><body><article data-testid="tweet"><div>Single click test</div></article></body></html>');
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Single click verified tweet',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.externalPostId).toBe('3344556677');
  });

  test('getTwitterWeightedLength and sanitizeTwitterCopy accurately enforce 275-weighted-char budget preserving URL and hashtags', () => {
    // 1. getTwitterWeightedLength verifies URLs count as 23 chars and emojis/Greek count as code points
    const sampleUrl = 'https://presshub.cy/article-slug-very-long-test-url-12345';
    expect(sampleUrl.length).toBeGreaterThan(23);
    const weightedUrlLength = getTwitterWeightedLength(sampleUrl);
    expect(weightedUrlLength).toBe(TWITTER_URL_WEIGHT); // exactly 23

    const greekWithEmoji = '🇬🇷 Κύπρος: Σημαντικές ανακοινώσεις σήμερα';
    const expectedGreekPoints = Array.from(greekWithEmoji).length;
    expect(getTwitterWeightedLength(greekWithEmoji)).toBe(expectedGreekPoints);

    // 2. sanitizeTwitterCopy smart trimming with Greek text, URL, and trailing hashtags exceeding 275 chars
    const greekArticleUrl = 'https://presshub.cy/mesimvrino-podcast-enimerosis-07-11-2026/';
    const trailingHashtags = '#Κυπρος #Ειδησεις #PressHub';
    const longGreekBody = 'Η κυβέρνηση της Κυπριακής Δημοκρατίας ανακοίνωσε σήμερα ένα εκτενές πακέτο μεταρρυθμίσεων που στοχεύει στον εκσυγχρονισμό των δημόσιων υπηρεσιών, την ενίσχυση της ψηφιακής διακυβέρνησης και την επιτάχυνση των διαδικασιών για όλους τους πολίτες και τις επιχειρήσεις σε όλη την επικράτεια. Το νέο πλαίσιο περιλαμβάνει λεπτομερείς κατευθυντήριες γραμμές και στρατηγικές παρεμβάσεις για την οικονομία.';
    const rawCopy = `${longGreekBody}\n\n${greekArticleUrl}\n\n${trailingHashtags}\n\npresshub.cy`;

    const sanitized = sanitizeTwitterCopy(rawCopy);

    // Verify budget enforcement
    expect(getTwitterWeightedLength(sanitized)).toBeLessThanOrEqual(TWITTER_MAX_WEIGHTED_CHARS);
    // Verify target URL is preserved
    expect(sanitized).toContain(greekArticleUrl);
    // Verify trailing hashtags are preserved
    expect(sanitized).toContain(trailingHashtags);
    // Verify ellipsis was added to trimmed body
    expect(sanitized).toContain('...');
    // Verify secondary domain reference outside target URL was normalized
    expect(sanitized.replace(greekArticleUrl, '')).not.toContain('presshub.cy');
  });

  test('postTwitter captures rest_id directly from an intercepted /i/api/graphql/.../CreateTweet response', async () => {
    clearSessionInvalid('twitter');
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          await page.route('**/i/api/graphql/*/CreateTweet', async (route) => {
            await route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({
                data: {
                  create_tweet: {
                    tweet_results: {
                      result: {
                        rest_id: '887766554433'
                      }
                    }
                  }
                }
              })
            });
          });

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
            </head>
            <body>
            <div role="dialog">
              <div data-testid="tweetTextarea_0" contenteditable="true"></div>
              <button id="post-btn" data-testid="tweetButton">Post</button>
            </div>
            <script>
              const area = document.querySelector('[data-testid="tweetTextarea_0"]');
              const triggerSend = () => {
                fetch('https://x.com/i/api/graphql/VaenaVgh5q5ih7kvyVjgtg/CreateTweet', {
                  method: 'POST',
                  headers: { 'content-type': 'application/json' },
                  body: JSON.stringify({})
                });
                const d = document.querySelector('[role="dialog"]');
                if (d) d.remove();
              };
              area.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                  triggerSend();
                }
              });
              document.getElementById('post-btn').addEventListener('click', triggerSend);
            </script>
          </body></html>`;

          page.goto = async (url: string) => {
            if (url && url.includes('/status/887766554433')) {
              await page.setContent('<html><body><article data-testid="tweet"><div>Intercepted rest_id tweet body</div></article></body></html>');
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Tweet with intercepted network response',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.externalPostId).toBe('887766554433');
    expect(res.externalPostUrl).toBe('https://x.com/presshubcy/status/887766554433');
  });

  test('postTwitter does not press Escape and successfully submits via Tier 1 keyboard shortcut in modal dialog', async () => {
    clearSessionInvalid('twitter');
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', domain: 'x.com', path: '/' }]);
          const page = await context.newPage();
          await page.route('**/i/api/graphql/*/CreateTweet', async (route) => {
            await route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({
                data: {
                  create_tweet: {
                    tweet_results: {
                      result: {
                        rest_id: '556677889900'
                      }
                    }
                  }
                }
              })
            });
          });

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
            </head>
            <body>
            <div data-slot="xds-modal-surface" role="dialog">
              <div data-testid="tweetTextarea_0" contenteditable="true"></div>
              <button id="post-btn" data-slot="xds-button">Post</button>
            </div>
            <script>
              window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                  // Simulate X opening "Save post?" modal and destroying composer focus
                  const discardModal = document.createElement('div');
                  discardModal.setAttribute('role', 'alertdialog');
                  discardModal.textContent = 'Save post? Discard / Save';
                  document.body.appendChild(discardModal);
                  const area = document.querySelector('[data-testid="tweetTextarea_0"]');
                  if (area) area.blur();
                }
              });
              const area = document.querySelector('[data-testid="tweetTextarea_0"]');
              area.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                  fetch('https://x.com/i/api/graphql/VaenaVgh5q5ih7kvyVjgtg/CreateTweet', {
                    method: 'POST',
                    headers: { 'content-type': 'application/json' },
                    body: JSON.stringify({})
                  });
                  const d = document.querySelector('[data-slot="xds-modal-surface"]');
                  if (d) d.remove();
                }
              });
            </script>
          </body></html>`;

          page.goto = async (url: string) => {
            if (url && url.includes('/status/556677889900')) {
              await page.setContent('<html><body><article data-testid="tweet"><div>Escape prevention verified</div></article></body></html>');
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Escape prevention and Tier 1 test tweet',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.externalPostId).toBe('556677889900');
    expect(res.externalPostUrl).toBe('https://x.com/presshubcy/status/556677889900');
  });

  test('postTwitter executes Tier 4 direct in-page CreateTweet GraphQL fallback when Tiers 1-3 do not fire network request', async () => {
    clearSessionInvalid('twitter');
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        cookies: async (urls?: any) => context.cookies(urls),
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', url: 'https://x.com' }]);
          const page = await context.newPage();
          await page.route('**/i/api/graphql/*/CreateTweet', async (route) => {
            await route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({
                data: {
                  create_tweet: {
                    tweet_results: {
                      result: {
                        rest_id: '998877665544'
                      }
                    }
                  }
                }
              })
            });
          });

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
              <script>document.cookie = "ct0=test_csrf_token_123; path=/";</script>
            </head>
            <body>
              <div role="dialog">
                <div data-testid="tweetTextarea_0" contenteditable="true"></div>
                <button id="post-btn" data-testid="tweetButton">Post</button>
              </div>
            </body>
          </html>`;

          page.goto = async (url: string) => {
            if (url && url.includes('/status/998877665544')) {
              await page.setContent('<html><body><article data-testid="tweet"><div>Tier 4 fallback tweet verified</div></article></body></html>');
              return null as any;
            }
            if (url && url.includes('/presshubcy')) {
              await page.setContent('<html><head><base href="https://x.com/"><script>document.cookie = "ct0=test_csrf_token_123; path=/";</script></head><body><div class="empty-timeline">No recent posts</div></body></html>');
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({
      platform: 'twitter',
      copy: 'Tier 4 GraphQL fallback tweet',
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
    expect(res.externalPostId).toBe('998877665544');
    expect(res.externalPostUrl).toBe('https://x.com/presshubcy/status/998877665544');
  });

  test('getTwitterSnowflakeTimestampMs decodes X status IDs and rejects non-numeric input', () => {
    // Real ID from job #21 (PR #125 log evidence): created ~11 minutes before that run started typing.
    expect(new Date(getTwitterSnowflakeTimestampMs('2118985289855090957')!).toISOString()).toBe('2026-11-07T08:33:56.601Z');
    expect(new Date(getTwitterSnowflakeTimestampMs('2118988395338764656')!).toISOString()).toBe('2026-11-07T08:46:17.006Z');
    const now = Date.now();
    expect(getTwitterSnowflakeTimestampMs(makeTwitterSnowflakeId(now))).toBe(now);
    expect(getTwitterSnowflakeTimestampMs('abc')).toBeUndefined();
    expect(getTwitterSnowflakeTimestampMs('')).toBeUndefined();
  });

  test('postTwitter ignores a matching timeline tweet created before the submission and falls through to Tier 4', async () => {
    clearSessionInvalid('twitter');
    const copy = 'Stale timeline tweet must not be reported as published';
    const staleId = makeTwitterSnowflakeId(Date.now() - 11 * 60 * 1000);
    const freshId = makeTwitterSnowflakeId(Date.now());
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        cookies: async (urls?: any) => context.cookies(urls),
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', url: 'https://x.com' }]);
          const page = await context.newPage();
          await page.route('**/i/api/graphql/*/CreateTweet', async (route) => {
            await route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({ data: { create_tweet: { tweet_results: { result: { rest_id: freshId } } } } })
            });
          });

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
              <script>document.cookie = "ct0=test_csrf_token_123; path=/";</script>
            </head>
            <body>
              <div role="dialog">
                <div data-testid="tweetTextarea_0" contenteditable="true"></div>
                <button id="post-btn" data-testid="tweetButton">Post</button>
              </div>
            </body>
          </html>`;

          page.goto = async (url: string) => {
            if (url && url.includes(`/status/${freshId}`)) {
              await page.setContent(`<html><body><article data-testid="tweet"><div>${copy}</div></article></body></html>`);
              return null as any;
            }
            if (url && url.includes('/presshubcy')) {
              // Same opening words as the new copy, but posted by an earlier attempt.
              await page.setContent(`<html><head><base href="https://x.com/"><script>document.cookie = "ct0=test_csrf_token_123; path=/";</script></head><body>
                <article data-testid="tweet">
                  <div>${copy}</div>
                  <a href="/presshubcy/status/${staleId}">11m</a>
                </article>
              </body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({ platform: 'twitter', copy, browserSession: mockSession });

    expect(res.success).toBe(true);
    expect(res.externalPostId).not.toBe(staleId);
    expect(res.externalPostId).toBe(freshId);
  });

  test('modalSurface anchors to dialog containing the composer when preceded by another dialog', async () => {
    const copy = 'Modal scoping anchor verification tweet';
    const tweetId = makeTwitterSnowflakeId(Date.now());
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        cookies: async (urls?: any) => context.cookies(urls),
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', url: 'https://x.com' }]);
          const page = await context.newPage();

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
              <script>document.cookie = "ct0=test_csrf_token_123; path=/";</script>
            </head>
            <body>
              <!-- Preceding dialog without composer (e.g. cookie or onboarding dialog) -->
              <div role="dialog" class="onboarding-dialog">
                <h2>Welcome to Twitter</h2>
                <button id="dismiss-onboarding">Dismiss</button>
              </div>
              <!-- Actual composer dialog -->
              <div role="dialog" class="composer-dialog">
                <div data-testid="tweetTextarea_0" contenteditable="true"></div>
                <button id="post-btn" data-testid="tweetButton">Post</button>
              </div>
              <script>
                document.getElementById('post-btn').addEventListener('click', () => {
                  const toast = document.createElement('div');
                  toast.setAttribute('data-testid', 'toast');
                  toast.innerHTML = '<a href="/user/status/${tweetId}">View</a>';
                  document.body.appendChild(toast);
                });
              </script>
            </body>
          </html>`;

          page.goto = async (url: string) => {
            if (url && url.includes(`/status/${tweetId}`)) {
              await page.setContent(`<html><body><article data-testid="tweet"><a href="/user/status/${tweetId}">time</a><div>${copy}</div></article></body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({ platform: 'twitter', copy, browserSession: mockSession });
    expect(res.success).toBe(true);
    expect(res.externalPostId).toBe(tweetId);
  });

  test('Twitter posting succeeds with plain article fallback on timeline and post-confirmation when data-testid="tweet" is absent', async () => {
    const copy = 'Plain article fallback timeline and verification';
    const freshId = makeTwitterSnowflakeId(Date.now());
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        cookies: async (urls?: any) => context.cookies(urls),
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', url: 'https://x.com' }]);
          const page = await context.newPage();

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
              <script>document.cookie = "ct0=test_csrf_token_123; path=/";</script>
            </head>
            <body>
              <div role="dialog">
                <div data-testid="tweetTextarea_0" contenteditable="true"></div>
                <button id="post-btn" data-testid="tweetButton">Post</button>
              </div>
            </body>
          </html>`;

          page.goto = async (url: string) => {
            if (url && url.includes(`/status/${freshId}`)) {
              // Post-confirmation with plain article lacking data-testid="tweet", but containing status link
              await page.setContent(`<html><body><article><div>${copy}</div><a href="/presshubcy/status/${freshId}">Timestamp</a></article></body></html>`);
              return null as any;
            }
            if (url && url.includes('/presshubcy')) {
              // Timeline with plain article lacking data-testid="tweet"
              await page.setContent(`<html><head><base href="https://x.com/"><script>document.cookie = "ct0=test_csrf_token_123; path=/";</script></head><body>
                <article>
                  <div>${copy}</div>
                  <a href="/presshubcy/status/${freshId}">Just now</a>
                </article>
              </body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postTwitter({ platform: 'twitter', copy, browserSession: mockSession });
    expect(res.success).toBe(true);
    expect(res.externalPostId).toBe(freshId);
  });

  test('Twitter post-confirmation fails if plain article lacks a link to status/${tweetId}', async () => {
    const copy = 'Unrelated article rejection test';
    const fakeId = makeTwitterSnowflakeId(Date.now());
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        cookies: async (urls?: any) => context.cookies(urls),
        newPage: async () => {
          await context.addCookies([{ name: 'ct0', value: 'test_csrf_token_123', url: 'https://x.com' }]);
          const page = await context.newPage();

          const composeHtml = `<html>
            <head>
              <base href="https://x.com/">
              <script>document.cookie = "ct0=test_csrf_token_123; path=/";</script>
            </head>
            <body>
              <div role="dialog">
                <div data-testid="tweetTextarea_0" contenteditable="true"></div>
                <button id="post-btn" data-testid="tweetButton">Post</button>
              </div>
              <script>
                document.getElementById('post-btn').addEventListener('click', () => {
                  const toast = document.createElement('div');
                  toast.setAttribute('data-testid', 'toast');
                  toast.innerHTML = '<a href="/user/status/${fakeId}">View</a>';
                  document.body.appendChild(toast);
                });
              </script>
            </body>
          </html>`;

          page.goto = async (url: string) => {
            if (url && url.includes(`/status/${fakeId}`)) {
              // Plain article with no status link and no data-testid="tweet" (e.g. promotional or unrelated article)
              await page.setContent(`<html><body><article><div>Generic article content with no status link</div></article></body></html>`);
              return null as any;
            }
            await page.setContent(composeHtml);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    await expect(postTwitter({ platform: 'twitter', copy, browserSession: mockSession })).rejects.toThrow(
      /Twitter post verification failed/
    );
  });
});
