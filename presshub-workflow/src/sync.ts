import { createWordPressSyncClient, WordPressSyncClient, WordPressSyncOptions } from './wordpress-sync';

export async function processDrafts(
    fetchDrafts: () => Promise<any[]>,
    updatePost: (id: number, data: any) => Promise<boolean>
) {
    if (typeof fetchDrafts !== 'function') {
        throw new Error('processDrafts: fetchDrafts must be a function');
    }
    if (typeof updatePost !== 'function') {
        throw new Error('processDrafts: updatePost must be a function');
    }

    // Errors from fetchDrafts/updatePost are propagated naturally by `await`.
    const drafts = await fetchDrafts();
    let count = 0;
    for (const draft of drafts) {
        await updatePost(draft.id, { status: 'pending' });
        count++;
    }
    return count;
}

/**
 * Convenience wrapper: wires a `WordPressSyncClient` to `processDrafts`.
 * If `options` is provided, a new client is built via `createWordPressSyncClient`.
 * Otherwise the supplied `client` is used as-is.
 */
export function processDraftsWithClient(
    client: WordPressSyncClient,
    options?: WordPressSyncOptions
) {
    const resolvedClient = options
        ? createWordPressSyncClient({
              baseUrl: options.baseUrl,
              authToken: options.authToken,
              ...(options.fetchImpl ? { fetchImpl: options.fetchImpl } : {}),
          })
        : client;
    return processDrafts(resolvedClient.fetchDrafts, resolvedClient.updatePost);
}
