import path from 'path';
import fs from 'fs';
import { loadEnv } from '../src/config';
import { postTwitter, postTikTok, PostResult } from '../src/automation/posters';
import { generateSocialCard } from '../src/media/card-generator';
import { generateSocialVideo } from '../src/media/video-generator';

// Load .env
loadEnv();

const wpUrl = (process.env.PRESSHUB_WP_URL || 'https://presshub.cy').replace(/\/$/, '');
const workerSecret = process.env.PRESSHUB_WORKER_SECRET || '';

async function reportJobComplete(jobId: number | string, status: 'published' | 'failed', result: PostResult, error?: string) {
  const completeUrl = `${wpUrl}/wp-json/presshub-social/v1/worker/complete`;
  const payload = {
    job_id: jobId,
    status,
    external_post_id: result.external_id || result.externalPostId || '',
    external_post_url: result.external_url || result.externalPostUrl || '',
    error: error || result.error || ''
  };

  try {
    const res = await fetch(completeUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-PressHub-Secret': workerSecret,
        'Authorization': `Bearer ${workerSecret}`
      },
      body: JSON.stringify(payload)
    });
    console.log(`[Test Live Run] Reported Job #${jobId} complete (${status}) -> HTTP ${res.status}`);
  } catch (err: any) {
    console.warn(`[Test Live Run] Failed to report complete for Job #${jobId}:`, err.message);
  }
}

async function runTwitterTest() {
  console.log('\n========================================');
  console.log('--- TESTING TWITTER JOB (Job #17) ---');
  console.log('========================================');

  const postTitle = 'Μεσημβρινό Podcast Ενημέρωσης - 03/11/2026';
  const postCopy = 'Όσα πρέπει να ακούσετε: https://presshub.cy/mesimvrino-podcast-enimerosis-03-11-2026/ #podcast #podcastandchill #podcastclips #podcastbg';
  const featuredImageUrl = 'https://presshub.cy/wp-content/uploads/2026/09/podcast-Image-1-scaled.jpg';

  // 1. Generate branded card with featured image
  console.log('[Test Twitter] Generating branded 16:9 Twitter card...');
  const card = await generateSocialCard({
    title: postTitle,
    subtitle: 'Όσα πρέπει να ακούσετε στο σημερινό podcast ενημέρωσης του PressHub.Cy',
    category: 'PODCAST BRIEFING',
    siteName: 'PressHub.Cy',
    mediaUrl: featuredImageUrl,
    isPodcast: true,
    width: 1200,
    height: 675,
    outputDir: path.resolve(process.cwd(), 'output')
  });

  console.log(`[Test Twitter] Card generated: ${card.filePath} (${card.width}x${card.height}, ${card.sizeBytes} bytes)`);

  // 2. Post to Twitter with visible browser (headless: false)
  console.log('[Test Twitter] Executing Twitter automation (headless: false)...');
  try {
    const res = await postTwitter({
      platform: 'twitter',
      copy: postCopy,
      mediaPath: card.filePath,
      headless: false,
      timeoutMs: 45000
    });

    console.log('[Test Twitter] SUCCESS! Post Result:', JSON.stringify(res, null, 2));
    await reportJobComplete(17, 'published', res);
    return res;
  } catch (err: any) {
    console.error('[Test Twitter] FAILED:', err.message);
    await reportJobComplete(17, 'failed', { success: false, platform: 'twitter' }, err.message);
    throw err;
  }
}

async function runTikTokTest() {
  console.log('\n========================================');
  console.log('--- TESTING TIKTOK JOB (Job #20) ---');
  console.log('========================================');

  const postTitle = 'Μεσημβρινό Podcast Ενημέρωσης - 03/11/2026';
  const postCopy = 'Όσα έγιναν σήμερα και πρέπει οπωσδήποτε να τα μάθεις; 🎧\n\nΣτο Μεσημβρινό Podcast Ενημέρωσης του 03/11/2026 συγκεντρώνουμε όλες τις τελευταίες εξελίξεις!\n\n#PressHubCy #Podcast #CyprusNews';
  const featuredImageUrl = 'https://presshub.cy/wp-content/uploads/2026/09/podcast-Image-1-scaled.jpg';
  const audioUrl = 'https://presshub.cy/wp-content/uploads/2026/11/podcast-briefing-noon-2026-11-03-6ae9c3bc86dc7.wav';

  // 1. Generate branded vertical 9:16 video with featured image, podcast mic, and audio
  console.log('[Test TikTok] Generating branded 9:16 vertical video...');
  const video = await generateSocialVideo({
    title: postTitle,
    subtitle: 'Όσα έγιναν σήμερα και πρέπει οπωσδήποτε να τα μάθεις',
    category: 'PODCAST BRIEFING',
    siteName: 'PressHub.Cy',
    mediaUrl: featuredImageUrl,
    audioUrl: audioUrl,
    isPodcast: true,
    width: 540,
    height: 960,
    duration: 5,
    outputDir: path.resolve(process.cwd(), 'output')
  });

  console.log(`[Test TikTok] Video generated: ${video.filePath} (${video.width}x${video.height}, ${video.duration}s)`);

  // 2. Post to TikTok with visible browser (headless: false)
  console.log('[Test TikTok] Executing TikTok automation (headless: false)...');
  try {
    const res = await postTikTok({
      platform: 'tiktok',
      copy: postCopy,
      mediaPath: video.filePath,
      headless: false,
      timeoutMs: 60000
    });

    console.log('[Test TikTok] SUCCESS! Post Result:', JSON.stringify(res, null, 2));
    await reportJobComplete(20, 'published', res);
    return res;
  } catch (err: any) {
    console.error('[Test TikTok] FAILED:', err.message);
    await reportJobComplete(20, 'failed', { success: false, platform: 'tiktok' }, err.message);
    throw err;
  }
}

async function main() {
  const target = process.argv[2]?.toLowerCase() || 'both';

  if (target === 'twitter' || target === 'both') {
    try {
      await runTwitterTest();
    } catch (e: any) {
      console.error('Twitter test run failed:', e.message);
    }
  }

  if (target === 'tiktok' || target === 'both') {
    try {
      await runTikTokTest();
    } catch (e: any) {
      console.error('TikTok test run failed:', e.message);
    }
  }
}

main().catch((err) => {
  console.error('Test runner fatal error:', err);
  process.exit(1);
});
