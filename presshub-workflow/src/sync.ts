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
