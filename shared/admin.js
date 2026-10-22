(() => {
    'use strict';
    document.querySelectorAll('.tt-picker').forEach(picker => {
        const input = picker.querySelector('.tt-content-query');
        const status = picker.querySelector('.tt-search-status');
        const results = picker.querySelector('.tt-search-results');
        let timer, generation = 0;
        async function search() {
            const request = ++generation;
            status.textContent = 'Searching…';
            try {
                const url = new URL(picker.dataset.endpoint);
                Object.entries({action:'terrible_content_search',nonce:picker.dataset.nonce,q:input.value}).forEach(([k,v])=>url.searchParams.set(k,v));
                const response = await fetch(url, {credentials:'same-origin'});
                const data = await response.json();
                if (request !== generation) return;
                if (!data.success) throw new Error('Search unavailable. Reload and try again.');
                results.replaceChildren(); status.textContent = data.data.length ? 'Choose a result:' : 'No published content found.';
                data.data.forEach(post => {
                    const button = document.createElement('button'); button.type='button'; button.className='button'; button.textContent=`${post.title} · ${post.type}`;
                    button.addEventListener('click',()=>{
                        picker.querySelector('input[type=hidden]').value=post.id;
                        picker.querySelector('.tt-selection').textContent=`Selected: ${post.title}`;
                        results.replaceChildren(); status.textContent='';
                        picker.closest('form')?.querySelector('button[type=submit]')?.removeAttribute('disabled');
                        if (picker.dataset.editUrl) window.location.assign(picker.dataset.editUrl.replace('CONTENT_ID',post.id));
                    }); results.append(button);
                });
            } catch (error) { if (request===generation) status.textContent=error.message; }
        }
        input.addEventListener('input',()=>{clearTimeout(timer); ++generation; timer=setTimeout(search,200);});
        input.addEventListener('focus',()=>{if(!results.children.length) search();});
    });
    document.querySelectorAll('a[href*="#"]').forEach(link=>link.addEventListener('click',()=>{const url=new URL(link.href);if(url.pathname===location.pathname&&url.search===location.search){const target=document.getElementById(url.hash.slice(1));if(target?.tagName==='DETAILS')target.open=true;}}));
    document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm)) event.preventDefault();}));
})();
