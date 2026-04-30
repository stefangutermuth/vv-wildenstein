(function () {
    'use strict';
    if (typeof window.VW_EVENTS === 'undefined') return;

    const cfg = window.VW_EVENTS;

    document.querySelectorAll('form.vw-events-form').forEach(initForm);

    function initForm(form) {
        const msg = form.querySelector('.vw-form-message');
        const fileInput = form.querySelector('input[type="file"][name="image"]');
        const preview = form.querySelector('.vw-image-preview');

        if (fileInput) {
            fileInput.addEventListener('change', () => handleFile(fileInput, preview, form));
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearErrors(form);

            const submitBtn = form.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            try {
                const fd = new FormData(form);

                // Multi-checkbox standort[] is auto-collected by FormData
                // Resize image client-side if too large
                const file = fileInput && fileInput.files && fileInput.files[0];
                if (file) {
                    if (file.size > cfg.max_file_bytes) {
                        const resized = await resizeImage(file, 2400);
                        if (resized && resized.size <= cfg.max_file_bytes) {
                            fd.set('image', resized, file.name.replace(/\.\w+$/, '.jpg'));
                        } else {
                            showError(form, 'image', cfg.i18n.too_big);
                            submitBtn.disabled = false;
                            return;
                        }
                    }
                }

                // Turnstile: das Widget injiziert ein hidden input "cf-turnstile-response"
                // ins Form. FormData picked es automatisch — wir mappen auf "turnstile_token".
                const ts = fd.get('cf-turnstile-response');
                if (ts) {
                    fd.set('turnstile_token', ts);
                } else {
                    showError(form, 'turnstile_token', 'Bot-Schutz noch nicht geladen — bitte kurz warten und erneut absenden.');
                    submitBtn.disabled = false;
                    return;
                }

                const resp = await fetch(cfg.rest_url, { method: 'POST', body: fd });
                const data = await resp.json().catch(() => ({}));

                if (resp.ok && data.ok) {
                    showSuccess(form);
                } else {
                    if (data.errors) {
                        Object.entries(data.errors).forEach(([k, v]) => showError(form, k, v));
                    } else {
                        showFormMessage(form, cfg.i18n.error, 'is-error');
                    }
                }
            } catch (err) {
                showFormMessage(form, cfg.i18n.error, 'is-error');
            } finally {
                submitBtn.disabled = false;
            }
        });
    }

    function clearErrors(form) {
        form.querySelectorAll('.vw-error').forEach(el => el.textContent = '');
        const msg = form.querySelector('.vw-form-message');
        if (msg) { msg.hidden = true; msg.textContent = ''; msg.className = 'vw-form-message'; }
    }

    function showError(form, key, text) {
        const target = form.querySelector(`.vw-error[data-for="${key}"]`);
        if (target) {
            target.textContent = text;
        } else {
            showFormMessage(form, text, 'is-error');
        }
    }

    function showFormMessage(form, text, cls) {
        const msg = form.querySelector('.vw-form-message');
        if (!msg) return;
        msg.textContent = text;
        msg.className = 'vw-form-message ' + cls;
        msg.hidden = false;
    }

    function showSuccess(form) {
        const html = `
        <div class="vw-form-success">
            <h3>${escapeHtml(cfg.i18n.success_title)}</h3>
            <p>${escapeHtml(cfg.i18n.success)}</p>
            <button type="button" class="vw-another">${escapeHtml(cfg.i18n.another)}</button>
        </div>`;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        form.replaceWith(wrapper);
        wrapper.querySelector('.vw-another').addEventListener('click', () => location.reload());
    }

    function handleFile(input, preview, form) {
        if (!preview) return;
        preview.innerHTML = '';
        const file = input.files && input.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.src = url;
        img.alt = '';
        const label = document.createElement('p');
        label.className = 'description';
        label.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        preview.append(img, label);
    }

    async function resizeImage(file, maxSide) {
        try {
            const bmp = await createImageBitmap(file);
            const ratio = Math.min(1, maxSide / Math.max(bmp.width, bmp.height));
            const w = Math.round(bmp.width * ratio);
            const h = Math.round(bmp.height * ratio);
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            canvas.getContext('2d').drawImage(bmp, 0, 0, w, h);
            return await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.85));
        } catch (e) {
            return null;
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
})();
