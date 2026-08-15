export async function processDrafts(fetchDrafts: () => Promise<any[]>, updatePost: (id: number, data: any) => Promise<boolean>) {
    const drafts = await fetchDrafts();
    let count = 0;
    for (const draft of drafts) {
        await updatePost(draft.id, { status: 'pending' });
        count++;
    }
    return count;
}
