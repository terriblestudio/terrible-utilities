/* global wp */
document.getElementById('terrible-media-picker')?.addEventListener('click', () => {
    const frame = wp.media({title: 'Choose a file to replace or inspect', multiple: false});
    frame.on('select', () => { const url = new URL(window.location.href); url.searchParams.set('id', frame.state().get('selection').first().id); window.location.assign(url); });
    frame.open();
});
let replacementUrl;
document.getElementById('tt-replacement')?.addEventListener('change', event => {
    const preview=document.getElementById('tt-replacement-preview'); preview.replaceChildren();
    if(replacementUrl) URL.revokeObjectURL(replacementUrl);
    const file=event.target.files[0]; if(!file)return;
    const label=document.createElement('p'); label.textContent=`New file: ${file.name} · ${(file.size/1024).toFixed(1)} KiB`; preview.append(label);
    if(file.type.startsWith('image/')) { replacementUrl=URL.createObjectURL(file);const image=document.createElement('img');image.src=replacementUrl;image.alt='Replacement preview';image.className='tt-media-preview';preview.append(image); }
});
