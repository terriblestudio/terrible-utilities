document.addEventListener('input', function (event) {
    if (event.target.name !== 'terrible_social_title') return;
    const box=event.target.closest('.postbox');
    if (!box) return;
    const image=box.querySelector('.tsi-preview-image');
    if (image && !image.hidden) { image.hidden=true; box.querySelector('.tsi-preview-status').textContent='Title changed. Generate a new preview.'; }
});
document.addEventListener('click', async function (event) {
    const button = event.target.closest('.tsi-preview');
    if (!button) return;
    const box = button.closest('.postbox') || button.parentElement.parentElement;
    const status = box.querySelector('.tsi-preview-status');
    const image = box.querySelector('.tsi-preview-image');
    const custom = box.querySelector('[name="terrible_social_title"]');
    let title = custom ? custom.value.trim() : '';
    if (!title) {
        const editor = (window.wp && window.wp.data) || (window.parent && window.parent.wp && window.parent.wp.data);
        const selected = editor && editor.select('core/editor');
        title = selected && selected.getEditedPostAttribute ? selected.getEditedPostAttribute('title') : '';
        if (title && typeof title === 'object') title = title.raw || title.rendered || '';
        if (!title) title = (document.querySelector('#title') || {}).value || '';
    }
    if (!title.trim()) { status.textContent = 'Enter a post title or card title first.'; return; }
    button.disabled = true; image.hidden = true; status.textContent = 'Generating preview…';
    try {
        const body = new URLSearchParams({action:'terrible_social_images_preview', post:button.dataset.post, nonce:button.dataset.nonce, title:title});
        const response = await fetch(button.dataset.url, {method:'POST', credentials:'same-origin', body});
        const result = await response.json();
        if (!result.success) throw new Error((result.data && result.data.message) || 'Could not generate preview.');
        image.src = result.data.image; image.hidden = false; status.textContent = 'Preview ready. This image is not published.';
    } catch (error) { status.textContent = error.message || 'Could not generate preview.'; }
    finally { button.disabled = false; }
});
