jQuery(function ($) {
    $('#tsi-bulk').on('click', async function () {
        const button=this, status=$('#tsi-bulk-status'); let cursor=0, count=0, errors=[];
        button.disabled=true; status.text('Starting…');
        try {
            let more;
            do {
                const body=new URLSearchParams({action:'terrible_social_images_bulk',nonce:button.dataset.nonce,cursor:String(cursor)});
                const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',body});
                const result=await response.json(); if (!result.success) throw new Error((result.data && result.data.message)||'Bulk generation failed.');
                cursor=result.data.cursor; count+=result.data.count; errors=errors.concat(result.data.errors); more=result.data.more;
                status.text('Processed '+count+' posts and pages; '+errors.length+' failed.');
            } while (more);
            status.text('Finished: '+count+' processed, '+errors.length+' failed.'+(errors.length?' Open the affected posts for details: '+errors.map(e=>e.id).join(', ')+'.':''));
        } catch (error) { status.text('Stopped after '+count+' items: '+error.message); }
        finally { button.disabled=false; }
    });
    $('.tsi-swatch').on('click', function () { $('#tsi-color').val($(this).data('color')); });
    $('#tsi-pick').on('click', function () {
        const picker = wp.media({title: 'Choose backgrounds', library: {type: 'image'}, multiple: true, button: {text: 'Use backgrounds'}});
        picker.on('open', function () {
            const selection = picker.state().get('selection');
            ($('#tsi-backgrounds').val() || '').split(',').filter(Boolean).forEach(function (id) { selection.add(wp.media.attachment(id)); });
        });
        picker.on('select', function () {
            const ids = picker.state().get('selection').map(function (item) { return item.id; });
            $('#tsi-backgrounds').val(ids.join(',')); $('#tsi-count').text(ids.length + ' selected');
        });
        picker.open();
    });
});
