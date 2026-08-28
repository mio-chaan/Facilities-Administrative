<?php
/**
 * templates/ai_widget.php
 * Floating AI Chatbot Assistant - include this ONCE, near the end of
 * your main layout (e.g. templates/footer.php, right before </body>),
 * so it appears on every logged-in page. Requires t8_csrf_field() and
 * page_url() to already be available (true on every page that goes
 * through the normal front controller).
 *
 * Usage: <?php include __DIR__ . '/ai_widget.php'; ?>
 */
?>
<div id="t8AiWidget">
    <button type="button" id="t8AiLauncher" aria-label="Open AI Assistant">
        <i class="fa-solid fa-robot"></i>
    </button>

    <div id="t8AiPanel">
        <div id="t8AiHeader">
            <span><i class="fa-solid fa-robot"></i> RAM YUM Assistant</span>
            <button type="button" id="t8AiClose" aria-label="Close">&times;</button>
        </div>
        <div id="t8AiMessages"></div>
        <form id="t8AiForm">
            <?= t8_csrf_field() ?>
            <input type="text" id="t8AiInput" placeholder="Ask about the system…" autocomplete="off" maxlength="1000">
            <button type="submit" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<style>
#t8AiWidget { position: fixed; bottom: 24px; right: 24px; z-index: 999; font-family: var(--t8-font-body); }
#t8AiLauncher {
    width: 56px; height: 56px; border-radius: 50%; border: none;
    background: var(--t8-primary); color: #fff; font-size: 1.4rem;
    box-shadow: var(--t8-shadow-lift); cursor: pointer;
}
#t8AiLauncher:hover { background: var(--t8-primary-dark); }
#t8AiPanel {
    display: none;
    position: fixed; bottom: 92px; right: 24px; width: 340px; max-width: calc(100vw - 32px);
    height: 460px; max-height: calc(100vh - 140px); background: var(--t8-surface);
    border-radius: var(--t8-radius); box-shadow: var(--t8-shadow-lift);
    flex-direction: column; overflow: hidden; border: 1px solid var(--t8-border);
}
#t8AiHeader {
    background: var(--t8-primary); color: #fff; padding: 12px 16px;
    display: flex; align-items: center; justify-content: space-between; font-weight: 700;
}
#t8AiClose { background: none; border: none; color: #fff; font-size: 1.3rem; cursor: pointer; line-height: 1; }
#t8AiMessages { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; background: var(--t8-bg); }
.t8-ai-msg { max-width: 85%; padding: 8px 12px; border-radius: 12px; font-size: 0.86rem; line-height: 1.4; white-space: pre-wrap; }
.t8-ai-msg-user { align-self: flex-end; background: var(--t8-primary); color: #fff; border-bottom-right-radius: 2px; }
.t8-ai-msg-bot { align-self: flex-start; background: var(--t8-surface); border: 1px solid var(--t8-border); border-bottom-left-radius: 2px; }
.t8-ai-msg-error { align-self: flex-start; background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
#t8AiForm { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--t8-border); background: var(--t8-surface); }
#t8AiInput { flex: 1; border: 1px solid var(--t8-border); border-radius: 999px; padding: 8px 14px; font-size: 0.86rem; outline: none; }
#t8AiForm button[type="submit"] { width: 38px; height: 38px; border-radius: 50%; border: none; background: var(--t8-primary); color: #fff; cursor: pointer; flex-shrink: 0; }
@media (max-width: 480px) {
    #t8AiPanel { right: 16px; width: calc(100vw - 32px); }
    #t8AiWidget { right: 16px; }
}
</style>

<script>
(function () {
    var launcher = document.getElementById('t8AiLauncher');
    var panel = document.getElementById('t8AiPanel');
    var closeBtn = document.getElementById('t8AiClose');
    var form = document.getElementById('t8AiForm');
    var input = document.getElementById('t8AiInput');
    var messages = document.getElementById('t8AiMessages');
    var csrfInput = form.querySelector('input[name="csrf_token"]');

    function addMessage(text, cls) {
        var div = document.createElement('div');
        div.className = 't8-ai-msg ' + cls;
        div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    launcher.addEventListener('click', function () {
        var isOpen = panel.style.display === 'flex';
        panel.style.display = isOpen ? 'none' : 'flex';
        if (!isOpen && messages.children.length === 0) {
            addMessage('Hi! Ask me anything about using RAM YUM — reservations, documents, visitors, and more.', 't8-ai-msg-bot');
        }
        if (!isOpen) { input.focus(); }
    });

    closeBtn.addEventListener('click', function () {
        panel.style.display = 'none';
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) { return; }
        addMessage(text, 't8-ai-msg-user');
        input.value = '';
        input.disabled = true;

        var body = new URLSearchParams();
        body.append('message', text);
        body.append('csrf_token', csrfInput ? csrfInput.value : '');

        fetch('<?= e(page_url('assistant')) ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                addMessage(data.error, 't8-ai-msg-error');
            } else {
                addMessage(data.reply, 't8-ai-msg-bot');
            }
        })
        .catch(function () {
            addMessage('Something went wrong reaching the assistant. Please try again.', 't8-ai-msg-error');
        })
        .finally(function () {
            input.disabled = false;
            input.focus();
        });
    });
})();
</script>
