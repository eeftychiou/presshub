/**
 * Trending in Cyprus client: asks WordPress whether a roundup run is waiting,
 * reads the pages it lists, and sends the posts back. WordPress ranks them and
 * writes the roundup post.
 */
import { WorkerLogger } from '../logger';
import { browserTrendingScan, TrendingPlan, TrendingScanFn } from './scanner';

export interface TrendingClientOptions {
  wpUrl: string;
  workerSecret: string;
  fetchFn?: typeof fetch;
  scanFn?: TrendingScanFn;
}

export interface TrendingRunResult {
  ran: boolean;
  sent: number;
  accepted: number;
  errors: number;
}

const IDLE: TrendingRunResult = { ran: false, sent: 0, accepted: 0, errors: 0 };

export class TrendingClient {
  private readonly wpUrl: string;
  private readonly secret: string;
  private readonly fetchFn: typeof fetch;
  private readonly scanFn: TrendingScanFn;
  private running = false;

  constructor(options: TrendingClientOptions) {
    this.wpUrl = options.wpUrl.trim().replace(/\/+$/, '');
    this.secret = options.workerSecret.trim();
    this.fetchFn = options.fetchFn || globalThis.fetch;
    this.scanFn = options.scanFn || browserTrendingScan;
  }

  private headers(extra: Record<string, string> = {}): Record<string, string> {
    return { 'X-PressHub-Secret': this.secret, Authorization: `Bearer ${this.secret}`, ...extra };
  }

  private endpoint(path: string): string {
    return `${this.wpUrl}/wp-json/presshub-social/v1/trending/${path}`;
  }

  public async fetchPlan(force = false): Promise<TrendingPlan | null> {
    const res = await this.fetchFn(this.endpoint('plan') + (force ? '?force=1' : ''), { headers: this.headers() });
    if (!res.ok) {
      // 404: the WordPress plugin is older than this worker.
      if (res.status !== 404) WorkerLogger.warn('Trending', `Plan request failed with HTTP ${res.status}`);
      return null;
    }
    const plan: any = await res.json();
    if (!plan || typeof plan !== 'object' || !Array.isArray(plan.targets)) return null;
    return plan as TrendingPlan;
  }

  /** One cycle. Does nothing unless WordPress has a run waiting for the worker. */
  public async runOnce(force = false): Promise<TrendingRunResult> {
    if (this.running) return IDLE;
    this.running = true;
    try {
      const plan = await this.fetchPlan(force);
      if (!plan || !plan.enabled || !plan.due || !plan.run_id || plan.targets.length === 0) return IDLE;
      WorkerLogger.info('Trending', `Run ${plan.run_id}: reading ${plan.targets.length} page(s)...`);
      const result = await this.scanFn(plan);
      const res = await this.fetchFn(this.endpoint('posts'), {
        method: 'POST',
        headers: this.headers({ 'Content-Type': 'application/json' }),
        body: JSON.stringify({ run_id: plan.run_id, posts: result.posts, errors: result.errors })
      });
      if (!res.ok) {
        WorkerLogger.warn('Trending', `Sending results failed with HTTP ${res.status}`);
        return { ran: true, sent: result.posts.length, accepted: 0, errors: result.errors.length };
      }
      const body: any = await res.json().catch(() => ({}));
      const accepted = Number(body?.accepted || 0);
      WorkerLogger.info('Trending', `Sent ${result.posts.length} post(s); ${accepted} accepted, roundup post #${Number(body?.post_id || 0)}.`);
      return { ran: true, sent: result.posts.length, accepted, errors: result.errors.length };
    } catch (err: any) {
      WorkerLogger.warn('Trending', `Trending cycle failed: ${err.message}`);
      return IDLE;
    } finally {
      this.running = false;
    }
  }
}
