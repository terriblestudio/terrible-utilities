/* global wp */
// The block editor mounts legacy meta boxes after the page scripts have loaded.
// Delegation also works in the classic editor and on the site-default screen.
document.addEventListener('click', event => {
    const button=event.target.closest('.terrible-image-picker,.terrible-image-remove');
    if(!button)return;
    const control=button.closest('.terrible-image-control');
    function select(id,url) {
        control.querySelector('input[type=hidden]').value=id;
        const image=control.querySelector('img');image.src=url||'';image.hidden=!url;
        control.querySelector('.terrible-image-remove').hidden=!id;
        const preview=control.closest('.terrible-meta-panel')?.querySelector('.tt-social-preview-image');
        if(preview){preview.src=url||control.dataset.fallback||'';preview.hidden=!preview.getAttribute('src');}
    }
    if(button.classList.contains('terrible-image-remove')){select(0,'');return;}
    const frame=wp.media({title:'Choose a sharing image',library:{type:'image'},multiple:false});
    frame.on('select',()=>{const image=frame.state().get('selection').first().toJSON();select(image.id,image.sizes?.medium?.url||image.url);});frame.open();
});
document.addEventListener('input',event=>{
    const key={terrible_meta_title:'title',terrible_meta_description:'description'}[event.target.name];
    const panel=event.target.closest('.terrible-meta-panel');if(!key||!panel)return;
    const fallback=key==='title'?panel.dataset.fallbackTitle:panel.dataset.fallbackDescription;
    panel.querySelector(`.tt-preview-${key}`).textContent=event.target.value||fallback;
});
