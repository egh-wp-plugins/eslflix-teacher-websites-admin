document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-copy-secret]').forEach(function (button) {
        button.addEventListener('click', async function () {
            var input = button.closest('.eslflix-twa-secret').querySelector('[data-eslflix-secret]');
            if (!input) return;

            try {
                await navigator.clipboard.writeText(input.value);
                button.textContent = 'Copied';
                window.setTimeout(function () {
                    button.textContent = 'Copy';
                }, 1800);
            } catch (error) {
                input.focus();
                input.select();
            }
        });
    });

    document.querySelectorAll('.eslflix-twa-requires-confirmation').forEach(function (button) {
        button.addEventListener('click', function (event) {
            var message = button.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
