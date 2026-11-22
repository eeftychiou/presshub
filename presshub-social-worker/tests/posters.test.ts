import fs from 'fs';
import path from 'path';
import { chromium, Browser, BrowserContext } from 'playwright';
import {
  postTwitter,
  postTikTok,
  postFacebook,
  postInstagram,
  selectInstagramCrop,
  dismissInstagramInterstitials,
  findInstagramComposerButton,
  resolveInstagramPermalink,
  resolveFacebookPermalink,
  parseFacebookPermalink,
  waitForFacebookVideoReady,
  isFacebookVideoFile,
  markTikTokAiGenerated,
  getTwitterWeightedLength,
  sanitizeTwitterCopy,
  getTwitterSnowflakeTimestampMs,
  makeTwitterSnowflakeId,
  TWITTER_URL_WEIGHT,
  TWITTER_MAX_WEIGHTED_CHARS,
  parseTikTokVideoHref,
  parseTikTokProfileHref,
  resolveTikTokPostUrl,
  TIKTOK_STUDIO_CONTENT_URL,
  splitTikTokCaption,
  typeTikTokCaption,
  fitInstagramCaption,
  INSTAGRAM_REEL_CAPTION_MAX_BYTES,
  waitForTwitterVideo,
  TWITTER_UPLOAD_PROGRESS_SELECTOR,
  TWITTER_VIDEO_READY_SELECTOR
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
          page.goto = async (url: string) => {
            if (url.includes('/me/')) {
              await page.setContent(`<html><body>
                <div role="article"><span>Older post</span><a href="https://www.facebook.com/PressHub.cy/posts/pfbid0OLD?__cft__[0]=x">1d</a></div>
                <div role="article"><span>Facebook post with strict mode prevention</span>
                  <a href="/PressHub.cy">PressHub.cy</a>
                  <a href="https://www.facebook.com/PressHub.cy/posts/pfbid02NEW123?__cft__[0]=AZ&amp;__tn__=%2CO">Just now</a></div>
              </body></html>`);
            } else {
              await page.setContent(html);
            }
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
    expect(res.externalPostUrl).toBe('https://www.facebook.com/PressHub.cy/posts/pfbid02NEW123');
    expect(res.externalPostId).toBe('pfbid02NEW123');
  }, 60000);

  test('postFacebook fails instead of reporting success when there is no Post button', async () => {
    const page = await context.newPage();
    page.goto = async () => {
      await page.setContent('<html><body><div role="textbox" contenteditable="true"></div></body></html>');
      return null as any;
    };
    const mockSession = { browser, isExternalCdp: false, context: { newPage: async () => page } as any, close: async () => {} } as ChromeConnection;
    await expect(postFacebook({ platform: 'facebook', copy: 'No button here', browserSession: mockSession, timeoutMs: 3000 }))
      .rejects.toThrow(/"Post" button not found/);
  }, 30000);

  test('postFacebook waits for a video upload, goes through Next and posts the Reel', async () => {
    const fs = require('fs');
    const os = require('os');
    const pathMod = require('path');
    const dir = fs.mkdtempSync(pathMod.join(os.tmpdir(), 'ph-fb-video-'));
    const video = pathMod.join(dir, 'short.mp4');
    fs.writeFileSync(video, 'video');
    const page = await context.newPage();
    page.goto = async (url: string) => {
      if (url.includes('/me/')) {
        await page.setContent(`<html><body><div role="article"><span>Reel caption text for the test</span>
          <a href="https://www.facebook.com/reel/1234567890123?__cft__=1">Just now</a></div></body></html>`);
      } else {
        // Upload "finishes" 1.5s after a file is chosen: the progress bar goes and Next is enabled.
        // Next swaps in the Post button.
        await page.setContent(`<html><body><div role="dialog">
          <div role="textbox" contenteditable="true"></div>
          <input type="file" accept="image/*,video/*" onchange="var d=document.querySelector('[role=dialog]');var p=document.createElement('div');p.setAttribute('role','progressbar');d.appendChild(p);setTimeout(function(){p.remove();document.getElementById('next').setAttribute('aria-disabled','false');},1500);" />
          <div id="next" role="button" aria-label="Next" aria-disabled="true" onclick="this.outerHTML='<div role=&quot;button&quot; aria-label=&quot;Post&quot; onclick=&quot;window.__posted=1&quot;>Post</div>'">Next</div>
        </div></body></html>`);
      }
      return null as any;
    };
    const mockSession = { browser, isExternalCdp: false, context: { newPage: async () => page } as any, close: async () => {} } as ChromeConnection;
    const res = await postFacebook({ platform: 'facebook', copy: 'Reel caption text for the test', mediaPath: video, browserSession: mockSession, timeoutMs: 5000, videoUploadTimeoutMs: 20000 });
    expect(res.success).toBe(true);
    expect(res.externalPostUrl).toBe('https://www.facebook.com/reel/1234567890123');
    expect(res.externalPostId).toBe('1234567890123');
    fs.rmSync(dir, { recursive: true, force: true });
  }, 60000);

  test('waitForFacebookVideoReady fails when the upload never finishes', async () => {
    const page = await context.newPage();
    try {
      await page.setContent('<html><body><div role="dialog"><div role="progressbar"></div><div role="button" aria-label="Post" aria-disabled="true">Post</div></div></body></html>');
      await expect(waitForFacebookVideoReady(page, 1500, 200)).rejects.toThrow(/did not finish uploading/);
      await page.setContent('<html><body><div role="dialog"><div role="button" aria-label="Post">Post</div></div></body></html>');
      await expect(waitForFacebookVideoReady(page, 1500, 200)).resolves.toBeUndefined();
    } finally {
      await page.close();
    }
  }, 30000);

  test('isFacebookVideoFile tells a rendered short from a post image', () => {
    expect(isFacebookVideoFile('/out/short-1.mp4')).toBe(true);
    expect(isFacebookVideoFile('C:\\out\\SHORT.MOV')).toBe(true);
    expect(isFacebookVideoFile('/out/image.jpg')).toBe(false);
    expect(isFacebookVideoFile('')).toBe(false);
  });

  test('parseFacebookPermalink keeps the post id and drops tracking parameters', () => {
    expect(parseFacebookPermalink('/PressHub.cy/posts/pfbid0abc?__cft__[0]=x&__tn__=R')).toEqual({
      url: 'https://www.facebook.com/PressHub.cy/posts/pfbid0abc',
      id: 'pfbid0abc'
    });
    expect(parseFacebookPermalink('https://www.facebook.com/permalink.php?story_fbid=123456789&id=61596712436152&__cft__=1')).toEqual({
      url: 'https://www.facebook.com/permalink.php?story_fbid=123456789&id=61596712436152',
      id: '123456789'
    });
    expect(parseFacebookPermalink('https://www.facebook.com/photo/?fbid=987654321&set=a.1')?.id).toBe('987654321');
    expect(parseFacebookPermalink('/PressHub.cy')).toBeNull();
    expect(parseFacebookPermalink('#')).toBeNull();
    expect(parseFacebookPermalink('https://evil.example.com/posts/123')).toBeNull();
  });

  test('resolveFacebookPermalink hovers the timestamp when its link is filled in on hover', async () => {
    const page = await context.newPage();
    try {
      page.goto = async () => {
        await page.setContent(`<html><body><div role="article"><span>Hover caption post text here</span>
          <a role="link" href="#" onmouseover="this.href='https://www.facebook.com/PressHub.cy/posts/pfbid0HOVER?__cft__=1'">2m</a></div></body></html>`);
        return null as any;
      };
      expect(await resolveFacebookPermalink(page, 'Hover caption post text here', 'https://www.facebook.com/me/', 5000)).toEqual({
        url: 'https://www.facebook.com/PressHub.cy/posts/pfbid0HOVER',
        id: 'pfbid0HOVER'
      });
      expect(await resolveFacebookPermalink(page, 'A caption nobody posted', 'https://www.facebook.com/me/', 2000)).toBeNull();
    } finally {
      await page.close();
    }
  }, 30000);

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
    // No real video link or profile link on the mock page: falls back to Studio's content list (#191).
    expect(res.externalPostUrl).toBe('https://www.tiktok.com/tiktokstudio/content');
    expect(res.externalPostUrl).not.toContain('@creator');
  });

  test('parseTikTokVideoHref / parseTikTokProfileHref read handles and video ids (#191)', () => {
    expect(parseTikTokVideoHref('https://www.tiktok.com/@presshub.cy/video/7312345678901234567')).toEqual({ handle: 'presshub.cy', videoId: '7312345678901234567', kind: 'video' });
    expect(parseTikTokVideoHref('/@presshub.cy/video/7312345678901234567?is_from_webapp=1')).toEqual({ handle: 'presshub.cy', videoId: '7312345678901234567', kind: 'video' });
    expect(parseTikTokVideoHref('https://www.tiktok.com/@creator/video/tt-123')).toBeNull();
    expect(parseTikTokProfileHref('/@presshub.cy')).toBe('presshub.cy');
    expect(parseTikTokProfileHref('https://www.tiktok.com/@presshub.cy?lang=el')).toBe('presshub.cy');
    expect(parseTikTokProfileHref('/@presshub.cy/video/7312345678901234567')).toBeNull();
  });

  function studioPage(html: string) {
    return context.newPage().then((page) => {
      page.goto = async () => {
        await page.setContent(html);
        return null as any;
      };
      return page;
    });
  }

  test('resolveTikTokPostUrl picks the Studio row whose caption matches (#191)', async () => {
    const page = await studioPage(`<html><body>
      <a href="/@presshub.cy">Profile</a>
      <div class="row"><a href="/@presshub.cy/video/7300000000000000002">open</a><span>Κάτι άλλο παλιό</span></div>
      <div class="row"><a href="/@presshub.cy/video/7300000000000000001">open</a><span>Σήμερα στην Κύπρο: τα νέα της ημέρας</span></div>
    </body></html>`);
    const res = await resolveTikTokPostUrl(page, 'Σήμερα στην Κύπρο: τα νέα της ημέρας #cyprus', 3000);
    expect(res).toEqual({ kind: 'video', url: 'https://www.tiktok.com/@presshub.cy/video/7300000000000000001', videoId: '7300000000000000001' });
    await page.close();
  });

  test('resolveTikTokPostUrl reports a photo post as a photo (#333)', async () => {
    const page = await studioPage(`<html><body>
      <div class="row"><a href="/@presshub.cy/photo/7300000000000000003">open</a><span>Φωτογραφίες της ημέρας #news</span></div>
    </body></html>`);
    const res = await resolveTikTokPostUrl(page, 'Φωτογραφίες της ημέρας #news', 3000);
    expect(res).toEqual({ kind: 'photo', url: 'https://www.tiktok.com/@presshub.cy/photo/7300000000000000003', videoId: '7300000000000000003' });
    await page.close();
  });

  test('resolveTikTokPostUrl falls back to the profile when no row matches (#191)', async () => {
    const page = await studioPage(`<html><body>
      <a href="/@presshub.cy">Profile</a>
      <div class="row"><a href="/@presshub.cy/video/7300000000000000002">open</a><span>Κάτι άλλο παλιό</span></div>
    </body></html>`);
    const res = await resolveTikTokPostUrl(page, 'Νέο βίντεο που δεν φαίνεται ακόμη', 2000);
    expect(res).toEqual({ kind: 'profile', url: 'https://www.tiktok.com/@presshub.cy' });
    await page.close();
  });

  test('resolveTikTokPostUrl falls back to Studio content when nothing is known (#191)', async () => {
    const page = await studioPage('<html><body><p>Loading</p></body></html>');
    const res = await resolveTikTokPostUrl(page, 'caption', 1000);
    expect(res).toEqual({ kind: 'studio', url: TIKTOK_STUDIO_CONTENT_URL });
    await page.close();
  });

  test('splitTikTokCaption separates text, line breaks and hashtags in any script', () => {
    expect(splitTikTokCaption('Νέα στην Κύπρο\n\n#κύπρος #cyprus C#')).toEqual([
      { kind: 'text', value: 'Νέα στην Κύπρο' },
      { kind: 'newline', value: '\n' },
      { kind: 'newline', value: '\n' },
      { kind: 'hashtag', value: '#κύπρος' },
      { kind: 'text', value: ' ' },
      { kind: 'hashtag', value: '#cyprus' },
      { kind: 'text', value: ' C#' },
    ]);
  });

  // A stand-in for TikTok Studio's caption editor: typing '#tag' opens a suggestion list;
  // picking an entry turns the typed tag into a hashtag (a <span class="hashtag">).
  const TIKTOK_EDITOR_MOCK = `<html><body>
    <div id="ed" contenteditable="true" style="min-height:40px"></div>
    <div id="pop" class="mention-list-popover" style="display:none"></div>
    <script>
      const ed = document.getElementById('ed');
      const pop = document.getElementById('pop');
      // Suggestions TikTok would show for a typed tag: the exact tag first, except for #rare.
      const suggest = (t) => t === 'rare' ? ['#rarebits', '#rarely'] : t === 'second' ? ['#secondhand', '#second'] : ['#' + t, '#' + t + 'news'];
      const current = () => { const m = ed.innerText.match(/#([\\p{L}\\p{N}_]+)$/u); return m ? m[1] : null; };
      const pick = (tag) => {
        const t = current();
        ed.innerHTML = ed.innerHTML.replace(new RegExp('#' + t + '$', 'u'), '<span class="hashtag">' + tag + '</span>&nbsp;');
        pop.style.display = 'none';
        const r = document.createRange(); r.selectNodeContents(ed); r.collapse(false);
        const s = getSelection(); s.removeAllRanges(); s.addRange(r);
      };
      ed.addEventListener('input', () => {
        const t = current();
        if (!t) { pop.style.display = 'none'; return; }
        pop.innerHTML = suggest(t).map(x => '<div class="hashtag-item">' + x + ' 1.2M views</div>').join('');
        pop.querySelectorAll('.hashtag-item').forEach(el => el.onclick = () => pick(el.textContent.split(' ')[0]));
        pop.style.display = 'block';
      });
      ed.addEventListener('keydown', (e) => {
        if (pop.style.display === 'none') return;
        if (e.key === 'Enter') { e.preventDefault(); pick(pop.querySelector('.hashtag-item').textContent.split(' ')[0]); }
        if (e.key === 'Escape') { pop.style.display = 'none'; }
      });
    </script>
  </body></html>`;

  test('typeTikTokCaption picks each hashtag from the suggestion list so TikTok links it', async () => {
    const page = await context.newPage();
    await page.setContent(TIKTOK_EDITOR_MOCK);
    await page.click('#ed');
    const res = await typeTikTokCaption(page, 'Η είδηση της ημέρας. #κύπρος #cyprus #second', 1500);
    expect(res).toEqual({ hashtags: 3, linked: 3 });
    const tags = await page.$$eval('#ed .hashtag', els => els.map(e => e.textContent));
    expect(tags).toEqual(['#κύπρος', '#cyprus', '#second']);
    expect(await page.innerText('#ed')).toContain('Η είδηση της ημέρας.');
    await page.close();
  });

  test('splitTikTokCaption keeps a bare number like #2026 as text', () => {
    expect(splitTikTokCaption('Έτος #2026 #κύπρος')).toEqual([
      { kind: 'text', value: 'Έτος #2026 ' },
      { kind: 'hashtag', value: '#κύπρος' },
    ]);
  });

  test('typeTikTokCaption keeps the space between tags when a pick adds none', async () => {
    const page = await context.newPage();
    await page.setContent(TIKTOK_EDITOR_MOCK.replace("'</span>&nbsp;'", "'</span>'"));
    await page.click('#ed');
    const res = await typeTikTokCaption(page, '#κύπρος #cyprus', 1500);
    expect(res).toEqual({ hashtags: 2, linked: 2 });
    expect((await page.innerText('#ed')).trim()).toBe('#κύπρος #cyprus');
    await page.close();
  });

  test('typeTikTokCaption leaves a tag as typed when the list does not offer it', async () => {
    const page = await context.newPage();
    await page.setContent(TIKTOK_EDITOR_MOCK);
    await page.click('#ed');
    const res = await typeTikTokCaption(page, 'Κείμενο #rare', 1500);
    expect(res).toEqual({ hashtags: 1, linked: 0 });
    expect(await page.$$eval('#ed .hashtag', els => els.length)).toBe(0);
    expect((await page.innerText('#ed')).trim()).toBe('Κείμενο #rare');
    await page.close();
  });

  test('typeTikTokCaption still types the caption when no suggestion list appears', async () => {
    const page = await context.newPage();
    await page.setContent('<html><body><div id="ed" contenteditable="true"></div></body></html>');
    await page.click('#ed');
    const res = await typeTikTokCaption(page, 'Λεζάντα #κύπρος #ειδήσεις', 300);
    expect(res).toEqual({ hashtags: 2, linked: 0 });
    expect((await page.innerText('#ed')).trim()).toBe('Λεζάντα #κύπρος #ειδήσεις');
    await page.close();
  });

  test('markTikTokAiGenerated opens "Show more", turns the AI label on and confirms it', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <button id="more">Show more</button>
      <div id="opts" style="display:none">
        <div class="row"><span>Allow comments</span><div role="switch" aria-checked="true" style="width:40px;height:20px"></div></div>
        <div class="row"><span>AI-generated content</span><div id="ai" role="switch" aria-checked="false" style="width:40px;height:20px"></div></div>
      </div>
      <script>
        document.getElementById('more').onclick = () => { document.getElementById('opts').style.display = 'block'; };
        document.getElementById('ai').onclick = () => {
          const d = document.createElement('div');
          d.setAttribute('role', 'dialog');
          d.innerHTML = '<p>Label as AI-generated?</p><button id="ok">Turn on</button>';
          document.body.appendChild(d);
          document.getElementById('ok').onclick = () => { document.getElementById('ai').setAttribute('aria-checked', 'true'); d.remove(); };
        };
      </script>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(true);
    expect(await page.getAttribute('#ai', 'aria-checked')).toBe('true');
    // Already on: nothing is clicked again.
    expect(await markTikTokAiGenerated(page)).toBe(true);
    await page.close();
  });

  test('markTikTokAiGenerated reports false instead of failing when the switch is missing', async () => {
    const page = await context.newPage();
    await page.setContent('<html><body><div contenteditable="true"></div><button>Post</button></body></html>');
    expect(await markTikTokAiGenerated(page)).toBe(false);
    await page.close();
  });

  test('markTikTokAiGenerated expands TikTok Studio div.more-btn "Show more" and toggles Switch__content inside aigc_container', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <div class="card">
        <div class="options-form" style="display:none">
          <div data-e2e="disclose_content_container" class="container">
            <span>Disclose post content</span>
            <div class="Switch__root" data-layout="switch-root">
              <div class="Switch__content Switch__content--checked-false" aria-checked="false" data-state="unchecked" style="width:28px;height:16px">
                <span data-part="thumb" class="Switch__thumb"></span>
                <input role="switch" type="checkbox" aria-hidden="true" class="Switch__input" style="appearance:none;width:28px;height:16px">
              </div>
            </div>
          </div>
          <div data-e2e="aigc_container" class="container">
            <span>AI-generated content</span>
            <div class="Tooltip__root">
              <div class="switch">
                <div class="Switch__root" data-layout="switch-root">
                  <div id="aigc-content" class="Switch__content Switch__content--checked-false" aria-checked="false" data-state="unchecked" style="width:28px;height:16px">
                    <span data-part="thumb" data-state="unchecked" class="Switch__thumb"></span>
                    <input id="aigc-input" role="switch" type="checkbox" aria-hidden="true" class="Switch__input" style="appearance:none;width:28px;height:16px">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div data-e2e="advanced_settings_container" class="more-collapse collapsed">
          <div id="more-btn" class="more-btn"><span>Show more</span></div>
        </div>
      </div>
      <script>
        document.getElementById('more-btn').onclick = () => {
          document.querySelector('.options-form').style.display = 'block';
          document.querySelector('.more-collapse').classList.remove('collapsed');
        };
        document.getElementById('aigc-content').onclick = () => {
          const d = document.createElement('div');
          d.setAttribute('role', 'dialog');
          d.innerHTML = '<h2>Labelling AI-generated content</h2><button id="turn-on">Turn on</button>';
          document.body.appendChild(d);
          document.getElementById('turn-on').onclick = () => {
            const c = document.getElementById('aigc-content');
            c.setAttribute('aria-checked', 'true');
            c.setAttribute('data-state', 'checked');
            document.getElementById('aigc-input').checked = true;
            d.remove();
          };
        };
      </script>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(true);
    expect(await page.getAttribute('#aigc-content', 'aria-checked')).toBe('true');
    // Re-running when already enabled returns true without toggling off.
    expect(await markTikTokAiGenerated(page)).toBe(true);
    await page.close();
  });

  test('markTikTokAiGenerated skips hidden checkbox and clicks visible [role="switch"]', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <div class="row">
        <span>AI-generated content</span>
        <input type="checkbox" style="display:none" />
        <div id="visible-sw" role="switch" aria-checked="false" style="width:28px;height:16px;display:inline-block"></div>
      </div>
      <script>
        document.getElementById('visible-sw').onclick = () => {
          document.getElementById('visible-sw').setAttribute('aria-checked', 'true');
        };
      </script>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(true);
    expect(await page.getAttribute('#visible-sw', 'aria-checked')).toBe('true');
    await page.close();
  });

  test('markTikTokAiGenerated returns false cleanly when switch remains hidden after Show more', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <div class="options-form" style="display:none">
        <div data-e2e="aigc_container">
          <span>AI-generated content</span>
          <input role="switch" type="checkbox" />
        </div>
      </div>
      <div data-e2e="advanced_settings_container"><div class="more-btn"><span>Show more</span></div></div>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(false);
    await page.close();
  });

  test('markTikTokAiGenerated ignores an earlier decoy "Show more" and clicks advanced_settings_container .more-btn', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <div class="caption-help">
        <span id="decoy">Show more</span>
        <button id="decoy-btn">Show more</button>
      </div>
      <div class="options-form" style="display:none">
        <div data-e2e="aigc_container">
          <span>AI-generated content</span>
          <div class="Switch__root">
            <div id="ai-sw" class="Switch__content" aria-checked="false" style="width:28px;height:16px"></div>
          </div>
        </div>
      </div>
      <div data-e2e="advanced_settings_container" class="more-collapse collapsed">
        <div id="real-more" class="more-btn"><span>Show more</span></div>
      </div>
      <script>
        window.__decoyClicked = false;
        document.getElementById('decoy').onclick = () => { window.__decoyClicked = true; };
        document.getElementById('decoy-btn').onclick = () => { window.__decoyClicked = true; };
        document.getElementById('real-more').onclick = () => {
          document.querySelector('.options-form').style.display = 'block';
          document.querySelector('.more-collapse').classList.remove('collapsed');
        };
        document.getElementById('ai-sw').onclick = () => {
          document.getElementById('ai-sw').setAttribute('aria-checked', 'true');
        };
      </script>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(true);
    expect(await page.evaluate(() => (globalThis as any).__decoyClicked)).toBe(false);
    expect(await page.getAttribute('#ai-sw', 'aria-checked')).toBe('true');
    await page.close();
  });

  test('markTikTokAiGenerated does not collapse an already-expanded section and waits for lazy switch mount', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <div id="form" class="options-form"></div>
      <div data-e2e="advanced_settings_container" class="more-collapse">
        <div id="less-btn" class="more-btn"><span>Show less</span></div>
      </div>
      <script>
        window.__lessClicked = false;
        document.getElementById('less-btn').onclick = () => { window.__lessClicked = true; };
        setTimeout(() => {
          document.getElementById('form').innerHTML =
            '<div data-e2e="aigc_container"><span>AI-generated content</span>' +
            '<div id="lazy-sw" role="switch" aria-checked="false" style="width:28px;height:16px"></div></div>';
          document.getElementById('lazy-sw').onclick = () => {
            document.getElementById('lazy-sw').setAttribute('aria-checked', 'true');
          };
        }, 300);
      </script>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(true);
    expect(await page.evaluate(() => (globalThis as any).__lessClicked)).toBe(false);
    expect(await page.getAttribute('#lazy-sw', 'aria-checked')).toBe('true');
    await page.close();
  });

  test('markTikTokAiGenerated isOn() does not false-positive on a neighbouring checked control in the same row', async () => {
    const page = await context.newPage();
    await page.setContent(`<html><body>
      <div class="row">
        <span>AI-generated content</span>
        <div id="neighbour" role="switch" aria-checked="true" style="width:28px;height:16px;display:none"></div>
        <div id="target-sw" role="switch" aria-checked="false" style="width:28px;height:16px"></div>
      </div>
      <script>
        document.getElementById('target-sw').onclick = () => {
          document.getElementById('target-sw').setAttribute('aria-checked', 'true');
        };
      </script>
    </body></html>`);
    expect(await markTikTokAiGenerated(page)).toBe(true);
    expect(await page.getAttribute('#target-sw', 'aria-checked')).toBe('true');
    await page.close();
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
    // No account name in this composer, so the link can't be looked up: no made-up URL.
    expect(res.externalPostUrl).toBe('');
    expect(res.externalPostId).toBe('');
  });

  test('postInstagram reports the real permalink of the new post from the profile', async () => {
    const page = await context.newPage();
    try {
      const now = Date.now();
      const iso = (ms: number) => new Date(ms).toISOString();
      const copy = 'Πρωινή Ενημέρωση:   Συνταξιοδοτικό\nκαι αναμονή ΟΗΕ #PressHub';
      const composer = `<html><body>
        <a href="#" role="link" onclick="document.getElementById('c').style.display='block'; document.getElementById('up').innerHTML='<input type=file>'; return false;"><svg aria-label="Post" width="24" height="24"></svg>Post</a>
        <div id="c" style="display:none"><div role="dialog" aria-label="Create new post">
          <div id="up"></div>
          <img alt="presshub.cy's profile picture" src="data:," /><span>presshub.cy</span>
          <div aria-label="Write a caption..." contenteditable="true"></div>
          <div role="button" onclick="document.getElementById('ok').style.display='block'">Share</div>
          <div id="ok" style="display:none">Your post has been shared.</div>
        </div></div></body></html>`;
      const profile = `<html><body>
        <a href="/presshub.cy/p/PINNED1/"><svg aria-label="Pinned post icon"></svg></a>
        <a href="/presshub.cy/reel/OTHERNEW/"><svg aria-label="Clip"></svg></a>
        <a href="/presshub.cy/p/MINE123/"></a>
        <a href="/presshub.cy/p/OLD999/"></a></body></html>`;
      const post = (caption: string, ms: number) =>
        `<html><head><meta property="og:title" content="Presshub.cy on Instagram: &quot;${caption}&quot;"></head><body><time datetime="${iso(ms)}">now</time></body></html>`;
      const pages: Record<string, string> = {
        'https://www.instagram.com/presshub.cy/': profile,
        'https://www.instagram.com/presshub.cy/p/PINNED1/': post('Pinned welcome post', now - 86400000 * 30),
        'https://www.instagram.com/presshub.cy/reel/OTHERNEW/': post('A different reel that posted moments ago', now + 1000),
        'https://www.instagram.com/presshub.cy/p/MINE123/': post('Πρωινή Ενημέρωση: Συνταξιοδοτικό και αναμονή ΟΗΕ #PressHub', now + 2000),
        'https://www.instagram.com/presshub.cy/p/OLD999/': post('Old post', now - 86400000)
      };
      const visited: string[] = [];
      const mockSession: ChromeConnection = {
        browser,
        isExternalCdp: false,
        context: {
          newPage: async () => {
            page.goto = async (url: string) => {
              visited.push(url);
              await page.setContent(pages[url] || composer);
              return null as any;
            };
            page.close = async () => {};
            return page;
          }
        } as any,
        close: async () => {}
      };

      const res = await postInstagram({ platform: 'instagram', copy, mediaPath: dummyImagePath, browserSession: mockSession });

      expect(res.success).toBe(true);
      expect(res.externalPostUrl).toBe('https://www.instagram.com/presshub.cy/p/MINE123/');
      expect(res.externalPostId).toBe('MINE123');
      expect(visited).not.toContain('https://www.instagram.com/presshub.cy/p/PINNED1/');
      expect(visited).not.toContain('https://www.instagram.com/presshub.cy/p/OLD999/');
    } finally {
      await page.close();
    }
  }, 90000);

  test('resolveInstagramPermalink returns null when no recent post has the caption', async () => {
    const page = await context.newPage();
    try {
      const now = Date.now();
      page.goto = async (url: string) => {
        if (url.endsWith('/presshub.cy/')) {
          await page.setContent('<html><body><a href="/presshub.cy/p/AAA/"></a></body></html>');
        } else {
          await page.setContent(`<html><head><meta property="og:title" content="Someone else's text"></head><body><time datetime="${new Date(now).toISOString()}"></time></body></html>`);
        }
        return null as any;
      };
      expect(await resolveInstagramPermalink(page, 'My caption that was shared', now, 'presshub.cy')).toBeNull();
      expect(await resolveInstagramPermalink(page, 'My caption', now, null)).toBeNull();
    } finally {
      await page.close();
    }
  }, 60000);

  test('selectInstagramCrop finds the "Select Crop" label used by Google Chrome', async () => {
    const page = await context.newPage();
    try {
      await page.setContent(`<html><body><div role="dialog" aria-label="Crop">
        <div role="button" onclick="document.getElementById('m').style.display='block'"><svg aria-label="Select Crop" width="24" height="24"></svg></div>
        <div id="m" style="display:none">
          <div role="button" onclick="window.__crop='1:1'"><span>1:1</span></div>
          <div role="button" onclick="window.__crop='9:16'"><span>9:16</span></div>
        </div></div></body></html>`);
      expect(await selectInstagramCrop(page, ['9:16', 'Original'])).toBe('9:16');
      expect(await page.evaluate('window.__crop')).toBe('9:16');
    } finally {
      await page.close();
    }
  });

  test('postInstagram for video dismisses exact dialog OK button and requires share confirmation', async () => {
    const fsMod = require('fs');
    const osMod = require('os');
    const pathMod = require('path');
    const dummyVideo = pathMod.join(osMod.tmpdir(), `ig-reel-test-${Date.now()}.mp4`);
    fsMod.writeFileSync(dummyVideo, 'fake-mp4');

    try {
      const mockSession: ChromeConnection = {
        browser,
        isExternalCdp: false,
        context: {
          newPage: async () => {
            const page = await context.newPage();
            const html = `<html><body>
              <button id="fb-btn" onclick="window.__fbClicked = true">Facebook</button>
              <a role="link">Create</a>
              <input type="file" />
              <div role="dialog" id="reels-dialog">
                <p>Video posts are now shared as reels</p>
                <button id="ok-btn" onclick="document.getElementById('reels-dialog').style.display='none'; window.__okClicked = true;">OK</button>
              </div>
              <button>Next</button>
              <div aria-label="Write a caption" contenteditable="true"></div>
              <button onclick="document.getElementById('confirm').style.display='block'">Share</button>
              <div id="confirm" style="display:none">Your reel has been shared.</div>
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
        copy: 'Instagram Reel caption',
        mediaPath: dummyVideo,
        browserSession: mockSession
      });

      expect(res.success).toBe(true);
      expect(res.platform).toBe('instagram');
    } finally {
      if (fsMod.existsSync(dummyVideo)) fsMod.unlinkSync(dummyVideo);
    }
  });

  test('postInstagram picks the 9:16 crop for a Reel before Next', async () => {
    const page = await context.newPage();
    try {
      const mockSession: ChromeConnection = {
        browser,
        isExternalCdp: false,
        context: {
          newPage: async () => {
            const html = `<html><body>
              <a href="#" role="link"><svg aria-label="Post"></svg><span>Post</span></a>
              <input type="file" accept="video/mp4" />
              <div role="dialog">
                <div role="button" id="crop" onclick="document.getElementById('crop-menu').style.display='block'"><svg aria-label="Select crop"></svg></div>
                <div id="crop-menu" style="display:none">
                  <div role="button" onclick="window.__crop='Original'"><span>Original</span></div>
                  <div role="button" onclick="window.__crop='1:1'"><span>1:1</span></div>
                  <div role="button" onclick="window.__crop='9:16'"><span>9:16</span></div>
                  <div role="button" onclick="window.__crop='16:9'"><span>16:9</span></div>
                </div>
                <button onclick="window.__nextCropAtClick = window.__nextCropAtClick || window.__crop || 'none'; this.remove();">Next</button>
                <div aria-label="Write a caption" contenteditable="true"></div>
                <button onclick="document.getElementById('confirm').style.display='block'">Share</button>
              </div>
              <div id="confirm" style="display:none">Your reel has been shared.</div>
            </body></html>`;
            page.goto = async () => {
              await page.setContent(html);
              return null as any;
            };
            page.close = async () => {};
            return page;
          }
        } as any,
        close: async () => {}
      };

      const res = await postInstagram({
        platform: 'instagram',
        copy: 'Reel with vertical crop',
        mediaPath: dummyVideoPath,
        browserSession: mockSession
      });

      expect(res.success).toBe(true);
      expect(await page.evaluate('window.__crop')).toBe('9:16');
      expect(await page.evaluate('window.__nextCropAtClick')).toBe('9:16');
    } finally {
      await page.close();
    }
  });

  test('selectInstagramCrop returns null and does not throw when the crop menu is missing', async () => {
    const page = await context.newPage();
    try {
      await page.setContent('<html><body><div role="dialog"><button>Next</button></div></body></html>');
      const picked = await selectInstagramCrop(page, ['9:16', 'Original']);
      expect(picked).toBeNull();
    } finally {
      await page.close();
    }
  }, 20000);

  test('selectInstagramCrop returns null and does not throw when an overlay blocks the crop button', async () => {
    const page = await context.newPage();
    try {
      await page.setContent(`<html><body><div role="dialog">
        <div role="button" style="position:absolute;top:10px;left:10px;width:40px;height:40px"><svg aria-label="Select crop" width="40" height="40"></svg></div>
        <div style="position:fixed;inset:0;z-index:10;background:transparent"></div>
      </div></body></html>`);
      const picked = await selectInstagramCrop(page, ['9:16', 'Original']);
      expect(picked).toBeNull();
    } finally {
      await page.close();
    }
  }, 30000);

  test('postInstagram opens the composer through the "Post" entry of the current Create menu', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <a href="#" role="link" id="create"><svg aria-label="Create"></svg><span>Create</span></a>
            <div id="menu" style="display:none">
              <a href="#" id="post-entry" onclick="window.__postClicked = true; document.getElementById('composer').innerHTML = '<input type=file accept=video/mp4>'; return false;"><svg aria-label="Post"></svg><span>Post</span></a>
              <a href="#"><svg aria-label="Live video"></svg><span>Live video</span></a>
            </div>
            <div id="composer"></div>
            <script>document.getElementById('create').addEventListener('click', (e) => { e.preventDefault(); document.getElementById('menu').style.display = 'block'; });</script>
            <button>Next</button>
            <div aria-label="Write a caption" contenteditable="true"></div>
            <button>Share</button>
          </body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          (mockSession as any).__page = page;
          return page;
        }
      } as any,
      close: async () => {}
    };

    const res = await postInstagram({
      platform: 'instagram',
      copy: 'Instagram caption via Post entry',
      mediaPath: dummyImagePath,
      browserSession: mockSession
    });

    expect(res.success).toBe(true);
  });

  test('postInstagram clicks the composer Share, not the feed Share icons behind the modal', async () => {
    const page = await context.newPage();
    try {
      // Live DOM from 16/11/2026: feed posts behind the modal have div[role=button] Share
      // icons (svg title "Share"); the composer dialog has "Share to" and the real "Share".
      // The backdrop covers the feed, so clicking a feed icon is intercepted.
      const html = `<html><body style="margin:0">
        <a href="#" role="link" onclick="document.getElementById('composer').style.display='block'; document.getElementById('upload').innerHTML = '<input type=file accept=image/jpeg>'; return false;"><svg aria-label="Post" width="24" height="24"></svg>Post</a>
        <main>
          <div role="button" onclick="window.__feedShare = true"><svg aria-label="Share" width="24" height="24"><title>Share</title></svg></div>
          <div role="button" onclick="window.__feedShare = true"><svg aria-label="Share" width="24" height="24"><title>Share</title></svg></div>
        </main>
        <div id="composer" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10">
          <div role="dialog" aria-label="Create new post" style="background:#fff;margin:40px auto;width:600px;padding:12px">
            <div id="upload"></div>
            <div role="button" id="next1" onclick="this.remove(); document.getElementById('next2').style.display='inline-block'">Next</div>
            <div role="button" id="next2" style="display:none" onclick="this.remove(); document.getElementById('caption-step').style.display='block'">Next</div>
            <div id="caption-step" style="display:none">
              <div role="button" onclick="window.__composerShare = true; document.getElementById('confirm').style.display='block'">Share</div>
              <div aria-label="Write a caption…" contenteditable="true"></div>
              <div role="button" onclick="window.__shareTo = true"><span>Share to</span></div>
            </div>
            <div id="confirm" style="display:none">Your post has been shared.</div>
          </div>
        </div>
      </body></html>`;
      const mockSession: ChromeConnection = {
        browser,
        isExternalCdp: false,
        context: {
          newPage: async () => {
            page.goto = async () => {
              await page.setContent(html);
              return null as any;
            };
            page.close = async () => {};
            return page;
          }
        } as any,
        close: async () => {}
      };

      const res = await postInstagram({
        platform: 'instagram',
        copy: 'Card caption',
        mediaPath: dummyImagePath,
        browserSession: mockSession
      });

      expect(res.success).toBe(true);
      expect(await page.evaluate('window.__composerShare')).toBe(true);
      expect(await page.evaluate('window.__feedShare')).toBeUndefined();
      expect(await page.evaluate('window.__shareTo')).toBeUndefined();
    } finally {
      await page.close();
    }
  }, 60000);

  test('findInstagramComposerButton ignores matching buttons outside an open dialog', async () => {
    const page = await context.newPage();
    try {
      await page.setContent(`<html><body>
        <div role="button"><svg aria-label="Share"><title>Share</title></svg></div>
        <div role="dialog"><div role="button">Share to</div></div>
      </body></html>`);
      expect(await findInstagramComposerButton(page, 'Share', 600)).toBeNull();
      await page.setContent('<html><body><button>Share</button></body></html>');
      expect(await findInstagramComposerButton(page, 'Share', 600)).not.toBeNull();
    } finally {
      await page.close();
    }
  });

  test('postInstagram dismisses the "Turn on notifications" dialog that blocks the Post entry', async () => {
    const page = await context.newPage();
    try {
      // Live DOM from 16/11/2026: a full-screen modal covers the sidebar, so a click on
      // "Post" is intercepted until "Not Now" is chosen.
      const html = `<html><body style="margin:0">
        <a href="#" role="link" id="post-entry" onclick="window.__postClicked = true; document.getElementById('composer').innerHTML = '<input type=file accept=image/jpeg>'; return false;"><svg aria-label="Post" width="24" height="24"></svg><span>Post</span></a>
        <div id="composer"></div>
        <div id="overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10">
          <div role="dialog" style="background:#fff;margin:100px auto;width:400px;padding:20px">
            <h2>Turn on notifications</h2>
            <button onclick="window.__turnedOn = true">Turn On</button>
            <button onclick="document.getElementById('overlay').remove(); window.__notNow = true;">Not Now</button>
          </div>
        </div>
        <button>Next</button>
        <div aria-label="Write a caption" contenteditable="true"></div>
        <button>Share</button>
      </body></html>`;
      const mockSession: ChromeConnection = {
        browser,
        isExternalCdp: false,
        context: {
          newPage: async () => {
            page.goto = async () => {
              await page.setContent(html);
              return null as any;
            };
            page.close = async () => {};
            return page;
          }
        } as any,
        close: async () => {}
      };

      const res = await postInstagram({
        platform: 'instagram',
        copy: 'Caption behind a notifications prompt',
        mediaPath: dummyImagePath,
        browserSession: mockSession
      });

      expect(res.success).toBe(true);
      expect(await page.evaluate('window.__notNow')).toBe(true);
      expect(await page.evaluate('window.__turnedOn')).toBeUndefined();
      expect(await page.evaluate('window.__postClicked')).toBe(true);
    } finally {
      await page.close();
    }
  }, 60000);

  test('dismissInstagramInterstitials clicks "Not now" on the save-login dialog and nothing else', async () => {
    const page = await context.newPage();
    try {
      await page.setContent(`<html><body>
        <div role="dialog" id="d"><h2>Save your login info?</h2>
          <button onclick="window.__saved = true">Save info</button>
          <button onclick="document.getElementById('d').remove()">Not now</button>
        </div></body></html>`);
      const clicked = await dismissInstagramInterstitials(page);
      expect(clicked).toEqual(['Not now']);
      expect(await page.evaluate('window.__saved')).toBeUndefined();
      expect(await dismissInstagramInterstitials(page)).toEqual([]);
    } finally {
      await page.close();
    }
  });

  test('postInstagram names the blocking dialog when the composer still cannot open', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <a href="#" role="link"><svg aria-label="Post" width="24" height="24"></svg>Post</a>
            <div style="position:fixed;inset:0;z-index:10;background:#000a"><div role="dialog" style="background:#fff;margin:80px auto;width:300px">Confirm it's you<button>Continue</button></div></div>
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

    await expect(
      postInstagram({
        platform: 'instagram',
        copy: 'Blocked',
        mediaPath: dummyImagePath,
        timeoutMs: 2000,
        browserSession: mockSession
      })
    ).rejects.toThrow(/composer did not open.*dialog is still open: "Confirm it's you/);
  }, 60000);

  test('postInstagram fails clearly when the composer never opens', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body><a href="#"><svg aria-label="Live video"></svg>Live video</a></body></html>`;
          page.goto = async () => {
            await page.setContent(html);
            return null as any;
          };
          return page;
        }
      } as any,
      close: async () => {}
    };

    await expect(
      postInstagram({
        platform: 'instagram',
        copy: 'No composer',
        mediaPath: dummyImagePath,
        timeoutMs: 2000,
        browserSession: mockSession
      })
    ).rejects.toThrow(/composer did not open/);
  });

  test('postInstagram throws when Share button is missing (Zero-Silent-Fallback)', async () => {
    const mockSession: ChromeConnection = {
      browser,
      isExternalCdp: false,
      context: {
        newPage: async () => {
          const page = await context.newPage();
          const html = `<html><body>
            <input type="file" />
            <div aria-label="Write a caption" contenteditable="true"></div>
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

    await expect(
      postInstagram({
        platform: 'instagram',
        copy: 'Caption without share button',
        mediaPath: dummyImagePath,
        timeoutMs: 2000,
        browserSession: mockSession
      })
    ).rejects.toThrow();
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

describe('postMediaFiles', () => {
  const { postMediaFiles } = require('../src/automation/posters');
  const os = require('os');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ph-media-files-'));
  const a = path.join(dir, 'a.png');
  const b = path.join(dir, 'b.png');
  fs.writeFileSync(a, 'a');
  fs.writeFileSync(b, 'b');
  afterAll(() => fs.rmSync(dir, { recursive: true, force: true }));

  test('prefers mediaPaths, skips missing files and caps the count', () => {
    expect(postMediaFiles({ mediaPath: a, mediaPaths: [b, path.join(dir, 'gone.png'), a] }, 4)).toEqual([path.resolve(b), path.resolve(a)]);
    expect(postMediaFiles({ mediaPaths: [a, b, a, b, a] }, 4)).toHaveLength(4);
  });

  test('falls back to mediaPath, or nothing', () => {
    expect(postMediaFiles({ mediaPath: a }, 4)).toEqual([path.resolve(a)]);
    expect(postMediaFiles({}, 4)).toEqual([]);
  });
});


describe('fitInstagramCaption', () => {
  const bytes = (t: string) => Buffer.byteLength(t, 'utf8');
  const bullet = (n: number) => `• Σημείο ${n}: ` + 'Πολιτικοί αναλυτές επισημαίνουν ότι τα αθλητικά μέτρα παραμένουν περιορισμένης εμβέλειας. '.repeat(3).trim();
  const link = 'Link in bio / read more at PressHub (https://presshub.cy/distaktika-vimata/)';
  const tags = '#Κυπριακό #Cyprus #CyprusProblem #PressHub #Ειδήσεις';
  const greek = [
    '⚽ Βήματα προσέγγισης μέσω αθλητισμού στο Κυπριακό.',
    '📌 Τα βασικά σημεία:\n\n' + [1, 2, 3, 4, 5].map(bullet).join('\n'),
    'Μπορούν τέτοιες κινήσεις να ανοίξουν τον δρόμο; Πείτε μας στα σχόλια. 👇',
    link,
    tags,
  ].join('\n\n');

  it('returns a caption that fits unchanged', () => {
    const short = 'Σύντομη λεζάντα\n\n' + link + '\n\n' + tags;
    expect(fitInstagramCaption(short)).toBe(short);
  });

  it('keeps the link and hashtags of a long Greek caption and stays under the byte limit', () => {
    expect(bytes(greek)).toBeGreaterThan(INSTAGRAM_REEL_CAPTION_MAX_BYTES);
    expect(greek.length).toBeLessThan(2200); // under Instagram's character limit, over its byte limit
    const fitted = fitInstagramCaption(greek);
    expect(bytes(fitted)).toBeLessThanOrEqual(INSTAGRAM_REEL_CAPTION_MAX_BYTES);
    expect(fitted.startsWith('⚽ Βήματα προσέγγισης')).toBe(true);
    expect(fitted.endsWith(link + '\n\n' + tags)).toBe(true);
    expect(fitted).toContain('• Σημείο 1:');
  });

  it('cuts one very long paragraph at a word and keeps the tail', () => {
    const body = 'Λέξη '.repeat(800).trim();
    const fitted = fitInstagramCaption(body + '\n\n' + link + '\n\n' + tags, 1000);
    expect(bytes(fitted)).toBeLessThanOrEqual(1000);
    expect(fitted).toMatch(/Λέξη…\n\nLink in bio/);
    expect(fitted.endsWith(tags)).toBe(true);
  });

  it('cuts a caption without a link or hashtags within the limit', () => {
    const fitted = fitInstagramCaption('Λέξη '.repeat(800), 500);
    expect(bytes(fitted)).toBeLessThanOrEqual(500);
    expect(fitted.endsWith('…')).toBe(true);
  });
});

describe('waitForTwitterVideo', () => {
  type State = { ariaDisabled: string | null; progress: number; ready?: boolean; counter?: number };
  const fakeScope = (states: State[]) => {
    let i = 0;
    const current = () => states[Math.min(i, states.length - 1)];
    const button = {
      getAttribute: async (name: string) => (name === 'aria-disabled' ? current().ariaDisabled : null),
      count: async () => 1
    };
    const selectors: string[] = [];
    return {
      selectors,
      locator: (sel: string) => {
        selectors.push(sel);
        if (sel === TWITTER_UPLOAD_PROGRESS_SELECTOR) return { count: async () => current().progress };
        if (sel === TWITTER_VIDEO_READY_SELECTOR) return { count: async () => (current().ready ? 1 : 0) };
        // A bare progress bar outside the attachment, like X's character counter ring.
        if (sel === '[role="progressbar"]') return { count: async () => current().counter ?? 0 };
        return { filter: () => ({ filter: () => ({ first: () => button }) }) };
      },
      page: { waitForTimeout: async () => { i++; }, locator: () => ({}) }
    };
  };

  test('waits until Post is enabled and the upload bar is gone', async () => {
    const scope = fakeScope([
      { ariaDisabled: 'true', progress: 1 },
      { ariaDisabled: null, progress: 1 },
      { ariaDisabled: null, progress: 0 }
    ]);
    await expect(waitForTwitterVideo(scope.page, scope, 5000, 1)).resolves.toBeGreaterThanOrEqual(0);
  });

  test('fails when X never finishes the upload', async () => {
    const scope = fakeScope([{ ariaDisabled: 'true', progress: 1 }]);
    await expect(waitForTwitterVideo(scope.page, scope, 30, 1)).rejects.toThrow('X did not finish uploading the video');
  });

  test('only counts progress bars inside the attachment, never the character counter', async () => {
    const scope = fakeScope([{ ariaDisabled: null, progress: 0, counter: 1 }]);
    await expect(waitForTwitterVideo(scope.page, scope, 30, 1)).resolves.toBeGreaterThanOrEqual(0);
    expect(scope.selectors).not.toContain('[role="progressbar"]');
    expect(TWITTER_UPLOAD_PROGRESS_SELECTOR.split(',').every((s) => s.trim().startsWith('[data-testid="attachments"]'))).toBe(true);
  });

  test('X saying "<file>: Ready" ends the wait even while a bar is still shown', async () => {
    const scope = fakeScope([{ ariaDisabled: null, progress: 1, ready: true }]);
    await expect(waitForTwitterVideo(scope.page, scope, 30, 1)).resolves.toBeGreaterThanOrEqual(0);
  });

  test('a ready video still waits for Post to be enabled', async () => {
    const scope = fakeScope([{ ariaDisabled: 'true', progress: 0, ready: true }]);
    await expect(waitForTwitterVideo(scope.page, scope, 30, 1)).rejects.toThrow('X did not finish uploading the video');
  });

  test('the ready selector matches X\'s ready line for a video file', () => {
    const body = TWITTER_VIDEO_READY_SELECTOR.replace(/^text=\//, '').replace(/\/i$/, '');
    const re = new RegExp(body, 'i');
    expect(re.test('b7dcf243bba90d90e94bfab66cf1d766.mp4: Ready')).toBe(true);
    expect(re.test('short.mp4: Uploading (45%)')).toBe(false);
  });
});
