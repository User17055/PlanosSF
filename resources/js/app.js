import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    // Copiar links de proposta e pagamento.
    document.querySelectorAll('[data-copy]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const target = document.querySelector(btn.dataset.copy);
            const value = (target?.value ?? target?.textContent ?? '').trim();
            try {
                await navigator.clipboard.writeText(value);
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i> Copiado';
                setTimeout(() => (btn.innerHTML = original), 1800);
            } catch {
                target?.select?.();
            }
        });
    });

    document.querySelectorAll('[data-share]').forEach((btn) => {
        if (!navigator.share) {
            btn.classList.add('d-none');
            return;
        }
        btn.addEventListener('click', () => {
            navigator.share({ title: btn.dataset.shareTitle || 'Proposta', url: btn.dataset.share }).catch(() => {});
        });
    });

    // Confirmação para ações irreversíveis.
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) e.preventDefault();
        });
    });

    // Evita duplo envio (o servidor também é idempotente).
    document.querySelectorAll('form[data-once]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (e.defaultPrevented) return;
            form.querySelectorAll('button[type=submit]').forEach((b) => {
                b.disabled = true;
                b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Enviando...';
            });
        });
    });

    // Acompanhamento do pagamento: consulta moderada; o navegador nunca ativa a assinatura.
    const poll = document.querySelector('[data-poll-status]');
    if (poll) {
        let tries = 0;
        const tick = async () => {
            tries++;
            try {
                const r = await fetch(poll.dataset.pollStatus, { headers: { Accept: 'application/json' } });
                const data = await r.json();
                if (data.done) {
                    window.location.reload();
                    return;
                }
                const label = document.querySelector('[data-poll-label]');
                if (label && data.label) label.textContent = data.label;
            } catch {
                /* tenta novamente */
            }
            if (tries < 40) setTimeout(tick, Math.min(15000, 4000 + tries * 1000));
        };
        setTimeout(tick, 4000);
    }

    // Campos dependentes de outra escolha.
    document.querySelectorAll('[data-toggle-for]').forEach((el) => {
        const name = el.dataset.toggleFor;
        const values = el.dataset.toggleValues.split(',');
        const inputs = document.querySelectorAll(`[name="${name}"]`);
        const update = () => {
            const checked = document.querySelector(`[name="${name}"]:checked`);
            const v = checked ? checked.value : inputs[0]?.value;
            el.classList.toggle('d-none', !values.includes(v));
        };
        inputs.forEach((s) => s.addEventListener('change', update));
        update();
    });
});
