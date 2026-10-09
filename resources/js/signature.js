/**
 * Captura de assinatura em canvas com eventos de caneta/toque (Pointer Events).
 * A imagem é enviada ao servidor, que valida a sessão e a proposta e a incorpora ao PDF final.
 */
function initSignaturePad(canvas) {
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let last = null;
    let strokes = 0;

    const resize = () => {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect = canvas.getBoundingClientRect();
        const snapshot = strokes > 0 ? canvas.toDataURL() : null;
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111';
        if (snapshot) {
            const img = new Image();
            img.onload = () => ctx.drawImage(img, 0, 0, rect.width, rect.height);
            img.src = snapshot;
        }
    };

    const point = (e) => {
        const rect = canvas.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top, p: e.pressure || 0.5 };
    };

    canvas.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        canvas.setPointerCapture(e.pointerId);
        drawing = true;
        last = point(e);
    });
    canvas.addEventListener('pointermove', (e) => {
        if (!drawing) return;
        e.preventDefault();
        const p = point(e);
        ctx.lineWidth = e.pointerType === 'pen' ? 1.2 + p.p * 2.6 : 2.4;
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
        strokes++;
    });
    const end = () => {
        drawing = false;
        last = null;
    };
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
    canvas.addEventListener('pointerleave', end);

    window.addEventListener('resize', resize);
    window.addEventListener('orientationchange', () => setTimeout(resize, 200));
    resize();

    return {
        isEmpty: () => strokes < 5,
        clear: () => {
            const rect = canvas.getBoundingClientRect();
            ctx.clearRect(0, 0, rect.width, rect.height);
            strokes = 0;
        },
        toDataURL: () => canvas.toDataURL('image/png'),
    };
}

document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.querySelector('[data-signature-pad]');
    if (!canvas) return;
    const pad = initSignaturePad(canvas);
    const form = canvas.closest('form');
    const input = form.querySelector('input[name=signature]');
    const error = form.querySelector('[data-signature-error]');

    form.querySelector('[data-signature-clear]')?.addEventListener('click', () => pad.clear());

    form.addEventListener('submit', (e) => {
        if (pad.isEmpty()) {
            e.preventDefault();
            error?.classList.remove('d-none');
            return;
        }
        if (!navigator.onLine) {
            e.preventDefault();
            alert('Sem conexão. A assinatura ainda não foi concluída. Aguarde a conexão e tente novamente.');
            return;
        }
        input.value = pad.toDataURL();
    });
});
