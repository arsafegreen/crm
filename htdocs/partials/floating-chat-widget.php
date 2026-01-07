<?php
$chatWidgetStandalone = isset($chatWidgetStandalone) ? (bool)$chatWidgetStandalone : false;
$chatWidgetAutoOpen = isset($chatWidgetAutoOpen) ? (bool)$chatWidgetAutoOpen : false;
$chatWidgetStorageKey = isset($chatWidgetStorageKey) ? (string)$chatWidgetStorageKey : 'seloid-floating-lead';
?>
<div class="floating-chat<?= $chatWidgetStandalone ? ' is-standalone' : '' ?>" data-floating-chat data-storage-key="<?= htmlspecialchars($chatWidgetStorageKey, ENT_QUOTES, 'UTF-8'); ?>" data-chat-standalone="<?= $chatWidgetStandalone ? 'true' : 'false'; ?>" data-auto-open="<?= $chatWidgetAutoOpen ? 'true' : 'false'; ?>">
    <button class="floating-trigger" type="button" aria-expanded="false">
        <i class="fas fa-comments" aria-hidden="true"></i>
        <span>Iniciar conversa</span>
    </button>
    <div class="floating-panel" hidden>
        <header>
            <h4>Conecte-se com nossa equipe</h4>
            <button class="floating-close" type="button" aria-label="Fechar chat flutuante">&times;</button>
        </header>
        <div data-floating-intro>
            <p class="small">Preencha os dados e abriremos uma conversa aguardando um especialista.</p>
            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8'); ?>/chat/external-thread" data-floating-chat-form>
                <label>Nome completo
                    <input type="text" name="full_name" required placeholder="Seu nome">
                </label>
                <label>DDD
                    <input type="text" name="ddd" pattern="\d{2}" required placeholder="11">
                </label>
                <label>Telefone / WhatsApp
                    <input type="text" name="phone" pattern="[0-9\-\s]{8,15}" required placeholder="97138-0207">
                </label>
                <label>Mensagem inicial
                    <textarea name="message" required placeholder="Descreva sua necessidade"></textarea>
                </label>
                <input type="hidden" name="source" value="floating-chat">
                <button class="btn btn-gradient" type="submit">Abrir conversa</button>
                <div class="floating-feedback" data-floating-feedback role="status" aria-live="polite"></div>
            </form>
        </div>
        <div class="floating-session" data-floating-session hidden>
            <div class="floating-status" data-floating-status hidden>
                <strong data-floating-status-label></strong>
                <small data-floating-status-agent hidden></small>
            </div>
            <div class="floating-messages" data-floating-messages role="log" aria-live="polite"></div>
            <form class="floating-reply" data-floating-reply>
                <label class="sr-only" for="floating-reply-input">Envie sua mensagem</label>
                <textarea id="floating-reply-input" name="body" rows="2" data-floating-reply-input placeholder="Escreva sua mensagem" required></textarea>
                <button class="btn btn-gradient" type="submit">Enviar</button>
            </form>
        </div>
    </div>
</div>
