export interface WordPressSyncOptions {
    /** Base URL of the WordPress site (e.g. https://example.com). Trailing slash is trimmed. */
    baseUrl: string;
    /** Bearer token / app password sent as `Authorization: Bearer ***`. */
    authToken: string;
    /** Injected fetch implementation (defaults to global fetch). */
    fetchImpl?: typeof fetch;
}

export interface WordPressDraft {
    id: number;
    title: string;
    status: string;
}

export interface WordPressSyncClient {
    fetchDrafts(): Promise<WordPressDraft[]>;
    updatePost(id: number, data: Record<string, unknown>): Promise<boolean>;
}

/**
 * Factory that returns a real WordPress REST API sync client backed by the
 * WordPress JSON REST API (`/wp-json/wp/v2/...`). Used by `processDrafts`
 * in `src/sync.ts` to drive the editorial draft → "pending" workflow.
 */
export function createWordPressSyncClient(options: WordPressSyncOptions): WordPressSyncClient {
    if (!options || typeof options !== 'object') {
        throw new Error('createWordPressSyncClient: options object is required');
    }
    if (typeof options.baseUrl !== 'string' || options.baseUrl.trim() === '') {
        throw new Error('createWordPressSyncClient: options.baseUrl must be a non-empty string');
    }
    if (typeof options.authToken !== 'string' || options.authToken === '') {
        throw new Error('createWordPressSyncClient: options.authToken must be a non-empty string');
    }

    const baseUrl = options.baseUrl.replace(/\/$/, '');
    const authHeader = `Bearer ${options.authToken}`;
    const fetchImpl: typeof fetch = options.fetchImpl ?? (typeof fetch !== 'undefined' ? fetch : (() => {
        throw new Error('createWordPressSyncClient: no fetch implementation available');
    }) as unknown as typeof fetch);

    const postsEndpoint = `${baseUrl}/wp-json/wp/v2/posts`;

    async function fetchDrafts(): Promise<WordPressDraft[]> {
        const response = await fetchImpl(`${postsEndpoint}?status=draft&per_page=100`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': authHeader,
            },
        });

        if (!response.ok) {
            let detail = '';
            try {
                detail = await response.text();
            } catch {
                // ignore
            }
            throw new Error(
                `createWordPressSyncClient.fetchDrafts: WordPress returned ${response.status} ${response.statusText}${detail ? `: ${detail}` : ''}`
            );
        }

        let body: unknown;
        try {
            body = await response.json();
        } catch (err) {
            throw new Error('createWordPressSyncClient.fetchDrafts: WordPress returned non-JSON body');
        }

        if (!Array.isArray(body)) {
            throw new Error('createWordPressSyncClient.fetchDrafts: WordPress response was not an array of drafts');
        }

        return body.map((item: unknown, index: number): WordPressDraft => {
            if (!item || typeof item !== 'object') {
                throw new Error(`createWordPressSyncClient.fetchDrafts: draft at index ${index} is not an object`);
            }
            const candidate = item as Record<string, unknown>;
            if (typeof candidate.id !== 'number' || !Number.isFinite(candidate.id)) {
                throw new Error(`createWordPressSyncClient.fetchDrafts: draft at index ${index} missing numeric id`);
            }
            const title = candidate.title;
            let titleText: string;
            if (typeof title === 'string') {
                titleText = title;
            } else if (title && typeof title === 'object' && typeof (title as Record<string, unknown>).rendered === 'string') {
                titleText = (title as Record<string, unknown>).rendered as string;
            } else {
                titleText = '';
            }
            const status = typeof candidate.status === 'string' ? candidate.status : '';
            return { id: candidate.id, title: titleText, status };
        });
    }

    async function updatePost(id: number, data: Record<string, unknown>): Promise<boolean> {
        const response = await fetchImpl(`${postsEndpoint}/${id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': authHeader,
            },
            body: JSON.stringify(data),
        });

        if (response.status < 200 || response.status >= 300) {
            let detail = '';
            try {
                detail = await response.text();
            } catch {
                // ignore
            }
            throw new Error(
                `createWordPressSyncClient.updatePost: WordPress returned ${response.status} ${response.statusText}${detail ? `: ${detail}` : ''}`
            );
        }
        return true;
    }

    return { fetchDrafts, updatePost };
}