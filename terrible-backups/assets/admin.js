/* global TerribleBackups */
(() => {
    'use strict';
    const config = TerribleBackups;
    const $ = id => document.getElementById(id);
    let libraryPage = 0;
    let active = null, running = false, selected = null, jobs = [], cancelRequested = false;
    const terminal = ['done', 'ready', 'failed', 'cancelled', 'recovery'];
    const parts = form => [...form.querySelectorAll('input[name="parts[]"]:checked')].map(input => input.value);
    function notice(message, error = false) {
        const el = $('tb-notice'); el.hidden = false; el.className = error ? 'tb-error' : 'tb-success'; el.textContent = message;
    }
    async function api(op, data = {}, raw = null, query = {}) {
        const url = new URL(config.endpoint);
        url.searchParams.set('action', 'terrible_backups'); url.searchParams.set('op', op);
        Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
        const headers = {'X-Terrible-Backups-Nonce': config.nonce, 'Content-Type': raw ? 'application/octet-stream' : 'application/json'};
        if (active?.token) headers['X-Terrible-Backups-Job'] = active.token;
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers, body: raw || JSON.stringify(data)});
        let result;
        try { result = await response.json(); } catch (_) { throw new Error(`Server returned HTTP ${response.status}. The job is saved; refresh and resume. For uploads, try a smaller chunk size.`); }
        if (!result.success) throw new Error(result.data?.message || 'Request failed. Reload to renew your session.');
        return result.data;
    }
    function progress(job) {
        active = job; $('tb-progress').hidden = false; $('tb-progress-title').textContent = `${job.type === 'restore' ? 'Restoring' : job.type === 'upload' ? 'Uploading' : 'Working'} · ${job.phase}`;
        $('tb-progress-message').textContent = job.error || job.message || 'Working…';
        if (job.size && job.offset !== undefined) { $('tb-meter').max = job.size; $('tb-meter').value = job.offset; }
        else $('tb-meter').removeAttribute('value');
        $('tb-cancel').hidden = ['done', 'ready', 'cancelled'].includes(job.phase);

    }
    async function run(job) {
        if (running) throw new Error('A job is already running in this tab.');
        running = true; cancelRequested = false; progress(job);
        try {
            while (!terminal.includes(active.phase) && active.phase !== 'upload') { if (cancelRequested) { progress(await api('cancel', {id: active.id})); break; } await new Promise(resolve => setTimeout(resolve, 120)); progress(await api('step', {id: active.id})); }
            if (['failed', 'recovery'].includes(active.phase)) throw new Error(active.error || 'Job failed.');
            notice(active.message || 'Job complete.');
            if (active.warnings?.length) notice(`${active.message} ${active.warnings.join(' ')}`);
            if (active.type === 'restore' && active.phase === 'done') { notice('Restore complete. Sign in again using an account from the restored site. Rollback copies remain in private storage until you delete this job.'); return; }
            await refresh();
        } finally { running = false; }
    }
    $('tb-remember')?.addEventListener('submit', event => { event.preventDefault(); guard(async () => { await api('remember', {provider: $('tb-remember-provider').value, label: $('tb-remember-label').value}); window.location.reload(); }); });
    $('tb-browse')?.addEventListener('click', () => guard(async () => {
        const target = $('tb-remote-files'); target.replaceChildren();
        const kind = $('tb-fetch-kind').value;
        async function page(cursor = '') {
            const result = await api('catalog', {kind, cursor});
            if (!result.files.length && !cursor) target.textContent = 'No archives found.';
            result.files.forEach(file => {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = file.key;
                button.addEventListener('click', () => { $('tb-fetch-kind').value = kind; $('tb-fetch-name').value = file.key; }); target.append(button);
            });
            if (result.cursor) { const more = document.createElement('button'); more.type = 'button'; more.className = 'button'; more.textContent = 'Load more'; more.addEventListener('click', () => guard(async () => { more.remove(); await page(result.cursor); })); target.append(more); }
        }
        await page();
    }));
    async function refresh() {
        jobs = await api('list'); const target = $('tb-library'); target.replaceChildren();
        if (!jobs.length) { const p = document.createElement('p'); p.textContent = 'No backups yet. Create your first archive above.'; target.append(p); return; }
        const table = document.createElement('table'); table.className = 'widefat striped';
        const head = document.createElement('thead'), tr = document.createElement('tr');
        ['Created / identifier', 'Type / status', 'Size', 'Actions'].forEach(text => { const th = document.createElement('th'); th.textContent = text; tr.append(th); }); head.append(tr); table.append(head);
        const body = document.createElement('tbody');
        libraryPage=Math.min(libraryPage,Math.max(0,Math.ceil(jobs.length/20)-1));
        jobs.slice(libraryPage*20,(libraryPage+1)*20).forEach(job => {
            const row = document.createElement('tr');
            const date = document.createElement('td'); date.textContent = new Date(job.created * 1000).toLocaleString(); const code = document.createElement('code'); code.textContent = job.id; date.append(document.createElement('br'), code);
            const status = document.createElement('td'); status.textContent = `${job.type} · ${job.phase}`;
            if (job.error) { const p = document.createElement('p'); p.textContent = job.error; p.className = 'tb-error-text'; status.append(p); }
            const size = document.createElement('td'); size.textContent = job.size ? `${(job.size / 1048576).toFixed(1)} MiB` : '—';
            const actions = document.createElement('td'); actions.className = 'tb-actions';
            const button = (label, fn) => { const el = document.createElement('button'); el.className = 'button'; el.type = 'button'; el.textContent = label; el.addEventListener('click', () => guard(fn)); actions.append(el); };
            if (job.downloadable) {
                const link = document.createElement('a'); const url = new URL(config.endpoint); Object.entries({action: 'terrible_backups', op: 'download', id: job.id, _wpnonce: config.nonce}).forEach(([key, val]) => url.searchParams.set(key, val)); link.href = url; link.className = 'button'; link.textContent = 'Download'; actions.append(link);
                button('Inspect / restore', async () => {
                    const manifest = await api('inspect', {id: job.id}); selected = job.id; $('tb-restore').hidden = false; $('tb-confirm').checked = false;
                    $('tb-archive-summary').textContent = `Source: ${manifest.home} · WordPress ${manifest.wordpress} · ${new Date(manifest.created).toLocaleString()} · ${(manifest.expanded_size / 1048576).toFixed(1)} MiB unpacked`;
                    $('tb-restore-form').querySelectorAll('input[name="parts[]"]').forEach(input => { input.disabled = !manifest.parts.includes(input.value); input.checked = !input.disabled; });
                    $('tb-restore').scrollIntoView({behavior: 'smooth', block: 'start'});
                });
                if (job.downloadable && Object.keys(config.providers).length) {
                    const destination = document.createElement('select'); destination.setAttribute('aria-label','Send archive to destination');
                    Object.entries(config.providers).forEach(([id,label]) => destination.add(new Option(label,id)));
                    actions.append(destination);
                    button('Send', async () => { const kind=destination.value; const result=await api('remote',{source:job.id,kind,name:`terrible-backups-${job.id}.zip`}); await run(result); notice(`Sent to ${config.providers[kind]}: ${result.key}`); });
                }
            } else if (!terminal.includes(job.phase) && job.phase !== 'upload') button('Resume', () => run(job));
            else if (job.phase === 'upload') button('Resume upload', async () => { active = job; notice('Reselect the exact same ZIP file and click Upload archive to continue.'); $('tb-file').focus(); });
            if (!['done', 'ready', 'cancelled'].includes(job.phase)) button('Cancel / recover', async () => { active = job; progress(await api('cancel', {id: job.id})); await refresh(); });
            if (terminal.includes(job.phase) && job.phase !== 'recovery') button('Delete', async () => { if (window.confirm('Delete this local archive and any retained rollback data? Remote copies are kept.')) { await api('delete', {id: job.id}); await refresh(); } });
            row.append(date, status, size, actions); body.append(row);
        }); table.append(body); target.append(table);
        if(jobs.length>20){const nav=document.createElement('nav');nav.className='tb-actions';nav.setAttribute('aria-label','Archive pages');const label=document.createElement('span');label.textContent=`Page ${libraryPage+1} of ${Math.ceil(jobs.length/20)}`;nav.append(label);[['Previous',-1],['Next',1]].forEach(([text,delta])=>{const btn=document.createElement('button');btn.type='button';btn.className='button';btn.textContent=text;btn.disabled=delta<0?libraryPage===0:(libraryPage+1)*20>=jobs.length;btn.addEventListener('click',()=>{libraryPage+=delta;guard(refresh);});nav.append(btn);});target.append(nav);}
    }
    async function guard(fn) { try { await fn(); } catch (error) { notice(error.message, true); } }
    function submit(id, fn) { $(id)?.addEventListener('submit', event => { event.preventDefault(); guard(fn); }); }
    submit('tb-backup', async () => { if (running) throw new Error('Wait for the current job.'); await run(await api('backup', {parts: parts($('tb-backup'))})); });
    submit('tb-fetch', async () => { if (running) throw new Error('Wait for the current job.'); await run(await api('remote', {kind: $('tb-fetch-kind').value, name: $('tb-fetch-name').value})); });
    submit('tb-upload', async () => {
        if (running) throw new Error('Wait for the current job.');
        const file = $('tb-file').files[0]; if (!file) return;
        let job = active?.phase === 'upload' ? active : await api('upload', {size: file.size});
        if (job.size !== file.size) throw new Error('Resumed file size does not match. Cancel the old upload to choose another file.');
        running = true; cancelRequested = false; progress(job);
        try {
            const chunk = Math.min(config.chunkSize, Number($('tb-chunk').value));
            // Verify the first saved chunk before continuing a reselected file.
            if (job.offset) await api('chunk', {}, file.slice(0, Math.min(chunk, job.offset)), {id: job.id, offset: 0});
            while (active.phase === 'upload') { if (cancelRequested) { progress(await api('cancel', {id: active.id})); break; } const offset = active.offset; let lastError;
                for (let attempt = 0; attempt < 3; attempt++) { try { job = await api('chunk', {}, file.slice(offset, offset + chunk), {id: active.id, offset}); lastError = null; break; } catch (e) { lastError = e; await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1))); } }
                if (lastError) throw lastError; progress(job);
            }
            notice('Upload complete. Choose Inspect / restore in the library.'); await refresh();
        } finally { running = false; }
    });
    submit('tb-restore-form', async () => { if (running) throw new Error('Wait for the current job.'); await run(await api('restore', {id: selected, parts: parts($('tb-restore-form')), url: $('tb-url').value.trim()})); });
    submit('tb-settings', async () => { const data = {}; if($('tb-settings').dataset.destination)data._destination=$('tb-settings').dataset.destination; $('tb-settings').querySelectorAll('input[name]').forEach(input => { if (input.closest('fieldset')?.hidden) return; const [group, key] = input.name.split('.'); data[group] ||= {}; data[group][key] = input.type === 'checkbox' ? input.checked : input.value; }); await api('settings', data); $('tb-settings').querySelectorAll('input[type=password]').forEach(input => input.value = ''); const savedUrl=new URL(window.location.href);savedUrl.searchParams.set('storage_saved','1');savedUrl.hash='tb-storage';window.location.assign(savedUrl); });
    $('tb-close-restore').addEventListener('click', () => { $('tb-restore').hidden = true; });
    $('tb-refresh').addEventListener('click', () => guard(refresh));
    $('tb-cancel').addEventListener('click', () => guard(async () => { if (running) { cancelRequested = true; notice('Cancelling after the current request finishes…'); return; } if (active) { progress(await api('cancel', {id: active.id})); await refresh(); } }));
    window.addEventListener('beforeunload', event => { if (running) { event.preventDefault(); event.returnValue = ''; } });

    const choice=$('tb-provider-choice');
    function chooseProvider() { $('tb-settings').querySelectorAll('fieldset').forEach(fieldset=> { const group=fieldset.querySelector('input[name]')?.name.split('.')[0]; fieldset.hidden=group!==choice.value; }); }
    choice.addEventListener('change',chooseProvider); chooseProvider();
    if (window.location.hash==='#tb-storage') $('tb-storage').open=true;
    function catalogVisibility() { if ($('tb-browse')) $('tb-browse').hidden=!(config.catalogs||[]).includes($('tb-fetch-kind').value); }
    $('tb-fetch-kind')?.addEventListener('change',catalogVisibility); catalogVisibility();
    document.querySelectorAll('.tb-destination').forEach(form=>form.addEventListener('submit',event=> { event.preventDefault(); guard(async()=> {
        const operation=event.submitter.value;
        if (operation==='remove' && !window.confirm('Remove this destination? Remote archives are kept. In-progress transfers using it will stop.')) return;
        if (operation==='update' && !window.confirm('Replace this destination’s connection with the provider settings saved above? In-progress transfers will stop.')) return;
        await api('destination',{id:form.dataset.id,operation,label:form.elements.label.value}); window.location.hash='tb-storage'; window.location.reload();
    }); }));
    guard(refresh);
})();
