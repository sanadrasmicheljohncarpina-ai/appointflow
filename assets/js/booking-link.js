'use strict';

/* Booking link panel: copy button + QR code rendering/download. Needs qrcode.js loaded first. */
document.addEventListener('DOMContentLoaded', () => {
  const flash = (button, text) => {
    const original = button.dataset.label || button.textContent;
    button.dataset.label = original;
    button.textContent = text;
    window.setTimeout(() => { button.textContent = original; }, 1800);
  };

  // Copy
  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.getAttribute('data-copy-target'));
      if (!input) return;
      input.select();
      const fallback = () => {
        try { document.execCommand('copy'); flash(button, 'Copied!'); }
        catch (e) { flash(button, 'Press Ctrl+C'); }
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(() => flash(button, 'Copied!'), fallback);
      } else {
        fallback();
      }
    });
  });

  // QR code
  document.querySelectorAll('[data-qr-source]').forEach((frame) => {
    const input = document.getElementById(frame.getAttribute('data-qr-source'));
    if (!input || !window.AppointFlowQR) return;

    let svg = '';
    try {
      svg = window.AppointFlowQR.svg(input.value, { margin: 4, level: 'M' });
    } catch (e) {
      frame.textContent = 'Could not generate the QR code for this link.';
      return;
    }
    frame.innerHTML = svg;

    const name = frame.getAttribute('data-qr-name') || 'booking-qr';
    const scope = frame.closest('[data-booking-link]') || document;

    const save = (blob, filename) => {
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    scope.querySelectorAll('[data-qr-download]').forEach((button) => {
      button.addEventListener('click', () => {
        if (button.getAttribute('data-qr-download') === 'svg') {
          save(new Blob([svg], { type: 'image/svg+xml' }), name + '.svg');
          return;
        }
        const modules = parseInt((svg.match(/viewBox="0 0 (\d+)/) || [])[1], 10) || 45;
        const px = modules * Math.max(1, Math.floor(1024 / modules));
        const img = new Image();
        img.onload = () => {
          const canvas = document.createElement('canvas');
          canvas.width = px;
          canvas.height = px;
          const ctx = canvas.getContext('2d');
          ctx.imageSmoothingEnabled = false;
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, px, px);
          ctx.drawImage(img, 0, 0, px, px);
          canvas.toBlob((blob) => { if (blob) save(blob, name + '.png'); }, 'image/png');
        };
        img.onerror = () => flash(button, 'Try SVG instead');
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
      });
    });
  });
});
